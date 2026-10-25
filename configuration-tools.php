<?php
/** Portable policy configuration. No users, ledger, storage routing or installation state. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Configuration
{
    const MAX_BYTES = 4194304;
    const MAP = 'wp_xp_core_import_map';
    const FIELDS = [
        'id',
        'title',
        'description',
        'icon',
        'type',
        'period',
        'enabled',
        'steps',
        'starts_on',
        'ends_on',
        'prerequisites',
        'reward_xp',
        'image_id',
        'category',
        'series',
    ];
    const CONDITIONS = [
        'type',
        'period',
        'enabled',
        'steps',
        'starts_on',
        'ends_on',
        'prerequisites',
        'reward_xp',
    ];
    private static function permission($nonce)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', wp_xp_core_t('没有管理设置的权限。'), [
                'status' => 403,
            ]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_configuration')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), ['status' => 503]);
        }
        return true;
    }
    public static function export($nonce)
    {
        $permission = self::permission($nonce);
        if (is_wp_error($permission)) {
            return $permission;
        }
        try {
            $rules = WP_XP_Core_Settings::rules();
            unset($rules['retired_milestones']);
            $growth = WP_XP_Core_Growth::settings();
            unset($growth['streak']['generation'], $growth['streak']['start_event_id']);
            $tasks = [];
            foreach (WP_XP_Core_Achievements::definitions() as $task) {
                $tasks[] = WP_XP_Core_Achievements::validate(
                    array_intersect_key($task, array_flip(self::FIELDS)),
                    false,
                );
            }
            return ['schema' => 1, 'rules' => $rules, 'growth' => $growth, 'tasks' => $tasks];
        } catch (Throwable $e) {
            return new WP_Error('input', wp_xp_core_t('配置格式无效。'), ['status' => 400]);
        }
    }
    private static function differences(array $before, array $after, string $path = ''): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            $name = $path === '' ? (string) $key : $path . '.' . $key;
            if ($a === $b) {
                continue;
            }
            if (is_array($a) && is_array($b)) {
                $out = array_merge($out, self::differences($a, $b, $name));
            } else {
                $out[] = ['field' => $name, 'before' => $a, 'after' => $b];
            }
        }
        return $out;
    }
    private static function portable_growth(): array
    {
        $value = WP_XP_Core_Growth::settings();
        unset($value['streak']['generation'], $value['streak']['start_event_id']);
        return $value;
    }
    private static function revisions(): array
    {
        $out = [];
        foreach (
            [
                WP_XP_Core_Settings::OPTION,
                WP_XP_Core_Growth::OPTION,
                WP_XP_Core_Achievements::OPTION,
                self::MAP,
            ]
            as $name
        ) {
            wp_cache_delete($name, 'options');
            $out[$name] = WP_XP_Core_Settings::revision(get_option($name, null));
        }
        return $out;
    }
    private static function signature(string $json, string $mode, array $revision): string
    {
        return hash_hmac(
            'sha256',
            wp_json_encode([
                $json,
                $mode,
                $revision,
                get_current_user_id(),
                Reader_Experience::VERSION,
            ]),
            wp_salt('auth'),
        );
    }
    private static function plan(string $json, string $mode): array
    {
        if (strlen($json) > self::MAX_BYTES || !in_array($mode, ['update', 'template'], true)) {
            throw new InvalidArgumentException(wp_xp_core_t('配置大小或导入方式无效。'));
        }
        $doc = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($doc) ||
            array_diff(array_keys($doc), ['schema', 'rules', 'growth', 'tasks']) ||
            ($doc['schema'] ?? null) !== 1 ||
            !isset($doc['rules'], $doc['growth'], $doc['tasks']) ||
            !is_array($doc['tasks']) ||
            count($doc['tasks']) > 50
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('配置格式无效。'));
        }
        if (array_key_exists('retired_milestones', $doc['rules'])) {
            throw new InvalidArgumentException(wp_xp_core_t('不能导入内部状态。'));
        }
        $previous = WP_XP_Core_Settings::rules();
        $rules = WP_XP_Core_Settings::validate($doc['rules']);
        if ($mode === 'template') {
            foreach (['view', 'like'] as $kind) {
                foreach ($rules['milestones'][$kind] as &$milestone) {
                    $matched = false;
                    foreach ($previous['milestones'][$kind] as $existing) {
                        if ($milestone['threshold'] === $existing['threshold']) {
                            $milestone['id'] = $existing['id'];
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        if (
                            in_array($milestone['id'], $previous['retired_milestones'][$kind], true)
                        ) {
                            throw new InvalidArgumentException(
                                wp_xp_core_t('里程碑标识重复、无效或已经停用。'),
                            );
                        }
                        if (
                            in_array(
                                $milestone['id'],
                                array_column($previous['milestones'][$kind], 'id'),
                                true,
                            )
                        ) {
                            $milestone['id'] =
                                'tpl_' .
                                substr(
                                    hash_hmac(
                                        'sha256',
                                        $kind .
                                            ':' .
                                            $milestone['id'] .
                                            ':' .
                                            $milestone['threshold'],
                                        wp_salt('auth'),
                                    ),
                                    0,
                                    24,
                                );
                        }
                    }
                    if (
                        in_array(
                            $milestone['id'],
                            array_map('strval', WP_XP_Core_Settings::tiers()[$kind]),
                            true,
                        )
                    ) {
                        $rules[$kind . '_' . $milestone['id'] . '_threshold'] =
                            $milestone['threshold'];
                        $rules[$kind . '_' . $milestone['id']] = $milestone['xp'];
                    }
                }
                unset($milestone);
            }
        }
        // Stable milestone identities cannot resurrect retired claims or discard retirement history.
        $rules['retired_milestones'] = $previous['retired_milestones'];
        foreach (['view', 'like'] as $kind) {
            $newids = array_column($rules['milestones'][$kind], 'id');
            foreach ($previous['milestones'][$kind] as $row) {
                if (!in_array($row['id'], $newids, true)) {
                    $rules['retired_milestones'][$kind][] = $row['id'];
                }
            }
        }
        $rules = WP_XP_Core_Settings::validate($rules);
        if ($rules['timezone'] !== $previous['timezone']) {
            global $wpdb;
            $first = $wpdb->get_var('SELECT id FROM ' . Reader_Experience::table() . ' LIMIT 1');
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Timezone evidence unavailable');
            }
            if ($first) {
                throw new InvalidArgumentException(wp_xp_core_t('已有经验记录，不能修改时区。'));
            }
        }
        $growth = WP_XP_Core_Growth::validate($doc['growth']);
        $old = WP_XP_Core_Achievements::definitions();
        $map = get_option(self::MAP, []);
        if (!is_array($map)) {
            throw new RuntimeException('Invalid import identity map');
        }
        $incoming = [];
        $remap = [];
        foreach ($doc['tasks'] as $task) {
            if (!is_array($task)) {
                throw new InvalidArgumentException(wp_xp_core_t('任务字段无效。'));
            }
            $value = WP_XP_Core_Achievements::validate($task, false);
            $id = $value['id'];
            if ($id === '' || isset($incoming[$id])) {
                throw new InvalidArgumentException(wp_xp_core_t('导入任务必须有唯一编号。'));
            }
            $source = $mode . ':' . $id;
            $target =
                $mode === 'update' && isset($old[$id])
                    ? $id
                    : $map[$source] ??
                        't_' . substr(hash_hmac('sha256', $source, wp_salt('auth')), 0, 16);
            if ($target !== $id && isset($old[$target]) && ($map[$source] ?? '') !== $target) {
                throw new InvalidArgumentException(wp_xp_core_t('导入任务编号冲突。'));
            }
            if (
                $value['image_id'] &&
                !WP_XP_Core_Achievements::public_image($value['image_id']) &&
                ($mode === 'template' || ($old[$target]['image_id'] ?? null) !== $value['image_id'])
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('请选择可公开展示的媒体库图片。'));
            }
            $map[$source] = $target;
            $remap[$id] = $target;
            $incoming[$id] = $value;
        }
        $next = $old;
        $changes = [];
        foreach ($incoming as $id => $task) {
            $task['id'] = $remap[$id];
            foreach ($task['prerequisites'] as &$pre) {
                if (isset($remap[$pre['id']])) {
                    $pre['id'] = $remap[$pre['id']];
                } elseif ($mode !== 'update' || !isset($old[$pre['id']])) {
                    throw new InvalidArgumentException(wp_xp_core_t('导入配置缺少前置任务。'));
                }
            }
            unset($pre);
            $task = WP_XP_Core_Achievements::validate($task, false);
            $existing = $old[$task['id']] ?? null;
            $base = $existing
                ? WP_XP_Core_Achievements::validate(
                    array_intersect_key($existing, array_flip(self::FIELDS)),
                    false,
                )
                : null;
            $newversion =
                !$base ||
                array_intersect_key($base, array_flip(self::CONDITIONS)) !==
                    array_intersect_key($task, array_flip(self::CONDITIONS));
            $status = !$base
                ? 'new'
                : ($base === $task
                    ? 'unchanged'
                    : ($newversion
                        ? 'new_version'
                        : 'modified'));
            $task['number'] = $existing['number'] ?? count($next) + 1;
            $task['version'] = $existing ? (int) $existing['version'] + (int) $newversion : 1;
            $task['start_event_id'] = $newversion ? null : $existing['start_event_id'];
            $task['created_at'] = $newversion ? null : $existing['created_at'];
            $next[$task['id']] = $status === 'unchanged' ? $existing : $task;
            $changes[] = [
                'id' => $task['id'],
                'title' => $task['title'],
                'description' => $task['description'],
                'condition_text' => WP_XP_Core_Achievements::condition_text($task),
                'reward_xp' => $task['reward_xp'],
                'status' => $status,
                'version' => $task['version'],
            ];
        }
        if (count($next) > 50 || count($map) > 1000) {
            throw new InvalidArgumentException(wp_xp_core_t('配置超过任务或导入映射上限。'));
        }
        WP_XP_Core_Achievements::validate_graph($next);
        foreach ($old as $id => $task) {
            if (!in_array($id, $remap, true)) {
                $changes[] = [
                    'id' => $id,
                    'title' => $task['title'],
                    'status' => 'preserved',
                    'version' => $task['version'],
                ];
            }
        }
        return [
            'rules' => $rules,
            'growth' => $growth,
            'tasks' => $next,
            'map' => $map,
            'changes' => $changes,
        ];
    }
    public static function preview(string $json, string $mode, $nonce)
    {
        $permission = self::permission($nonce);
        if (is_wp_error($permission)) {
            return $permission;
        }
        try {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $revision = self::revisions();
            $plan = self::plan($json, $mode);
            return [
                'revision' => $revision,
                'proof' => self::signature($json, $mode, $revision),
                'changes' => $plan['changes'],
                'rules_changed' => $plan['rules'] !== WP_XP_Core_Settings::rules(),
                'milestones' => [
                    'before' => WP_XP_Core_Settings::rules()['milestones'],
                    'after' => $plan['rules']['milestones'],
                    'retired' => $plan['rules']['retired_milestones'],
                ],
                'growth_changed' => $plan['growth'] !== self::portable_growth(),
                'differences' => [
                    'rules' => self::differences(WP_XP_Core_Settings::rules(), $plan['rules']),
                    'growth' => self::differences(self::portable_growth(), $plan['growth']),
                ],
            ];
        } catch (Throwable $e) {
            return new WP_Error(
                'input',
                $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : wp_xp_core_t('配置预览失败。'),
                ['status' => 400],
            );
        }
    }
    public static function apply(string $json, string $mode, array $revision, string $proof, $nonce)
    {
        $permission = self::permission($nonce);
        if (is_wp_error($permission)) {
            return $permission;
        }
        if (!hash_equals(self::signature($json, $mode, $revision), $proof)) {
            return new WP_Error('preview', wp_xp_core_t('请先预览本次配置。'), ['status' => 409]);
        }
        global $wpdb;
        $lock = substr('wp_xp_rules:' . hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return new WP_Error('busy', wp_xp_core_t('设置正被修改。'), ['status' => 409]);
        }
        try {
            return Reader_Experience::transaction(function () use (
                $json,
                $mode,
                $revision,
                $nonce,
            ) {
                $permission = self::permission($nonce);
                if (is_wp_error($permission)) {
                    return $permission;
                }
                WP_XP_Core_Management::require_options_transaction();
                WP_XP_Core_Achievements::clear_cache();
                WP_XP_Core_Growth::clear_cache();
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                if (self::revisions() !== $revision) {
                    return new WP_Error('conflict', wp_xp_core_t('配置已更新，请重新预览。'), [
                        'status' => 409,
                    ]);
                }
                $plan = self::plan($json, $mode);
                global $wpdb;
                $boundary = $wpdb->get_var(
                    'SELECT COALESCE(MAX(id),0) FROM ' . Reader_Experience::table(),
                );
                if ($boundary === null || $wpdb->last_error !== '') {
                    throw new RuntimeException('Import boundary unavailable');
                }
                foreach ($plan['tasks'] as &$task) {
                    if ($task['start_event_id'] === null) {
                        $task['start_event_id'] = (int) $boundary;
                        $task['created_at'] = time();
                    }
                }
                unset($task);
                $previous = WP_XP_Core_Growth::settings();
                $s = $plan['growth']['streak'];
                $reset =
                    $s['enabled'] &&
                    (!$previous['streak']['enabled'] ||
                        $s['mode'] !== $previous['streak']['mode'] ||
                        $s['rewards'] !== $previous['streak']['rewards']);
                $plan['growth']['streak']['generation'] =
                    $previous['streak']['generation'] + (int) $reset;
                $plan['growth']['streak']['start_event_id'] = $reset
                    ? (int) $boundary
                    : $previous['streak']['start_event_id'];
                foreach (
                    [
                        WP_XP_Core_Settings::OPTION => 'rules',
                        WP_XP_Core_Growth::OPTION => 'growth',
                        WP_XP_Core_Achievements::OPTION => 'tasks',
                        self::MAP => 'map',
                    ]
                    as $option => $key
                ) {
                    $old = get_option($option, null);
                    if ($old === $plan[$key]) {
                        continue;
                    }
                    Reader_Experience::touch_option($option);
                    if (!update_option($option, $plan[$key], false)) {
                        throw new RuntimeException('Import write failed');
                    }
                    WP_XP_Core_Management::audit('configuration_import', $old, $plan[$key]);
                }
                WP_XP_Core_Achievements::clear_cache();
                WP_XP_Core_Growth::clear_cache();
                return ['applied' => true, 'changes' => $plan['changes']];
            });
        } catch (Throwable $e) {
            WP_XP_Core_Achievements::clear_cache();
            WP_XP_Core_Growth::clear_cache();
            return new WP_Error('storage', wp_xp_core_t('配置未能应用，请重新预览。'), [
                'status' => 503,
            ]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    public static function templates(): array
    {
        return [
            'reading' => [
                'id' => 't_0000000000000001',
                'title' => wp_xp_core_t('阅读起步'),
                'description' => wp_xp_core_t('阅读三篇不同的公开内容。'),
                'icon' => '◇',
                'type' => 'automatic',
                'period' => 'once',
                'enabled' => true,
                'steps' => [['kind' => 'visit', 'count' => 3, 'mode' => 'objects']],
            ],
            'checkin' => [
                'id' => 't_0000000000000002',
                'title' => wp_xp_core_t('每周参与'),
                'description' => wp_xp_core_t('本周在三个不同自然日签到。'),
                'icon' => '☆',
                'type' => 'automatic',
                'period' => 'week',
                'enabled' => true,
                'steps' => [['kind' => 'checkin', 'count' => 3, 'mode' => 'days']],
            ],
        ];
    }
    public static function template_document(string $name): string
    {
        $templates = self::templates();
        if (!isset($templates[$name])) {
            throw new InvalidArgumentException(wp_xp_core_t('未知模板。'));
        }
        $doc = self::export(wp_create_nonce('wp_xp_core_configuration'));
        if (is_wp_error($doc)) {
            throw new RuntimeException('Template permission');
        }
        $doc['tasks'] = [$templates[$name]];
        return wp_json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
