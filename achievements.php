<?php
/** Versioned tasks and optional rewards. Definitions never replay historical activity. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Achievements
{
    const OPTION = 'wp_xp_core_achievements';
    const KINDS = ['checkin', 'visit', 'comment', 'article'];
    public static function definitions(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }
    public static function revision(): string
    {
        return WP_XP_Core_Settings::revision(get_option(self::OPTION, null));
    }
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function integer($value, int $min, int $max): int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
            strlen((string) $value) > 10 ||
            (int) $value < $min ||
            (int) $value > $max
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请填写有效的次数。'));
        }
        return (int) $value;
    }
    private static function text($value, int $max): string
    {
        if (
            !is_string($value) ||
            mb_strlen($value) > $max ||
            preg_match('/[<>\x00-\x1f]/u', $value)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('任务文字无效或过长。'));
        }
        return trim($value);
    }
    public static function validate(array $input, bool $check_media = true): array
    {
        if (
            array_diff(array_keys($input), [
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
            ])
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('任务字段无效。'));
        }
        $id = $input['id'] ?? '';
        if (!is_string($id) || ($id !== '' && !preg_match('/^t_[a-f0-9]{16}$/D', $id))) {
            throw new InvalidArgumentException(wp_xp_core_t('任务编号无效。'));
        }
        $title = self::text($input['title'] ?? '', 60);
        if ($title === '') {
            throw new InvalidArgumentException(wp_xp_core_t('请填写任务名称。'));
        }
        if (
            !in_array($input['type'] ?? '', ['automatic', 'review'], true) ||
            !in_array($input['period'] ?? '', ['once', 'day', 'week', 'month'], true) ||
            !in_array($input['enabled'] ?? false, [true, false, 0, 1, '0', '1'], true)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择任务类型、周期和启用状态。'));
        }
        if (!is_array($input['steps'] ?? null) || count($input['steps']) > 8) {
            throw new InvalidArgumentException(wp_xp_core_t('每个任务最多八个步骤。'));
        }
        $steps = [];
        foreach ($input['steps'] as $step) {
            if (
                !is_array($step) ||
                array_diff(array_keys($step), [
                    'kind',
                    'count',
                    'mode',
                    'post_ids',
                    'category_ids',
                ]) ||
                !in_array($step['kind'] ?? '', self::KINDS, true)
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('请选择有效的任务步骤。'));
            }
            $mode = $step['mode'] ?? 'events';
            if (!in_array($mode, ['events', 'objects', 'days'], true)) {
                throw new InvalidArgumentException(wp_xp_core_t('请选择有效计数方式。'));
            }
            $posts = self::ids($step['post_ids'] ?? []);
            $categories = self::ids($step['category_ids'] ?? []);
            if ($step['kind'] === 'checkin' && ($posts || $categories || $mode === 'objects')) {
                throw new InvalidArgumentException(
                    wp_xp_core_t('签到不支持内容范围或不同内容计数。'),
                );
            }
            $steps[] = [
                'kind' => $step['kind'],
                'count' => self::integer($step['count'] ?? null, 1, 1000),
                'mode' => $mode,
                'post_ids' => $posts,
                'category_ids' => $categories,
            ];
        }
        if ($input['type'] === 'automatic' && !$steps) {
            throw new InvalidArgumentException(wp_xp_core_t('自动任务至少需要一个步骤。'));
        }
        if ($input['type'] === 'review' && $steps) {
            throw new InvalidArgumentException(
                wp_xp_core_t('审核任务通过提交说明完成，无需自动步骤。'),
            );
        }
        $starts = self::date($input['starts_on'] ?? '');
        $ends = self::date($input['ends_on'] ?? '');
        if ($starts && $ends && $starts > $ends) {
            throw new InvalidArgumentException(wp_xp_core_t('结束日期不得早于开始日期。'));
        }
        $prerequisites = $input['prerequisites'] ?? [];
        if (!is_array($prerequisites) || count($prerequisites) > 8) {
            throw new InvalidArgumentException(wp_xp_core_t('最多八个前置任务。'));
        }
        $seen = [];
        foreach ($prerequisites as $pre) {
            if (
                !is_array($pre) ||
                array_diff(array_keys($pre), ['id', 'scope']) ||
                !is_string($pre['id'] ?? null) ||
                !preg_match('/^t_[a-f0-9]{16}$/D', $pre['id']) ||
                !in_array($pre['scope'] ?? '', ['current', 'lifetime'], true) ||
                isset($seen[$pre['id']])
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('前置任务或完成范围无效。'));
            }
            $seen[$pre['id']] = true;
        }
        usort($prerequisites, fn($a, $b) => strcmp($a['id'], $b['id']));
        $image = self::integer($input['image_id'] ?? 0, 0, 2147483647);
        if ($check_media && $image && !self::public_image($image)) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择可公开展示的媒体库图片。'));
        }
        return [
            'reward_xp' => self::integer($input['reward_xp'] ?? 0, 0, 1000000),
            'image_id' => $image,
            'category' => self::text($input['category'] ?? '', 40),
            'series' => self::text($input['series'] ?? '', 60),
            'starts_on' => $starts,
            'ends_on' => $ends,
            'prerequisites' => array_values($prerequisites),
            'id' => $id,
            'title' => $title,
            'description' => self::text($input['description'] ?? '', 300),
            'icon' => self::text($input['icon'] ?? '', 8),
            'type' => $input['type'],
            'period' => $input['period'],
            'enabled' => (bool) $input['enabled'],
            'steps' => $steps,
        ];
    }
    /** Attachment URLs are exposed only while the image and its parent are public. */
    public static function public_image(int $id): bool
    {
        $image = get_post($id);
        if (
            !$image ||
            $image->post_type !== 'attachment' ||
            !in_array($image->post_status, ['inherit', 'publish'], true) ||
            !in_array(
                get_post_mime_type($id),
                ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
                true,
            ) ||
            !wp_attachment_is_image($id) ||
            $image->post_password !== ''
        ) {
            return false;
        }
        if ($image->post_parent) {
            $parent = get_post($image->post_parent);
            if (
                !$parent ||
                !in_array($parent->post_type, ['post', 'page'], true) ||
                $parent->post_status !== 'publish' ||
                $parent->post_password !== ''
            ) {
                return false;
            }
        }
        return true;
    }
    public static function image_url(int $id): string
    {
        return $id && self::public_image($id)
            ? (string) (wp_get_attachment_image_url($id, 'thumbnail') ?: '')
            : '';
    }
    /** Shared validity predicate for personal reads, prerequisites and administration statistics. */
    public static function valid_receipt(string $alias = 'e'): string
    {
        if (!in_array($alias, ['e', 'earned'], true)) {
            throw new InvalidArgumentException('Invalid event alias');
        }
        return 'NOT EXISTS (SELECT 1 FROM ' .
            Reader_Experience::table() .
            " revoked WHERE revoked.kind IN ('task_revoke','task_grant_revoke') AND revoked.object_id=$alias.id)";
    }
    private static function receipt(string $key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT e.* FROM ' .
                    Reader_Experience::table() .
                    ' e WHERE e.event_key=%s AND ' .
                    self::valid_receipt(),
                $key,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Achievement receipt unavailable');
        }
        return $row ?: null;
    }
    public static function condition_text(array $task): string
    {
        $names = [
            'checkin' => wp_xp_core_t('签到'),
            'visit' => wp_xp_core_t('有效阅读'),
            'comment' => wp_xp_core_t('有效评论'),
            'article' => wp_xp_core_t('发表文章'),
        ];
        $modes = [
            'events' => wp_xp_core_t('次活动'),
            'objects' => wp_xp_core_t('项不同内容'),
            'days' => wp_xp_core_t('个自然日'),
        ];
        $parts = [];
        foreach ($task['steps'] as $step) {
            $text =
                ($names[$step['kind']] ?? $step['kind']) .
                ' ' .
                $step['count'] .
                ' ' .
                $modes[$step['mode'] ?? 'events'];
            if (!empty($step['post_ids']) || !empty($step['category_ids'])) {
                $text .= wp_xp_core_t('（指定内容范围）');
            }
            $parts[] = $text;
        }
        if ($task['type'] === 'review') {
            $parts[] = wp_xp_core_t('提交说明并通过审核');
        }
        if (!empty($task['prerequisites'])) {
            $parts[] = wp_xp_core_t('完成全部前置任务');
        }
        if (!empty($task['starts_on'])) {
            $parts[] = wp_xp_core_t('开始：') . $task['starts_on'];
        }
        if (!empty($task['ends_on'])) {
            $parts[] = wp_xp_core_t('结束：') . $task['ends_on'];
        }
        return implode('；', $parts);
    }
    private static function ids($values): array
    {
        if (!is_array($values) || count($values) > 100) {
            throw new InvalidArgumentException(wp_xp_core_t('内容范围最多一百个编号。'));
        }
        $out = array_values(
            array_unique(array_map(fn($v) => self::integer($v, 1, 2147483647), $values)),
        );
        sort($out, SORT_NUMERIC);
        return $out;
    }
    private static function date($value): string
    {
        if (
            !is_string($value) ||
            ($value !== '' &&
                (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) ||
                    !($d = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) ||
                    $d->format('Y-m-d') !== $value))
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('日期须为有效的 YYYY-MM-DD。'));
        }
        return $value;
    }
    public static function validate_graph(array $definitions): void
    {
        $visiting = [];
        $done = [];
        $visit = function ($id) use (&$visit, &$visiting, &$done, $definitions) {
            if (isset($visiting[$id])) {
                throw new InvalidArgumentException(wp_xp_core_t('前置任务不能形成循环。'));
            }
            if (isset($done[$id])) {
                return;
            }
            if (!isset($definitions[$id])) {
                throw new InvalidArgumentException(wp_xp_core_t('前置任务不存在。'));
            }
            $visiting[$id] = true;
            foreach ($definitions[$id]['prerequisites'] ?? [] as $pre) {
                $visit($pre['id']);
            }
            unset($visiting[$id]);
            $done[$id] = true;
        };
        foreach (array_keys($definitions) as $id) {
            $visit($id);
        }
    }
    public static function clear_cache(): void
    {
        foreach ([self::OPTION, 'alloptions', 'notoptions'] as $name) {
            wp_cache_delete($name, 'options');
        }
    }
    public static function save(array $input, $nonce, $revision)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', wp_xp_core_t('没有管理任务的权限。'), 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_achievements')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
        }
        try {
            $task = self::validate($input);
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        }
        try {
            return Reader_Experience::transaction(function () use ($task, $revision) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
                }
                WP_XP_Core_Management::require_options_transaction();
                self::clear_cache();
                $raw = get_option(self::OPTION, null);
                if (
                    !is_string($revision) ||
                    !hash_equals(WP_XP_Core_Settings::revision($raw), $revision)
                ) {
                    return self::error(
                        'conflict',
                        wp_xp_core_t('任务列表已更新，请刷新后再修改。'),
                        409,
                    );
                }
                $old = self::definitions();
                $next = $old;
                $value = $task;
                $existing = $old[$task['id']] ?? null;
                if ($task['id'] !== '' && !$existing) {
                    return self::error('missing', wp_xp_core_t('任务不存在。'), 404);
                }
                if (!$existing && count($old) >= 50) {
                    return self::error(
                        'limit',
                        wp_xp_core_t('最多保留五十个任务，可先停用不再使用的任务。'),
                    );
                }
                if (!$existing) {
                    $value['id'] = 't_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
                }
                $base = $existing
                    ? self::validate(array_intersect_key($existing, $task), false)
                    : null;
                $next[$value['id']] = $value;
                try {
                    self::validate_graph($next);
                } catch (InvalidArgumentException $e) {
                    return self::error('input', $e->getMessage());
                }
                if ($base === $value) {
                    return ['id' => $value['id'], 'unchanged' => true];
                }
                $conditions = [
                    'type',
                    'period',
                    'enabled',
                    'steps',
                    'starts_on',
                    'ends_on',
                    'prerequisites',
                    'reward_xp',
                ];
                $new_version =
                    !$existing ||
                    array_intersect_key($base, array_flip($conditions)) !==
                        array_intersect_key($value, array_flip($conditions));
                $value['number'] = $existing ? (int) $existing['number'] : count($old) + 1;
                $value['version'] = $existing ? (int) $existing['version'] + (int) $new_version : 1;
                global $wpdb;
                $boundary = $new_version
                    ? $wpdb->get_var(
                        'SELECT COALESCE(MAX(id),0) FROM ' . Reader_Experience::table(),
                    )
                    : $existing['start_event_id'];
                if ($wpdb->last_error !== '' || $boundary === null) {
                    throw new RuntimeException('Task boundary unavailable');
                }
                $value['start_event_id'] = (int) $boundary;
                $value['created_at'] = $new_version ? time() : $existing['created_at'];
                $next[$value['id']] = $value;
                Reader_Experience::touch_option(self::OPTION);
                if (!update_option(self::OPTION, $next, false)) {
                    throw new RuntimeException('Task write failed');
                }
                WP_XP_Core_Management::audit('achievement_definition', $raw, $next);
                return ['id' => $value['id'], 'unchanged' => false];
            });
        } catch (Throwable $e) {
            self::clear_cache();
            return self::error('storage', wp_xp_core_t('任务未能保存，请重试。'), 503);
        }
    }
    public static function period(array $task): array
    {
        $today = Reader_Experience::day();
        $date = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        $period = match ($task['period']) {
            'day' => ['key' => $today, 'from' => $today],
            'week' => [
                'key' => $date->format('o-W'),
                'from' => $date->modify('monday this week')->format('Y-m-d'),
            ],
            'month' => ['key' => $date->format('Y-m'), 'from' => $date->format('Y-m-01')],
            default => ['key' => 'once', 'from' => '0001-01-01'],
        };
        $period['from'] = max($period['from'], $task['starts_on'] ?? '');
        $period['to'] = min($today, $task['ends_on'] ?? '' ?: $today);
        return $period;
    }
    private static function key(array $task, int $uid): string
    {
        return 'task:' .
            $task['id'] .
            ':' .
            $task['version'] .
            ':' .
            self::period($task)['key'] .
            ':' .
            $uid;
    }
    private static function event(string $key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                $key,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Task event unavailable');
        }
        return $row ?: null;
    }
    public static function progress(array $task, int $uid): array
    {
        global $wpdb;
        $period = self::period($task);
        $context = self::honor_context($uid);
        $available = self::unlocked($task, $uid, $context);
        $steps = [];
        $complete = $task['type'] === 'automatic' && $available;
        foreach ($task['steps'] as $step) {
            $n = (int) $wpdb->get_var(
                'SELECT ' .
                    self::counter($step, '1=1') .
                    ' FROM ' .
                    Reader_Experience::table() .
                    ' e ' .
                    self::joins() .
                    ' WHERE ' .
                    $wpdb->prepare('e.user_id=%d AND (', $uid) .
                    self::condition($task, $step) .
                    ')',
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Task progress unavailable');
            }
            $done = $n >= $step['count'];
            $complete = $complete && $done;
            $steps[] = $step + ['current' => min($n, $step['count']), 'complete' => $done];
        }
        return [
            'steps' => $steps,
            'complete' => $complete,
            'earned' => isset($context['valid'][self::key($task, $uid)]),
            'revoked' =>
                isset($context['consumed'][self::key($task, $uid)]) &&
                !isset($context['valid'][self::key($task, $uid)]),
            'period' => $period['key'],
            'available' => $available,
        ];
    }
    public static function joins(): string
    {
        global $wpdb;
        return " LEFT JOIN {$wpdb->comments} c ON e.kind='comment' AND c.comment_ID=e.object_id LEFT JOIN {$wpdb->posts} p ON p.ID=IF(e.kind='comment',c.comment_post_ID,e.object_id) ";
    }
    public static function condition(array $task, array $step): string
    {
        global $wpdb;
        $period = self::period($task);
        $sql = $wpdb->prepare(
            'e.kind=%s AND e.id>%d AND e.local_day BETWEEN %s AND %s',
            $step['kind'],
            $task['start_event_id'],
            $period['from'],
            $period['to'],
        );
        if ($step['kind'] !== 'checkin') {
            $sql .=
                " AND p.post_status='publish' AND p.post_password='' AND p.post_type IN ('post','page')";
            if ($step['kind'] === 'comment') {
                $sql .= " AND c.comment_approved='1' AND c.user_id=e.user_id AND c.comment_type IN ('','comment') AND p.post_author<>e.user_id AND NOT EXISTS (SELECT 1 FROM {$wpdb->comments} parent WHERE parent.comment_ID=c.comment_parent AND parent.user_id=e.user_id)";
            }
            $scope = [];
            if (!empty($step['post_ids'])) {
                $scope[] = 'p.ID IN (' . implode(',', array_map('intval', $step['post_ids'])) . ')';
            }
            if (!empty($step['category_ids'])) {
                $scope[] =
                    "(p.post_type='post' AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='category' AND tt.term_id IN (" .
                    implode(',', array_map('intval', $step['category_ids'])) .
                    ')))';
            }
            if ($scope) {
                $sql .= ' AND (' . implode(' OR ', $scope) . ')';
            }
        }
        return $sql;
    }
    private static function counter(array $step, string $condition): string
    {
        $object = match ($step['mode'] ?? 'events') {
            'objects' => 'p.ID',
            'days' => 'e.local_day',
            default => 'e.id',
        };
        return 'COUNT(DISTINCT CASE WHEN (' . $condition . ') THEN ' . $object . ' END)';
    }
    /** Request-local facts only: never retain an eligibility decision after a transaction. */
    private static function honor_context(int $uid): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT e.event_key,(' .
                    self::valid_receipt() .
                    ') AS valid FROM ' .
                    Reader_Experience::table() .
                    " e WHERE e.user_id=%d AND e.kind IN ('task_complete','task_grant')",
                $uid,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Honor facts unavailable');
        }
        $context = ['valid' => [], 'consumed' => [], 'lifetime' => []];
        foreach ($rows as $row) {
            $context['consumed'][$row['event_key']] = true;
            if ((int) $row['valid'] === 1) {
                $context['valid'][$row['event_key']] = true;
                if (preg_match('/^task:(t_[a-f0-9]{16}):/', $row['event_key'], $match)) {
                    $context['lifetime'][$match[1]] = true;
                }
            }
        }
        return $context;
    }
    public static function unlocked(
        array $task,
        int $uid,
        ?array $context = null,
        ?array $definitions = null,
    ): bool {
        $today = Reader_Experience::day();
        if (
            (!empty($task['starts_on']) && $today < $task['starts_on']) ||
            (!empty($task['ends_on']) && $today > $task['ends_on'])
        ) {
            return false;
        }
        if (empty($task['prerequisites'])) {
            return true;
        }
        $context ??= self::honor_context($uid);
        $definitions ??= self::definitions();
        foreach ($task['prerequisites'] as $pre) {
            if ($pre['scope'] === 'current') {
                if (
                    !isset($definitions[$pre['id']]) ||
                    !isset($context['valid'][self::key($definitions[$pre['id']], $uid)])
                ) {
                    return false;
                }
            } elseif (!isset($context['lifetime'][$pre['id']])) {
                return false;
            }
        }
        return true;
    }
    private static function permission($nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', wp_xp_core_t('请先登录。'), 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
        }
        return true;
    }
    private static function active($id): ?array
    {
        if (!is_string($id)) {
            return null;
        }
        $task = self::definitions()[$id] ?? null;
        return $task && !empty($task['enabled']) ? $task : null;
    }
    private static function award(
        array $task,
        int $uid,
        array $evidence = [],
        string $kind = 'task_complete',
    ): bool {
        if (self::event(self::key($task, $uid))) {
            return false;
        }
        $xp = self::integer($task['reward_xp'] ?? 0, 0, 1000000);

        return Reader_Experience::record(
            self::key($task, $uid),
            $uid,
            $kind,
            0,
            $xp,
            Reader_Experience::day(),
            [
                'task_id' => $task['id'],
                'version' => $task['version'],
                'title' => $task['title'],
                'icon' => $task['icon'],
                'image_id' => $task['image_id'] ?? 0,
                'category' => $task['category'] ?? '',
                'series' => $task['series'] ?? '',
                'condition_text' => self::condition_text($task),
                'task_type' => $task['type'],
                'reward_xp' => (int) ($task['reward_xp'] ?? 0),
                'period_type' => $task['period'],
                'version_created_at' => (int) ($task['created_at'] ?? time()),
                'period' => self::period($task)['key'],
            ] + $evidence,
        );
    }
    public static function claim($id, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            return Reader_Experience::transaction(function () use ($id) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
                }
                $task = self::active($id);
                if (!$task || $task['type'] !== 'automatic') {
                    return self::error('missing', wp_xp_core_t('任务当前不可领取。'), 404);
                }
                $uid = get_current_user_id();
                $progress = self::progress($task, $uid);
                if ($progress['earned']) {
                    return ['earned' => true, 'duplicate' => true];
                }
                if (!empty($progress['revoked'])) {
                    return self::error(
                        'revoked',
                        wp_xp_core_t('本期成就已撤销，不能再次领取。'),
                        409,
                    );
                }
                if (!$progress['complete']) {
                    return self::error('incomplete', wp_xp_core_t('任务步骤尚未完成。'), 409);
                }
                self::award($task, $uid);
                return ['earned' => true, 'duplicate' => false];
            });
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法记录任务，请重试。'), 503);
        }
    }
    public static function committed(array $event): void
    {
        if (!in_array($event['kind'] ?? '', self::KINDS, true)) {
            return;
        }
        self::clear_cache();
        $candidates = array_filter(
            self::definitions(),
            fn($task) => !empty($task['enabled']) &&
                $task['type'] === 'automatic' &&
                (int) $event['id'] > $task['start_event_id'] &&
                array_filter($task['steps'], fn($step) => $step['kind'] === $event['kind']),
        );
        if (!$candidates) {
            return;
        }
        $uid = (int) $event['user_id'];
        Reader_Experience::transaction(function () use ($event, $uid) {
            self::clear_cache();
            if (!Reader_Experience::ready()) {
                return;
            }
            $definitions = self::definitions();
            $tasks = array_filter(
                $definitions,
                fn($task) => !empty($task['enabled']) &&
                    $task['type'] === 'automatic' &&
                    (int) $event['id'] > $task['start_event_id'] &&
                    array_filter($task['steps'], fn($step) => $step['kind'] === $event['kind']),
            );
            if (!$tasks) {
                return;
            }
            $context = self::honor_context($uid);
            $progress = self::personal_progress($tasks, $uid, $context, $definitions);
            do {
                $changed = false;
                foreach ($tasks as $task) {
                    $key = self::key($task, $uid);
                    if (
                        isset($context['consumed'][$key]) ||
                        !self::unlocked($task, $uid, $context, $definitions)
                    ) {
                        continue;
                    }
                    $done = !array_filter(
                        $progress[$task['id']]['steps'],
                        fn($step) => !$step['complete'],
                    );
                    if ($done && self::award($task, $uid)) {
                        $context['consumed'][$key] = true;
                        $context['valid'][$key] = true;
                        $context['lifetime'][$task['id']] = true;
                        $changed = true;
                    }
                }
            } while ($changed);
        });
    }
    private static function submission_detail($detail): bool
    {
        return is_array($detail) &&
            is_string($detail['task_id'] ?? null) &&
            preg_match('/^t_[a-f0-9]{16}$/D', $detail['task_id']) &&
            is_int($detail['version'] ?? null) &&
            $detail['version'] > 0 &&
            is_string($detail['period'] ?? null) &&
            is_string($detail['title'] ?? null) &&
            is_string($detail['text'] ?? null);
    }
    private static function submission_condition(): string
    {
        $d = "IF(JSON_VALID(e.detail),e.detail,'{}')";
        return "JSON_TYPE(JSON_EXTRACT($d,'$.task_id'))='STRING' AND JSON_UNQUOTE(JSON_EXTRACT($d,'$.task_id')) REGEXP '^t_[a-f0-9]{16}$' AND JSON_TYPE(JSON_EXTRACT($d,'$.version'))='INTEGER' AND CAST(JSON_UNQUOTE(JSON_EXTRACT($d,'$.version')) AS UNSIGNED)>0 AND JSON_TYPE(JSON_EXTRACT($d,'$.period'))='STRING' AND JSON_TYPE(JSON_EXTRACT($d,'$.title'))='STRING' AND JSON_TYPE(JSON_EXTRACT($d,'$.text'))='STRING'";
    }
    private static function submissions(
        int $uid = 0,
        ?array $task = null,
        bool $pending = false,
        int $cursor = 0,
        int $limit = 100,
    ): array {
        global $wpdb;
        $where = $uid ? $wpdb->prepare(' AND e.user_id=%d', $uid) : '';
        if ($task) {
            $where .= $wpdb->prepare(
                ' AND e.object_id=%d AND e.id>%d AND e.local_day>=%s',
                $task['number'],
                $task['start_event_id'],
                self::period($task)['from'],
            );
        }
        if ($pending) {
            $where .=
                ' AND NOT EXISTS (SELECT 1 FROM ' .
                Reader_Experience::table() .
                " r WHERE r.kind='achievement_review' AND r.object_id=e.id)";
        }
        if ($cursor) {
            $where .= $wpdb->prepare(' AND e.id<%d', $cursor);
        }
        $rows = $wpdb->get_results(
            'SELECT e.id,e.user_id,e.detail,e.created_at,r.detail AS review_detail FROM ' .
                Reader_Experience::table() .
                ' e LEFT JOIN ' .
                Reader_Experience::table() .
                " r ON r.event_key=CONCAT('task-review:',e.id) WHERE e.kind='achievement_submit'" .
                $where .
                ' AND (' .
                self::submission_condition() .
                ')' .
                ' ORDER BY e.id DESC LIMIT ' .
                (int) $limit,
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Submissions unavailable');
        }
        $out = [];
        foreach ($rows as $row) {
            $detail = json_decode($row['detail'], true);
            if (!self::submission_detail($detail)) {
                continue;
            }
            $decision = $row['review_detail'] ? json_decode($row['review_detail'], true) : null;
            if (
                !is_array($decision) ||
                !in_array($decision['decision'] ?? '', ['approve', 'reject'], true)
            ) {
                $decision = null;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'created_at' => (int) $row['created_at'],
                'task_id' => $detail['task_id'],
                'version' => (int) $detail['version'],
                'period' => $detail['period'],
                'title' => $detail['title'],
                'text' => $detail['text'],
                'status' => $decision
                    ? ($decision['decision'] === 'approve'
                        ? 'approved'
                        : 'rejected')
                    : 'pending',
                'feedback' => $decision['feedback'] ?? '',
            ];
        }
        return $out;
    }
    public static function submit(array $input, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            if (
                array_diff(array_keys($input), ['task_id', 'text', 'request_id']) ||
                !is_string($input['request_id'] ?? null) ||
                !preg_match(
                    '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',
                    $input['request_id'],
                )
            ) {
                return self::error('input', wp_xp_core_t('提交信息无效。'));
            }
            $text = self::text($input['text'] ?? '', 1000);
            if (mb_strlen($text) < 5) {
                return self::error('input', wp_xp_core_t('请填写至少五个字的完成说明。'));
            }
            return Reader_Experience::transaction(function () use ($input, $text) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
                }
                $uid = get_current_user_id();
                $key = 'task-submit:' . $uid . ':' . $input['request_id'];
                $fingerprint = hash('sha256', wp_json_encode([$input['task_id'], $text]));
                $existing = self::event($key);
                if ($existing) {
                    $detail = json_decode($existing['detail'], true);
                    return ($detail['fingerprint'] ?? '') === $fingerprint
                        ? ['submitted' => true, 'duplicate' => true]
                        : self::error('conflict', wp_xp_core_t('提交编号已用于另一份说明。'), 409);
                }
                $task = self::active($input['task_id']);
                if (!$task || $task['type'] !== 'review') {
                    return self::error('missing', wp_xp_core_t('审核任务当前不可用。'), 404);
                }
                if (!self::unlocked($task, $uid)) {
                    return self::error(
                        'locked',
                        wp_xp_core_t('任务尚未开放、已结束或前置任务尚未完成。'),
                        409,
                    );
                }
                if (self::event(self::key($task, $uid))) {
                    return self::error('earned', wp_xp_core_t('本期成就已经获得。'), 409);
                }
                $attempts = 0;
                foreach (self::submissions($uid, $task) as $row) {
                    if (
                        $row['task_id'] !== $task['id'] ||
                        $row['version'] !== $task['version'] ||
                        $row['period'] !== self::period($task)['key']
                    ) {
                        continue;
                    }
                    $attempts++;
                    if ($row['status'] === 'pending') {
                        return self::error(
                            'pending',
                            wp_xp_core_t('这项任务已有一份说明等待审核。'),
                            409,
                        );
                    }
                }
                if ($attempts >= 5) {
                    return self::error(
                        'limit',
                        wp_xp_core_t('本期提交次数已达上限，请联系管理员。'),
                        409,
                    );
                }
                Reader_Experience::record(
                    $key,
                    $uid,
                    'achievement_submit',
                    $task['number'],
                    0,
                    Reader_Experience::day(),
                    [
                        'task_id' => $task['id'],
                        'version' => $task['version'],
                        'period' => self::period($task)['key'],
                        'title' => $task['title'],
                        'text' => $text,
                        'fingerprint' => $fingerprint,
                    ],
                );
                return ['submitted' => true, 'duplicate' => false];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('说明未能提交，请重试。'), 503);
        }
    }
    public static function review(array $input, $nonce)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', wp_xp_core_t('没有审核成就的权限。'), 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_achievements')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
        }
        try {
            if (
                array_diff(array_keys($input), ['submission_id', 'decision', 'feedback']) ||
                !in_array($input['decision'] ?? '', ['approve', 'reject'], true)
            ) {
                return self::error('input', wp_xp_core_t('审核内容无效。'));
            }
            $id = self::integer($input['submission_id'] ?? null, 1, 2147483647);
            $feedback = self::text($input['feedback'] ?? '', 300);
            if ($input['decision'] === 'reject' && $feedback === '') {
                return self::error('input', wp_xp_core_t('请填写退回原因。'));
            }
            return Reader_Experience::transaction(function () use ($id, $input, $feedback) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
                }
                global $wpdb;
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM ' .
                            Reader_Experience::table() .
                            " WHERE id=%d AND kind='achievement_submit'",
                        $id,
                    ),
                    ARRAY_A,
                );
                if (!$row) {
                    return self::error('missing', wp_xp_core_t('提交记录不存在。'), 404);
                }
                $uid = (int) $row['user_id'];
                $permission = WP_XP_Core_Management::permission($uid);
                if ($permission !== true) {
                    return $permission;
                }
                if (!get_userdata($uid)) {
                    return self::error('missing', wp_xp_core_t('该用户已经不存在。'), 404);
                }
                $key = 'task-review:' . $id;
                $existing = self::event($key);
                if ($existing) {
                    $d = json_decode($existing['detail'], true);
                    return is_array($d) &&
                        ($d['decision'] ?? null) === $input['decision'] &&
                        ($d['feedback'] ?? null) === $feedback
                        ? ['reviewed' => true, 'duplicate' => true]
                        : self::error(
                            'conflict',
                            wp_xp_core_t('这份说明已被审核，请刷新页面。'),
                            409,
                        );
                }
                $detail = json_decode($row['detail'], true);
                if (!self::submission_detail($detail)) {
                    return self::error('input', wp_xp_core_t('提交记录不完整，未能审核。'));
                }
                $task = self::active($detail['task_id']);
                if ($input['decision'] === 'approve') {
                    if (
                        !$task ||
                        !self::unlocked($task, $uid) ||
                        $task['version'] !== (int) $detail['version'] ||
                        self::period($task)['key'] !== $detail['period']
                    ) {
                        return self::error(
                            'changed',
                            wp_xp_core_t('任务已修改、停用或本期已结束，可退回说明并告知用户。'),
                            409,
                        );
                    }
                    if (
                        self::event(self::key($task, $uid)) &&
                        !self::receipt(self::key($task, $uid))
                    ) {
                        return self::error(
                            'revoked',
                            wp_xp_core_t('本期成就已撤销，不能再次授予。'),
                            409,
                        );
                    }
                    self::award($task, $uid, [
                        'submission_id' => $id,
                        'reviewer' => get_current_user_id(),
                    ]);
                }
                Reader_Experience::record(
                    $key,
                    $uid,
                    'achievement_review',
                    $id,
                    0,
                    Reader_Experience::day(),
                    [
                        'decision' => $input['decision'],
                        'feedback' => $feedback,
                        'actor' => get_current_user_id(),
                        'submission_id' => $id,
                    ],
                );
                return ['reviewed' => true, 'duplicate' => false];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('审核未能保存，请重试。'), 503);
        }
    }
    /** Administrator-only current-cycle grant or append-only revocation. */
    public static function manage(array $input, $nonce)
    {
        $allowed = WP_XP_Core_Management::permission();
        if ($allowed !== true) {
            return $allowed;
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_achievements')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
        }
        try {
            $operation = $input['operation'] ?? '';
            $fields =
                $operation === 'grant'
                    ? [
                        'operation',
                        'request_id',
                        'reason',
                        'user_id',
                        'task_id',
                        'version',
                        'period',
                    ]
                    : ['operation', 'request_id', 'reason', 'event_id'];
            if (
                !in_array($operation, ['grant', 'revoke'], true) ||
                array_diff(array_keys($input), $fields) ||
                !is_string($input['request_id'] ?? null) ||
                !preg_match(
                    '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',
                    $input['request_id'],
                )
            ) {
                return self::error('input', wp_xp_core_t('授予或撤销请求无效。'));
            }
            $reason = self::text($input['reason'] ?? '', 300);
            if ($reason === '') {
                return self::error('input', wp_xp_core_t('请填写操作原因。'));
            }
            $value = ['operation' => $operation, 'reason' => $reason];
            if ($operation === 'grant') {
                $value['user_id'] = self::integer($input['user_id'] ?? null, 1, 2147483647);
                $value['task_id'] = self::text($input['task_id'] ?? '', 32);
                $value['version'] = self::integer($input['version'] ?? null, 1, 2147483647);
                $value['period'] = self::text($input['period'] ?? '', 16);
            } else {
                $value['event_id'] = self::integer($input['event_id'] ?? null, 1, 2147483647);
            }
            $actor = get_current_user_id();
            $request_key = 'task-admin:' . $actor . ':' . strtolower($input['request_id']);
            $fingerprint = hash('sha256', wp_json_encode($value));
            return Reader_Experience::transaction(function () use (
                $value,
                $actor,
                $request_key,
                $fingerprint,
            ) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
                }
                $previous = self::event($request_key);
                if ($previous) {
                    $d = json_decode($previous['detail'], true);
                    $permission = WP_XP_Core_Management::permission((int) $previous['user_id']);
                    if ($permission !== true) {
                        return $permission;
                    }
                    return ($d['fingerprint'] ?? '') === $fingerprint
                        ? ['managed' => true, 'duplicate' => true]
                        : self::error('conflict', wp_xp_core_t('操作编号已用于其他内容。'), 409);
                }
                $event_id = 0;
                $duplicate = false;
                if ($value['operation'] === 'grant') {
                    $uid = $value['user_id'];
                    $permission = WP_XP_Core_Management::permission($uid);
                    if ($permission !== true) {
                        return $permission;
                    }
                    if (!get_userdata($uid)) {
                        return self::error('missing', wp_xp_core_t('用户不存在。'), 404);
                    }
                    $task = self::active($value['task_id']);
                    if (
                        !$task ||
                        (int) $task['version'] !== $value['version'] ||
                        self::period($task)['key'] !== $value['period']
                    ) {
                        return self::error(
                            'changed',
                            wp_xp_core_t('任务版本、周期或启用状态已改变，请刷新页面。'),
                            409,
                        );
                    }
                    if (!self::unlocked($task, $uid)) {
                        return self::error(
                            'locked',
                            wp_xp_core_t('任务日期或前置条件尚未满足。'),
                            409,
                        );
                    }
                    $existing = self::event(self::key($task, $uid));
                    if ($existing && !self::receipt(self::key($task, $uid))) {
                        return self::error(
                            'revoked',
                            wp_xp_core_t('本期成就已撤销，不能重复授予。'),
                            409,
                        );
                    }
                    $duplicate = $existing !== null;
                    if (
                        !$existing &&
                        !self::award(
                            $task,
                            $uid,
                            ['actor' => $actor, 'reason' => $value['reason']],
                            'task_grant',
                        )
                    ) {
                        throw new RuntimeException('Grant failed');
                    }
                    $event_id = (int) self::event(self::key($task, $uid))['id'];
                } else {
                    global $wpdb;
                    $original = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT * FROM ' .
                                Reader_Experience::table() .
                                " WHERE id=%d AND kind IN ('task_complete','task_grant')",
                            $value['event_id'],
                        ),
                        ARRAY_A,
                    );
                    if ($wpdb->last_error !== '') {
                        throw new RuntimeException('Achievement receipt unavailable');
                    }
                    if (!$original) {
                        return self::error('missing', wp_xp_core_t('成就授予记录不存在。'), 404);
                    }
                    $uid = (int) $original['user_id'];
                    $permission = WP_XP_Core_Management::permission($uid);
                    if ($permission !== true) {
                        return $permission;
                    }
                    if (!get_userdata($uid)) {
                        return self::error('missing', wp_xp_core_t('用户不存在。'), 404);
                    }
                    $detail = json_decode($original['detail'], true);
                    if (
                        !is_array($detail) ||
                        !isset($detail['task_id'], $detail['version'], $detail['period']) ||
                        (int) $original['xp'] < 0
                    ) {
                        return self::error('input', wp_xp_core_t('原成就记录无效，未执行撤销。'));
                    }
                    $revoke_key = 'task-revoke:' . $original['id'];
                    $duplicate = self::event($revoke_key) !== null;
                    if (!$duplicate) {
                        wp_cache_delete($uid, 'user_meta');
                        if (
                            (int) get_user_meta($uid, Reader_Experience::balance_meta(), true) <
                            (int) $original['xp']
                        ) {
                            return self::error(
                                'balance',
                                wp_xp_core_t('当前经验不足以扣回原奖励，成就与经验均未改变。'),
                                409,
                            );
                        }
                        if (
                            !Reader_Experience::record(
                                $revoke_key,
                                $uid,
                                $original['kind'] === 'task_grant'
                                    ? 'task_grant_revoke'
                                    : 'task_revoke',
                                (int) $original['id'],
                                -(int) $original['xp'],
                                Reader_Experience::day(),
                                [
                                    'actor' => $actor,
                                    'reason' => $value['reason'],
                                    'original_event_id' => (int) $original['id'],
                                    'title' => is_string($detail['title'] ?? null)
                                        ? $detail['title']
                                        : '',
                                    'task_id' => $detail['task_id'],
                                    'version' => $detail['version'],
                                    'period' => $detail['period'],
                                ],
                            )
                        ) {
                            throw new RuntimeException('Revocation failed');
                        }
                    }
                    $event_id = (int) $original['id'];
                }
                if (
                    !Reader_Experience::record(
                        $request_key,
                        $uid,
                        'achievement_admin',
                        $event_id,
                        0,
                        Reader_Experience::day(),
                        [
                            'actor' => $actor,
                            'reason' => $value['reason'],
                            'operation' => $value['operation'],
                            'fingerprint' => $fingerprint,
                        ],
                    )
                ) {
                    throw new RuntimeException('Admin receipt failed');
                }
                return ['managed' => true, 'duplicate' => $duplicate, 'event_id' => $event_id];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('成就操作未能保存，请重试。'), 503);
        }
    }
    public static function pending(int $cursor = 0, int $limit = 25)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', wp_xp_core_t('没有查看审核记录的权限。'), 403);
        }
        if ($cursor < 0 || $limit < 1 || $limit > 100) {
            return self::error('input', wp_xp_core_t('审核分页无效。'));
        }
        try {
            $rows = self::submissions(0, null, true, $cursor, $limit + 1);
            $more = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            return [
                'items' => $rows,
                'next_cursor' => $more ? $rows[count($rows) - 1]['id'] : null,
            ];
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('审核记录暂不可读。'), 503);
        }
    }
    /** One ledger scan for the whole personal task list, independent of step count. */
    private static function personal_progress(
        array $tasks,
        int $uid,
        ?array $context = null,
        ?array $definitions = null,
    ): array {
        global $wpdb;
        if (!$tasks) {
            return [];
        }
        $context ??= self::honor_context($uid);
        $definitions ??= self::definitions();
        $select = [];
        $aliases = [];
        $keys = [];
        $pending = [];
        $minimum = PHP_INT_MAX;
        foreach ($tasks as $task) {
            $keys[] = self::key($task, $uid);
            $minimum = min($minimum, $task['start_event_id']);
            foreach ($task['steps'] as $i => $step) {
                $alias = 'n' . count($select);
                $condition = self::condition($task, $step);
                $select[] = self::counter($step, $condition) . ' AS ' . $alias;
                $aliases[$task['id']][$i] = $alias;
            }
            if ($task['type'] === 'review') {
                $pending[] = $wpdb->prepare(
                    '(e.object_id=%d AND e.id>%d AND e.local_day>=%s)',
                    $task['number'],
                    $task['start_event_id'],
                    self::period($task)['from'],
                );
            }
        }
        $counts = [];
        if ($select) {
            $counts = $wpdb->get_row(
                'SELECT ' .
                    implode(',', $select) .
                    ' FROM ' .
                    Reader_Experience::table() .
                    ' e ' .
                    self::joins() .
                    ' WHERE ' .
                    $wpdb->prepare('e.user_id=%d AND e.id>%d', $uid, $minimum),
                ARRAY_A,
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Task counters unavailable');
            }
        }
        $pending_numbers = [];
        if ($pending) {
            $pending_numbers = $wpdb->get_col(
                'SELECT e.object_id FROM ' .
                    Reader_Experience::table() .
                    ' e WHERE ' .
                    $wpdb->prepare("e.user_id=%d AND e.kind='achievement_submit'", $uid) .
                    ' AND (' .
                    self::submission_condition() .
                    ') AND (' .
                    implode(' OR ', $pending) .
                    ') AND NOT EXISTS (SELECT 1 FROM ' .
                    Reader_Experience::table() .
                    " r WHERE r.event_key=CONCAT('task-review:',e.id))",
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Pending tasks unavailable');
            }
        }
        $out = [];
        foreach ($tasks as $task) {
            $steps = [];
            $available = self::unlocked($task, $uid, $context, $definitions);
            $complete = $task['type'] === 'automatic' && $available;
            foreach ($task['steps'] as $i => $step) {
                $n = (int) ($counts[$aliases[$task['id']][$i]] ?? 0);
                $done = $n >= $step['count'];
                $complete = $complete && $done;
                $steps[] = $step + ['current' => min($n, $step['count']), 'complete' => $done];
            }
            $out[$task['id']] = [
                'steps' => $steps,
                'complete' => $complete,
                'earned' => isset($context['valid'][self::key($task, $uid)]),
                'revoked' =>
                    isset($context['consumed'][self::key($task, $uid)]) &&
                    !isset($context['valid'][self::key($task, $uid)]),
                'period' => self::period($task)['key'],
                'available' => $available,
                'pending_submission' => in_array(
                    (string) $task['number'],
                    array_map('strval', $pending_numbers),
                    true,
                ),
            ];
        }
        return $out;
    }
    private static function scope_links(array $step, array $posts, array $terms): array
    {
        $links = [];
        foreach ($step['post_ids'] ?? [] as $id) {
            $post = $posts[$id] ?? null;
            if (
                $post &&
                in_array($post->post_type, ['post', 'page'], true) &&
                $post->post_status === 'publish' &&
                $post->post_password === '' &&
                current_user_can('read_post', (int) $id)
            ) {
                $links[] = ['title' => get_the_title($post), 'url' => get_permalink($post)];
                if (count($links) >= 8) {
                    break;
                }
            }
        }
        foreach (array_slice($step['category_ids'] ?? [], 0, 8) as $id) {
            $term = $terms[$id] ?? null;
            $url = $term ? get_category_link($id) : null;
            if ($term && !is_wp_error($term) && !is_wp_error($url)) {
                $links[] = ['title' => $term->name . wp_xp_core_t('（分类）'), 'url' => $url];
            }
        }
        return $links;
    }
    private static function prerequisite_hints(
        array $task,
        int $uid,
        array $context,
        array $definitions,
    ): array {
        $hints = [];
        foreach ($task['prerequisites'] ?? [] as $pre) {
            $definition = $definitions[$pre['id']] ?? null;
            $probe = $task;
            $probe['prerequisites'] = [$pre];
            $probe['starts_on'] = '';
            $probe['ends_on'] = '';
            $hints[] = [
                'title' =>
                    $definition && !empty($definition['enabled'])
                        ? $definition['title']
                        : wp_xp_core_t('前置任务当前未开放'),
                'scope' => $pre['scope'],
                'complete' => self::unlocked($probe, $uid, $context, $definitions),
            ];
        }
        return $hints;
    }
    public static function personal(): array
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return ['tasks' => [], 'badges' => [], 'submissions' => []];
        }
        $uid = get_current_user_id();
        $tasks = [];
        $definitions = array_filter(self::definitions(), fn($task) => !empty($task['enabled']));
        $context = self::honor_context($uid);
        $progress = self::personal_progress($definitions, $uid, $context, $definitions);
        $post_ids = [];
        $term_ids = [];
        foreach ($definitions as $task) {
            foreach ($task['steps'] as $step) {
                $post_ids = array_merge($post_ids, $step['post_ids'] ?? []);
                $term_ids = array_merge($term_ids, $step['category_ids'] ?? []);
            }
        }
        $posts = $post_ids
            ? array_column(
                get_posts([
                    'post__in' => array_values(array_unique($post_ids)),
                    'post_type' => ['post', 'page'],
                    'post_status' => 'publish',
                    'has_password' => false,
                    'numberposts' => -1,
                    'update_post_term_cache' => true,
                ]),
                null,
                'ID',
            )
            : [];
        $terms = $term_ids
            ? get_terms([
                'taxonomy' => 'category',
                'include' => array_values(array_unique($term_ids)),
                'hide_empty' => false,
            ])
            : [];
        $terms = is_wp_error($terms) ? [] : array_column($terms, null, 'term_id');
        foreach ($definitions as $task) {
            if (!$task['enabled']) {
                continue;
            }
            foreach ($progress[$task['id']]['steps'] as &$step) {
                $step['scope_links'] = self::scope_links($step, $posts, $terms);
                $step['scoped'] = !empty($step['post_ids']) || !empty($step['category_ids']);
                unset($step['post_ids'], $step['category_ids']);
            }
            unset($step);
            $tasks[] =
                [
                    'id' => $task['id'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'icon' => $task['icon'],
                    'period' => $task['period'],
                    'period_key' => self::period($task)['key'],
                    'version' => $task['version'],
                    'type' => $task['type'],
                    'starts_on' => $task['starts_on'] ?? '',
                    'ends_on' => $task['ends_on'] ?? '',
                    'reward_xp' => (int) ($task['reward_xp'] ?? 0),
                    'category' => $task['category'] ?? '',
                    'series' => $task['series'] ?? '',
                    'image_url' => self::image_url((int) ($task['image_id'] ?? 0)),
                    'condition_text' => self::condition_text($task),
                    'prerequisites' => self::prerequisite_hints(
                        $task,
                        $uid,
                        $context,
                        $definitions,
                    ),
                ] + $progress[$task['id']];
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT e.id,e.detail,e.local_day,e.created_at,e.xp,e.kind FROM ' .
                    Reader_Experience::table() .
                    " e WHERE e.user_id=%d AND e.kind IN ('task_complete','task_grant') AND " .
                    self::valid_receipt() .
                    ' ORDER BY e.id DESC LIMIT 50',
                $uid,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Achievements unavailable');
        }
        $badges = [];
        foreach ($rows as $row) {
            $d = json_decode($row['detail'], true);
            if (is_array($d) && is_string($d['title'] ?? null)) {
                $badges[] = [
                    'title' => $d['title'],
                    'icon' => is_string($d['icon'] ?? null) ? $d['icon'] : '',
                    'day' => $row['local_day'],
                    'earned_at' => (int) $row['created_at'],
                    'image_url' => self::image_url((int) ($d['image_id'] ?? 0)),
                    'category' => $d['category'] ?? '',
                    'series' => $d['series'] ?? '',
                    'condition_text' => $d['condition_text'] ?? '',
                    'reward_xp' => (int) $row['xp'],
                    'method' => $row['kind'] === 'task_grant' ? 'manual' : $d['task_type'] ?? '',
                ];
            }
        }
        return [
            'timezone' => WP_XP_Core_Settings::rules()['timezone'],
            'tasks' => $tasks,
            'badges' => $badges,
            'submissions' => self::submissions($uid),
            'pending' => self::submissions($uid, null, true),
        ];
    }
}
add_action('wp_xp_core_event_committed', [WP_XP_Core_Achievements::class, 'committed'], 20);
