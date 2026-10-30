<?php
/** Validated global policy. Settings never rewrite existing experience events. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Settings
{
    const OPTION = 'wp_xp_core_rules';
    const PANEL_OPTION = 'wp_xp_core_panel_page_id';
    private static ?array $snapshot = null;
    public static function begin(): void
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        self::$snapshot = self::rules();
    }
    public static function end(): void
    {
        self::$snapshot = null;
    }
    private static function flat_defaults(): array
    {
        return [
            'checkin_xp' => 2,
            'visit_xp' => 1,
            'article_xp' => 20,
            'comment_xp' => 2,
            'visit_limit' => 3,
            'comment_limit' => 3,
            'view_100' => 5,
            'view_500' => 10,
            'view_1000' => 20,
            'like_10' => 5,
            'like_30' => 10,
            'like_100' => 20,
            'view_100_threshold' => 100,
            'view_500_threshold' => 500,
            'view_1000_threshold' => 1000,
            'like_10_threshold' => 10,
            'like_30_threshold' => 30,
            'like_100_threshold' => 100,
            'levels' => [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000],
        ];
    }
    public static function defaults(): array
    {
        return self::flat_defaults() + [
            'activities' => array_fill_keys(
                ['checkin', 'visit', 'article', 'comment', 'view', 'like'],
                true,
            ),
            'activity_caps' => ['day' => 0, 'week' => 0],
            'providers' => [],
            'dwell_seconds' => 15,
            'timezone' => 'Asia/Shanghai',
            'scope' => ['post_ids' => [], 'category_ids' => []],
            'milestones' => self::legacy_milestones(self::flat_defaults()),
            'retired_milestones' => ['view' => [], 'like' => []],
        ];
    }
    private static function legacy_milestones(array $flat): array
    {
        $out = [];
        foreach (self::tiers() as $kind => $slots) {
            $out[$kind] = [];
            foreach ($slots as $id) {
                $out[$kind][] = [
                    'id' => (string) $id,
                    'threshold' => $flat[$kind . '_' . $id . '_threshold'],
                    'xp' => $flat[$kind . '_' . $id],
                ];
            }
        }
        return $out;
    }
    private static function number($value, int $min, int $max): int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
            strlen((string) $value) > 16 ||
            (int) $value < $min ||
            (int) $value > $max
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请填写范围内的整数。'));
        }
        return (int) $value;
    }
    private static function ids($value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : preg_split('/[\s,，]+/u', trim($value));
        }
        if (!is_array($value) || count($value) > 200) {
            throw new InvalidArgumentException(wp_xp_core_t('每组适用范围最多填写 200 个 ID。'));
        }
        $ids = array_values(
            array_unique(array_map(fn($id) => self::number($id, 1, PHP_INT_MAX), $value)),
        );
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
    public static function validate($input): array
    {
        $defaults = self::defaults();
        $flat = self::flat_defaults();
        $legacy = array_filter(
            $flat,
            fn($key) => !str_ends_with($key, '_threshold'),
            ARRAY_FILTER_USE_KEY,
        );
        if (
            !is_array($input) ||
            array_diff(array_keys($input), array_keys($defaults)) ||
            array_diff(array_keys($legacy), array_keys($input))
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('设置字段不完整或包含未知字段。'));
        }
        $thresholds = array_diff_key($flat, $legacy);
        $provided = array_intersect_key($input, $thresholds);
        if ($provided && count($provided) !== count($thresholds)) {
            throw new InvalidArgumentException(wp_xp_core_t('里程碑次数设置不完整。'));
        }
        $input += $thresholds;
        $out = [];
        foreach ($flat as $key => $default) {
            if ($key !== 'levels') {
                $threshold = str_ends_with($key, '_threshold');
                $out[$key] = self::number(
                    $input[$key],
                    $threshold ? 1 : 0,
                    $threshold ? 1000000000 : (str_ends_with($key, '_limit') ? 1000 : 1000000),
                );
            }
        }
        // Retained flat fields are the compatibility values for the six original stable IDs.
        // Dynamic form saves synchronize these fields before validation.
        $levels = $input['levels'];
        if (is_string($levels)) {
            $levels = preg_split('/[\s,，]+/u', trim($levels));
        }
        if (
            !is_array($levels) ||
            ($levels && array_keys($levels) !== range(0, count($levels) - 1)) ||
            count($levels) < 1 ||
            count($levels) > 100
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请填写 1–100 个等级门槛。'));
        }
        $previous = -1;
        foreach ($levels as $i => $level) {
            $level = self::number($level, 0, 1000000000);
            if ($level <= $previous || ($i === 0 && $level !== 0)) {
                throw new InvalidArgumentException(
                    wp_xp_core_t('等级门槛必须从 0 开始、严格递增，且不超过 1000000000。'),
                );
            }
            $levels[$i] = $previous = $level;
        }
        $out['levels'] = $levels;
        $activities = $input['activities'] ?? $defaults['activities'];
        if (
            !is_array($activities) ||
            array_diff(array_keys($activities), array_keys($defaults['activities'])) ||
            array_diff(array_keys($defaults['activities']), array_keys($activities))
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('活动开关不完整。'));
        }
        foreach ($activities as $key => $value) {
            if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException(wp_xp_core_t('活动开关无效。'));
            }
            $activities[$key] = (bool) $value;
        }
        $out['activities'] = array_replace($defaults['activities'], $activities);
        $caps = $input['activity_caps'] ?? $defaults['activity_caps'];
        if (
            !is_array($caps) ||
            array_diff(array_keys($caps), ['day', 'week']) ||
            count($caps) !== 2
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('活动经验上限无效。'));
        }
        $out['activity_caps'] = [
            'day' => self::number($caps['day'], 0, 1000000000),
            'week' => self::number($caps['week'], 0, 1000000000),
        ];
        $providers = $input['providers'] ?? [];
        if (!is_array($providers) || count($providers) > 20) {
            throw new InvalidArgumentException(wp_xp_core_t('活动提供者设置无效。'));
        }
        $out['providers'] = [];
        foreach ($providers as $id => $provider) {
            if (
                !is_string($id) ||
                !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
                !is_array($provider) ||
                array_diff(array_keys($provider), [
                    'enabled',
                    'xp',
                    'day_limit',
                    'min_score',
                    'inbox',
                    'template',
                    'channels',
                ]) ||
                !in_array($provider['enabled'] ?? null, [true, false, 0, 1, '0', '1'], true)
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('活动提供者设置无效。'));
            }
            $out['providers'][$id] = [
                'enabled' => (bool) $provider['enabled'],
                'xp' => self::number($provider['xp'] ?? null, 0, 1000000),
            ];
            if (array_key_exists('day_limit', $provider)) {
                $out['providers'][$id]['day_limit'] =
                    $provider['day_limit'] === null
                        ? null
                        : self::number($provider['day_limit'], 0, 100000);
            }
            if (array_key_exists('min_score', $provider)) {
                $out['providers'][$id]['min_score'] = self::number(
                    $provider['min_score'],
                    0,
                    1000000000,
                );
            }
            if (array_key_exists('inbox', $provider)) {
                if (!in_array($provider['inbox'], [true, false, 0, 1, '0', '1'], true)) {
                    throw new InvalidArgumentException(wp_xp_core_t('活动通知设置无效。'));
                }
                $out['providers'][$id]['inbox'] = (bool) $provider['inbox'];
            }
            if (array_key_exists('template', $provider)) {
                if (
                    !is_string($provider['template']) ||
                    trim($provider['template']) === '' ||
                    mb_strlen($provider['template']) > 200 ||
                    preg_match('/[<>\x00-\x1f]/u', $provider['template']) ||
                    preg_match(
                        '/[{}]/',
                        str_replace(['{activity}', '{xp}', '{score}'], '', $provider['template']),
                    )
                ) {
                    throw new InvalidArgumentException(
                        wp_xp_core_t('通知文案只支持活动、经验和分数占位符。'),
                    );
                }
                $out['providers'][$id]['template'] = $provider['template'];
            }
            if (array_key_exists('channels', $provider)) {
                if (!is_array($provider['channels']) || count($provider['channels']) > 10) {
                    throw new InvalidArgumentException(wp_xp_core_t('活动通知设置无效。'));
                }
                foreach ($provider['channels'] as $channel) {
                    if (
                        !is_string($channel) ||
                        !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $channel)
                    ) {
                        throw new InvalidArgumentException(wp_xp_core_t('活动通知设置无效。'));
                    }
                }
                $out['providers'][$id]['channels'] = array_values(
                    array_unique($provider['channels']),
                );
            }
        }
        $out['dwell_seconds'] = self::number($input['dwell_seconds'] ?? 15, 5, 3600);
        $timezone = $input['timezone'] ?? 'Asia/Shanghai';
        if (
            !is_string($timezone) ||
            !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择有效时区。'));
        }
        $out['timezone'] = $timezone;
        $scope = $input['scope'] ?? $defaults['scope'];
        if (
            !is_array($scope) ||
            array_diff(array_keys($scope), ['post_ids', 'category_ids']) ||
            !array_key_exists('post_ids', $scope) ||
            !array_key_exists('category_ids', $scope)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('适用内容设置无效。'));
        }
        $out['scope'] = [
            'post_ids' => self::ids($scope['post_ids']),
            'category_ids' => self::ids($scope['category_ids']),
        ];
        $retired = $input['retired_milestones'] ?? $defaults['retired_milestones'];
        $milestones = $input['milestones'] ?? self::legacy_milestones($out);
        if (
            !is_array($milestones) ||
            !is_array($retired) ||
            array_diff(array_keys($milestones), ['view', 'like']) ||
            array_diff(array_keys($retired), ['view', 'like'])
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('里程碑设置无效。'));
        }
        $out['milestones'] = [];
        $out['retired_milestones'] = [];
        foreach (['view', 'like'] as $kind) {
            if (
                !isset($milestones[$kind], $retired[$kind]) ||
                !is_array($milestones[$kind]) ||
                !is_array($retired[$kind]) ||
                count($milestones[$kind]) > 20
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('每组最多设置 20 档里程碑。'));
            }
            $retired_ids = array_values(array_unique($retired[$kind]));
            foreach ($retired_ids as $id) {
                if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,48}$/D', $id)) {
                    throw new InvalidArgumentException(wp_xp_core_t('停用里程碑标识无效。'));
                }
            }
            $out['retired_milestones'][$kind] = $retired_ids;
            $out['milestones'][$kind] = [];
            $previous = 0;
            $seen = [];
            foreach (array_values($milestones[$kind]) as $row) {
                if (
                    !is_array($row) ||
                    array_diff(array_keys($row), ['id', 'threshold', 'xp']) ||
                    !isset($row['id'], $row['threshold'], $row['xp']) ||
                    !is_string($row['id']) ||
                    !preg_match('/^[a-z0-9_]{1,48}$/D', $row['id']) ||
                    isset($seen[$row['id']]) ||
                    in_array($row['id'], $retired_ids, true)
                ) {
                    throw new InvalidArgumentException(
                        wp_xp_core_t('里程碑标识重复、无效或已经停用。'),
                    );
                }
                $id = $row['id'];
                $seen[$id] = true;
                $old = in_array($id, array_map('strval', self::tiers()[$kind]), true);
                $threshold = $old
                    ? $out[$kind . '_' . $id . '_threshold']
                    : self::number($row['threshold'], 1, 1000000000);
                $xp = $old ? $out[$kind . '_' . $id] : self::number($row['xp'], 0, 1000000);
                if ($threshold <= $previous) {
                    throw new InvalidArgumentException(
                        wp_xp_core_t('每组里程碑次数必须严格递增。'),
                    );
                }
                $previous = $threshold;
                $out['milestones'][$kind][] = ['id' => $id, 'threshold' => $threshold, 'xp' => $xp];
            }
        }
        return $out;
    }
    public static function enabled(string $kind): bool
    {
        return self::rules()['activities'][$kind] ?? false;
    }
    public static function in_scope(int $id): bool
    {
        $scope = self::rules()['scope'];
        if (!$scope['post_ids'] && !$scope['category_ids']) {
            return true;
        }
        if (in_array($id, $scope['post_ids'], true)) {
            return true;
        }
        $post = $id > 0 ? get_post($id) : null;
        return $post &&
            $post->post_type === 'post' &&
            $scope['category_ids'] &&
            has_category($scope['category_ids'], $id);
    }
    public static function policy_revision(): string
    {
        return self::revision(self::rules());
    }
    public static function rules(): array
    {
        if (self::$snapshot !== null) {
            return self::$snapshot;
        }
        try {
            return self::validate(get_option(self::OPTION, self::defaults()));
        } catch (InvalidArgumentException $e) {
            return self::defaults();
        }
    }
    public static function revision($raw): string
    {
        return hash('sha256', serialize($raw));
    }
    public static function save($input, $nonce, $revision)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', wp_xp_core_t('没有管理设置的权限。'), [
                'status' => 403,
            ]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_settings')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新设置页。'), [
                'status' => 403,
            ]);
        }
        global $wpdb;
        $lock = 'wp_xp_rules:' . hash('sha256', DB_NAME . ':' . $wpdb->prefix);
        // Serialize administrators, then compare against the exact value shown by their form.
        $lock = substr($lock, 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return new WP_Error('busy', wp_xp_core_t('设置正被其他管理员修改，请重试。'), [
                'status' => 409,
            ]);
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $old = get_option(self::OPTION, null);
            if (!is_string($revision) || !hash_equals(self::revision($old), $revision)) {
                return new WP_Error(
                    'conflict',
                    wp_xp_core_t('设置已被其他管理员更新，请刷新后重新修改。'),
                    [
                        'status' => 409,
                    ],
                );
            }
            try {
                $saved = self::validate($old);
            } catch (InvalidArgumentException $e) {
                $saved = self::defaults();
            }
            if (is_array($input)) {
                // Older clients submit only the original fields. Keep newer settings intact.
                foreach (
                    array_diff_key(self::defaults(), self::flat_defaults())
                    as $key => $default
                ) {
                    if (!array_key_exists($key, $input)) {
                        $input[$key] = $saved[$key];
                    }
                }
                if (!self::likes_ready()) {
                    foreach (self::tiers()['like'] as $slot) {
                        foreach (['like_' . $slot, 'like_' . $slot . '_threshold'] as $key) {
                            $input[$key] = $saved[$key];
                        }
                    }
                    $input['activities']['like'] = $saved['activities']['like'];
                    $input['milestones']['like'] = $saved['milestones']['like'];
                }
                foreach (['view', 'like'] as $kind) {
                    if (($input['milestones'][$kind] ?? null) === '') {
                        $input['milestones'][$kind] = [];
                    }
                }
                if (isset($input['providers']) && is_array($input['providers'])) {
                    $input['providers'] = array_replace($saved['providers'], $input['providers']);
                }
                // Retirement is server-owned and survives old forms and forged submissions.
                $input['retired_milestones'] = $saved['retired_milestones'];
                foreach (['view', 'like'] as $kind) {
                    if (
                        !isset($input['milestones'][$kind]) ||
                        !is_array($input['milestones'][$kind])
                    ) {
                        continue;
                    }
                    $old_rows = array_column($saved['milestones'][$kind], null, 'id');
                    $ids = [];
                    foreach ($input['milestones'][$kind] as $row) {
                        if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) {
                            continue;
                        }
                        $id = $row['id'];
                        $ids[] = $id;
                        if (
                            in_array($id, array_map('strval', self::tiers()[$kind]), true) &&
                            (!isset($old_rows[$id]) || $row != $old_rows[$id])
                        ) {
                            $input[$kind . '_' . $id] = $row['xp'] ?? null;
                            $input[$kind . '_' . $id . '_threshold'] = $row['threshold'] ?? null;
                        }
                    }
                    foreach ($saved['milestones'][$kind] as $row) {
                        if (!in_array($row['id'], $ids, true)) {
                            $input['retired_milestones'][$kind][] = $row['id'];
                        }
                    }
                }
            }
            try {
                $rules = self::validate($input);
            } catch (InvalidArgumentException $e) {
                return new WP_Error('input', $e->getMessage(), ['status' => 400]);
            }
            // Even an unchanged form may initialize a missing server-owned activity boundary.

            // Store the new policy and its audit entry together on transactional options storage.
            return Reader_Experience::transaction(function () use ($old, $rules, $saved) {
                if ($rules['timezone'] !== $saved['timezone']) {
                    global $wpdb;
                    $first = $wpdb->get_var(
                        'SELECT id FROM ' . Reader_Experience::table() . ' LIMIT 1',
                    );
                    if ($wpdb->last_error !== '') {
                        throw new RuntimeException('Timezone evidence unavailable');
                    }
                    if ($first) {
                        return new WP_Error(
                            'timezone_locked',
                            wp_xp_core_t('已有经验记录，不能修改时区，以免重复领取每日奖励。'),
                            ['status' => 409],
                        );
                    }
                }
                WP_XP_Core_Management::require_options_transaction();
                if (self::revision(get_option(self::OPTION, null)) !== self::revision($old)) {
                    return new WP_Error(
                        'conflict',
                        wp_xp_core_t('设置已被其他管理员更新，请刷新后重新修改。'),
                        ['status' => 409],
                    );
                }
                WP_XP_Core_Providers::update_boundaries($saved, $rules);
                if ($old === $rules) {
                    return true;
                }
                Reader_Experience::touch_option(self::OPTION);
                if (!update_option(self::OPTION, $rules, false)) {
                    throw new RuntimeException('Experience settings write failed');
                }
                WP_XP_Core_Management::audit_rules($old, $rules);
                return true;
            });
        } catch (Throwable $e) {
            WP_XP_Core_Management::clear_option_cache();
            return new WP_Error('storage', wp_xp_core_t('设置未能保存，请重试。'), [
                'status' => 503,
            ]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    public static function panel_revision($raw): string
    {
        // WordPress keeps integer values in the write cache but reloads DB scalars as strings.
        $value = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : $raw;
        return self::revision($value);
    }
    public static function save_panel_page($input, $nonce, $revision)
    {
        if (!current_user_can('manage_options') || !reader_experience_native()) {
            return new WP_Error('forbidden', wp_xp_core_t('没有管理经验面板的权限。'), [
                'status' => 403,
            ]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_panel')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新设置页。'), [
                'status' => 403,
            ]);
        }
        if (
            (!is_int($input) && !is_string($input)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $input) ||
            strlen((string) $input) > 10 ||
            (int) $input > 2147483647
        ) {
            return new WP_Error('input', wp_xp_core_t('请选择有效的已发布页面。'), [
                'status' => 400,
            ]);
        }
        $id = (int) $input;
        $page = $id ? get_post($id) : null;
        if (
            $id &&
            (!$page ||
                $page->post_type !== 'page' ||
                $page->post_status !== 'publish' ||
                $page->post_password !== '')
        ) {
            return new WP_Error('input', wp_xp_core_t('请选择没有密码保护的已发布页面。'), [
                'status' => 400,
            ]);
        }
        global $wpdb;
        $lock = substr('wp_xp_panel:' . hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return new WP_Error('busy', wp_xp_core_t('面板设置正被修改，请重试。'), [
                'status' => 409,
            ]);
        }
        try {
            wp_cache_delete(self::PANEL_OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $old = get_option(self::PANEL_OPTION, null);
            if (!is_string($revision) || !hash_equals(self::panel_revision($old), $revision)) {
                return new WP_Error('conflict', wp_xp_core_t('面板设置已更新，请刷新页面。'), [
                    'status' => 409,
                ]);
            }
            if ((is_int($old) || is_string($old)) && (string) $old === (string) $id) {
                return true;
            }
            return update_option(self::PANEL_OPTION, $id, false)
                ? true
                : new WP_Error('storage', wp_xp_core_t('面板设置未能保存，请重试。'), [
                    'status' => 503,
                ]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    private static function panel_help(): void
    {
        echo '<section class="xp-card xp-panel-help"><h2>' .
            esc_html(wp_xp_core_t('添加经验面板')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('经验面板放在普通 WordPress 页面中，无需已有账户插件。')) .
            '</p><ol><li>' .
            esc_html(wp_xp_core_t('进入 ')) .
            '<strong>' .
            esc_html(wp_xp_core_t('页面 → 新建页面')) .
            '</strong>' .
            esc_html(wp_xp_core_t('，标题可填“我的经验”。')) .
            '</li><li>' .
            esc_html(wp_xp_core_t('添加 ')) .
            '<strong>' .
            esc_html(wp_xp_core_t('短代码')) .
            '</strong>' .
            esc_html(wp_xp_core_t(' 区块，填入 ')) .
            '<code>[reader_experience]</code>。</li><li>' .
            esc_html(wp_xp_core_t('发布页面，登录后即可查看经验与签到。')) .
            '</li></ol>';
        if (reader_experience_native()) {
            $raw = get_option(self::PANEL_OPTION, null);
            echo '<form method="post"><input type="hidden" name="wp_xp_core_action" value="panel">';
            wp_nonce_field('wp_xp_core_panel');
            echo '<input type="hidden" name="panel_revision" value="' .
                esc_attr(self::panel_revision($raw)) .
                '"><label class="xp-label" for="xp-panel-page">' .
                esc_html(wp_xp_core_t('选择已发布的经验页面')) .
                '</label>';
            wp_dropdown_pages([
                'name' => 'panel_page_id',
                'id' => 'xp-panel-page',
                'selected' => (int) $raw,
                'show_option_none' => wp_xp_core_t('暂不指定'),
                'option_none_value' => 0,
                'post_status' => 'publish',
            ]);
            echo '<p class="xp-help">' .
                esc_html(
                    wp_xp_core_t(
                        '选择后，顶栏“经验签到”和登录返回链接会指向此页。请先按上方步骤加入短代码；保存不会创建或发布页面。',
                    ),
                ) .
                '</p>';
            submit_button(wp_xp_core_t('保存面板页面'), 'secondary', 'save_panel', false);
            echo '</form>';
        } else {
            echo '<p class="xp-help">' .
                esc_html(wp_xp_core_t('本站使用高级接入配置；面板页与短代码名称由现有配置提供。')) .
                '</p>';
        }
        echo '</section>';
    }
    public static function tiers(): array
    {
        return ['view' => [100, 500, 1000], 'like' => [10, 30, 100]];
    }
    public static function likes_ready(): bool
    {
        return function_exists('pagenest_companion_like') &&
            function_exists('pagenest_companion_feature') &&
            (bool) pagenest_companion_feature('likes');
    }
    public static function menu(): void
    {
        add_options_page('WP XP Core', 'WP XP Core', 'manage_options', 'wp-xp-core', [
            self::class,
            'page',
        ]);
    }
    public static function assets($hook): void
    {
        if ($hook !== 'settings_page_wp-xp-core') {
            return;
        }
        $manifest = json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true);
        wp_enqueue_style(
            'wp-xp-core-admin',
            plugins_url('assets/' . $manifest['admin_css'], __FILE__),
            [],
            Reader_Experience::VERSION,
        );
        wp_enqueue_script(
            'wp-xp-core-admin',
            plugins_url('assets/' . $manifest['admin_js'], __FILE__),
            [],
            Reader_Experience::VERSION,
            true,
        );
        if (class_exists('WP_XP_Core_I18n')) {
            WP_XP_Core_I18n::script('wp-xp-core-admin');
        }
    }
    private static function field(string $key, string $label, array $rules): void
    {
        $limit = str_ends_with($key, '_limit');
        echo '<div class="xp-field"><label for="xp-' .
            esc_attr($key) .
            '">' .
            esc_html($label) .
            '</label><div class="xp-input-unit"><input type="number" min="0" max="' .
            ($limit ? '1000' : '1000000') .
            '" step="1" required id="xp-' .
            esc_attr($key) .
            '" name="rules[' .
            esc_attr($key) .
            ']" value="' .
            esc_attr((string) $rules[$key]) .
            '"><span>' .
            ($limit ? wp_xp_core_t('次 / 日') : wp_xp_core_t('经验')) .
            '</span></div></div>';
    }
    private static function activity(string $kind, string $label, array $rules): void
    {
        echo '<label class="xp-activity-toggle"><input type="hidden" name="rules[activities][' .
            esc_attr($kind) .
            ']" value="0"><input type="checkbox" name="rules[activities][' .
            esc_attr($kind) .
            ']" value="1"' .
            ($rules['activities'][$kind] ? ' checked' : '') .
            '> ' .
            esc_html($label) .
            '</label>';
    }
    private static function milestone_row(string $kind, array $row, int $index): void
    {
        $prefix = 'rules[milestones][' . $kind . '][' . $index . ']';
        $id = $kind . '_' . $row['id'];
        echo '<div class="xp-milestone-row"><input type="hidden" data-field="id" name="' .
            esc_attr($prefix . '[id]') .
            '" value="' .
            esc_attr($row['id']) .
            '"><span class="xp-tier">第 ' .
            ($index + 1) .
            ' 档</span><div class="xp-field"><label data-for="threshold" for="xp-' .
            esc_attr($id) .
            '-threshold">' .
            esc_html(wp_xp_core_t('所需次数')) .
            '</label><div class="xp-input-unit"><input type="number" data-field="threshold" id="xp-' .
            esc_attr($id) .
            '-threshold" name="' .
            esc_attr($prefix . '[threshold]') .
            '" value="' .
            (int) $row['threshold'] .
            '" min="1" max="1000000000" step="1" required><span>' .
            esc_html(wp_xp_core_t('次')) .
            '</span></div></div><div class="xp-field"><label data-for="xp" for="xp-' .
            esc_attr($id) .
            '">' .
            esc_html(wp_xp_core_t('奖励经验')) .
            '</label><div class="xp-input-unit"><input type="number" data-field="xp" id="xp-' .
            esc_attr($id) .
            '" name="' .
            esc_attr($prefix . '[xp]') .
            '" value="' .
            (int) $row['xp'] .
            '" min="0" max="1000000" step="1" required><span>' .
            esc_html(wp_xp_core_t('经验')) .
            '</span></div></div><button type="button" class="button xp-milestone-remove" aria-label="移除第 ' .
            ($index + 1) .
            ' 档">' .
            esc_html(wp_xp_core_t('移除')) .
            '</button></div>';
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有管理设置的权限。'));
        }
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result =
                ($_POST['wp_xp_core_action'] ?? '') === 'panel'
                    ? self::save_panel_page(
                        wp_unslash($_POST['panel_page_id'] ?? ''),
                        $_POST['_wpnonce'] ?? '',
                        $_POST['panel_revision'] ?? '',
                    )
                    : self::save(
                        wp_unslash($_POST['rules'] ?? []),
                        $_POST['_wpnonce'] ?? '',
                        $_POST['revision'] ?? '',
                    );
        }
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>WP XP Core <span class="xp-version">' .
            esc_html(Reader_Experience::VERSION) .
            '</span></h1><p>' .
            esc_html(wp_xp_core_t('经验规则 · 让每一次参与都有积累')) .
            '</p></div></header>';
        if ($result !== null) {
            echo '<div class="notice ' .
                (is_wp_error($result) ? 'notice-error' : 'notice-success') .
                '"><p>' .
                esc_html(
                    is_wp_error($result)
                        ? $result->get_error_message()
                        : wp_xp_core_t('设置已保存。'),
                ) .
                '</p></div>';
        }
        if (class_exists('WP_XP_Core_Management_UI')) {
            WP_XP_Core_Management_UI::nav('rules');
        }
        $raw = get_option(self::OPTION, null);
        if ($raw !== null) {
            try {
                self::validate($raw);
            } catch (InvalidArgumentException $e) {
                echo '<div class="notice notice-warning"><p>' .
                    esc_html(
                        wp_xp_core_t('已保存的设置无效，当前使用默认规则；请检查并重新保存。'),
                    ) .
                    '</p></div>';
            }
        }
        $rules = self::rules();
        echo '<div class="xp-layout"><form method="post" class="xp-form">';
        wp_nonce_field('wp_xp_core_settings');
        echo '<input type="hidden" name="revision" value="' . esc_attr(self::revision($raw)) . '">';
        echo '<section class="xp-card" aria-labelledby="xp-activity-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">01</span><div><h2 id="xp-activity-title">' .
            esc_html(wp_xp_core_t('日常活动')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('设置每次参与的奖励，以及每日可获得奖励的次数。')) .
            '</p></div></div><div class="xp-fields">';
        foreach (
            [
                'checkin' => wp_xp_core_t('每日签到'),
                'article' => wp_xp_core_t('首次发表文章'),
                'visit' => wp_xp_core_t('有效阅读'),
                'comment' => wp_xp_core_t('有效评论'),
            ]
            as $kind => $label
        ) {
            echo '<div class="xp-activity-group">';
            self::activity($kind, wp_xp_core_t('启用') . $label, $rules);
            self::field($kind . '_xp', $label . wp_xp_core_t('奖励'), $rules);
            if (in_array($kind, ['visit', 'comment'], true)) {
                self::field($kind . '_limit', wp_xp_core_t('每日奖励次数'), $rules);
            }
            echo '</div>';
        }
        echo '<p><a href="' .
            esc_url(admin_url('options-general.php?page=wp-xp-activities')) .
            '">' .
            esc_html(wp_xp_core_t('配置可选活动与通知')) .
            '</a></p>';
        echo '</div><div class="xp-field"><label>' .
            esc_html(wp_xp_core_t('每日活动经验上限')) .
            '<input type="number" min="0" max="1000000000" name="rules[activity_caps][day]" value="' .
            $rules['activity_caps']['day'] .
            '"></label><label>' .
            esc_html(wp_xp_core_t('每周活动经验上限')) .
            '<input type="number" min="0" max="1000000000" name="rules[activity_caps][week]" value="' .
            $rules['activity_caps']['week'] .
            '"></label><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '0 表示不限。仅累计首次活动实际发放的正向经验；扣回不恢复额度，剩余额度不足时只发剩余部分，不补发差额。人工与原金额补偿不受限。',
                ),
            ) .
            '</p></div><div class="xp-field"><label for="xp-dwell">' .
            esc_html(wp_xp_core_t('有效阅读停留时间')) .
            '</label><div class="xp-input-unit"><input type="number" id="xp-dwell" name="rules[dwell_seconds]" min="5" max="3600" required value="' .
            $rules['dwell_seconds'] .
            '"><span>' .
            esc_html(wp_xp_core_t('秒')) .
            '</span></div></div><p class="xp-footnote">' .
            esc_html(
                wp_xp_core_t(
                    '签到每日奖励一次；阅读须满足停留时间。关闭活动或将次数设为 0 后停止发放新奖励，已发评论的撤销与恢复继续按原金额处理。',
                ),
            ) .
            '</p><details class="xp-scope"><summary>' .
            esc_html(wp_xp_core_t('适用内容与每日统计时区')) .
            '</summary><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '以下范围用于阅读、文章、评论与作者里程碑。两项都留空表示全部；填写后，只要内容 ID 或所属分类命中一项即可。不会开放原本无权访问的内容。',
                ),
            ) .
            '</p><div class="xp-field"><label for="xp-scope-posts">' .
            esc_html(wp_xp_core_t('文章与页面 ID')) .
            '</label><input id="xp-scope-posts" name="rules[scope][post_ids]" value="' .
            esc_attr(implode(', ', $rules['scope']['post_ids'])) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('例如 12, 35')) .
            '"><p class="xp-help">' .
            esc_html(wp_xp_core_t('用逗号分隔，可在文章或页面编辑地址中找到 ID。')) .
            '</p></div><div class="xp-field"><label for="xp-scope-categories">' .
            esc_html(wp_xp_core_t('文章分类 ID')) .
            '</label><input id="xp-scope-categories" name="rules[scope][category_ids]" value="' .
            esc_attr(implode(', ', $rules['scope']['category_ids'])) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('例如 3, 8')) .
            '"><p class="xp-help">' .
            esc_html(wp_xp_core_t('用逗号分隔，页面不按文章分类匹配。')) .
            '</p></div><div class="xp-field"><label for="xp-timezone">' .
            esc_html(wp_xp_core_t('每日统计时区')) .
            '</label><input id="xp-timezone" name="rules[timezone]" value="' .
            esc_attr($rules['timezone']) .
            '" list="xp-timezones" required><datalist id="xp-timezones">';
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            echo '<option value="' . esc_attr($zone) . '"></option>';
        }
        echo '</datalist><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '默认 Asia/Shanghai。已有经验记录后不能修改，以免同一天重复领取奖励。',
                ),
            ) .
            '</p></div></details></section>';
        echo '<section class="xp-card" aria-labelledby="xp-milestones-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">02</span><div><h2 id="xp-milestones-title">' .
            esc_html(wp_xp_core_t('作者里程碑')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('每组可设置 0–20 档，次数须从小到大排列。')) .
            '</p></div></div>';
        // Keep original flat values for older integrations; nested rows are the current form controls.
        foreach (self::tiers() as $kind => $slots) {
            foreach ($slots as $slot) {
                foreach ([$kind . '_' . $slot, $kind . '_' . $slot . '_threshold'] as $key) {
                    echo '<input type="hidden" name="rules[' .
                        esc_attr($key) .
                        ']" value="' .
                        (int) $rules[$key] .
                        '">';
                }
            }
        }
        foreach ($rules['milestones'] as $kind => $rows) {
            $disabled = $kind === 'like' && !self::likes_ready();
            echo '<fieldset class="xp-milestone-group" data-kind="' .
                $kind .
                '"' .
                ($disabled ? ' disabled' : '') .
                '><legend>' .
                ($kind === 'view' ? wp_xp_core_t('浏览里程碑') : wp_xp_core_t('点赞里程碑')) .
                '</legend>';
            self::activity(
                $kind,
                wp_xp_core_t('启用') .
                    ($kind === 'view'
                        ? wp_xp_core_t('有效浏览累计与奖励')
                        : wp_xp_core_t('有效点赞累计与奖励')),
                $rules,
            );
            if ($kind === 'like') {
                echo '<p class="xp-help">' .
                    ($disabled
                        ? wp_xp_core_t(
                            '未启用点赞服务：需要 PageNest Companion 及其点赞功能。此组暂不可编辑和发奖，已有设置会保留。',
                        )
                        : wp_xp_core_t('点赞服务已启用，按经验账本记录的有效点赞累计。')) .
                    '</p>';
            }
            echo '<input type="hidden" name="rules[milestones][' .
                $kind .
                ']" value=""><div class="xp-milestone-rows">';
            foreach ($rows as $index => $row) {
                self::milestone_row($kind, $row, $index);
            }
            echo '</div><template class="xp-milestone-template">';
            self::milestone_row($kind, ['id' => '', 'threshold' => 1, 'xp' => 0], 0);
            echo '</template><button type="button" class="button xp-milestone-add" hidden>' .
                esc_html(wp_xp_core_t('添加档位')) .
                '</button><p class="xp-milestone-message" role="status" aria-live="polite"></p></fieldset>';
        }
        echo '<p class="xp-footnote">' .
            esc_html(
                wp_xp_core_t(
                    '每篇作品每档仅奖励一次，修改次数不会重置已领取档位。移除后保存，该档位会永久停用；新增档位使用独立标识。保存规则不补发历史奖励，之后的有效活动会按累计次数判断。',
                ),
            ) .
            '</p></section>';
        echo '<section class="xp-card" aria-labelledby="xp-levels-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">03</span><div><h2 id="xp-levels-title">' .
            esc_html(wp_xp_core_t('等级成长')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('逐级设置最低经验，当前共 ')) .
            '<span id="xp-level-count">' .
            count($rules['levels']) .
            '</span>' .
            esc_html(wp_xp_core_t(' 级。')) .
            '</p></div></div><div id="xp-levels" aria-describedby="xp-levels-help">';
        foreach ($rules['levels'] as $i => $level) {
            echo '<div class="xp-level-row"><label for="xp-level-' .
                $i .
                '">Level ' .
                ($i + 1) .
                '</label><div class="xp-input-unit"><input id="xp-level-' .
                $i .
                '" name="rules[levels][' .
                $i .
                ']" type="number" min="0" max="1000000000" step="1" required value="' .
                esc_attr((string) $level) .
                '"' .
                ($i === 0 ? ' readonly' : '') .
                '><span>' .
                esc_html(wp_xp_core_t('经验')) .
                '</span></div><button type="button" class="button xp-level-remove" aria-label="移除 Level ' .
                ($i + 1) .
                '"' .
                ($i === 0 ? ' disabled' : '') .
                '>' .
                esc_html(wp_xp_core_t('移除')) .
                '</button></div>';
        }
        echo '</div><button type="button" class="button xp-level-add" hidden>' .
            esc_html(wp_xp_core_t('添加等级')) .
            '</button><p class="xp-level-message" role="status" aria-live="polite"></p><noscript><p>' .
            esc_html(wp_xp_core_t('启用 JavaScript 后可添加或移除等级；现有门槛仍可编辑保存。')) .
            '</p></noscript><p id="xp-levels-help" class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '首级固定为 0，门槛须严格递增，最多 100 级。修改门槛会立即影响等级展示，不改变经验余额。',
                ),
            ) .
            '</p></section>';
        echo '<div class="xp-save"><p>' .
            esc_html(wp_xp_core_t('奖励调整仅影响之后首次记账的事件。')) .
            '</p>';
        submit_button(wp_xp_core_t('保存经验规则'), 'primary', 'submit', false);
        echo '</div></form><aside class="xp-sidebar" aria-label="' .
            esc_attr(wp_xp_core_t('插件信息与帮助')) .
            '"><section class="xp-card xp-about"><span class="xp-eyebrow">' .
            esc_html(wp_xp_core_t('关于插件')) .
            '</span><h2>WP XP Core</h2><p>' .
            esc_html(wp_xp_core_t('独立的 WordPress 经验与等级系统。')) .
            '</p><dl><div><dt>' .
            esc_html(wp_xp_core_t('版本')) .
            '</dt><dd>' .
            esc_html(Reader_Experience::VERSION) .
            '</dd></div><div><dt>' .
            esc_html(wp_xp_core_t('作者')) .
            '</dt><dd><a href="https://github.com/wzf2000">wzf2000</a></dd></div><div><dt>' .
            esc_html(wp_xp_core_t('许可证')) .
            '</dt><dd><a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html">GPL-2.0-or-later</a></dd></div></dl><a class="xp-repo-link" href="https://github.com/wzf2000/wp-xp-core">' .
            esc_html(wp_xp_core_t('源码与使用说明 ')) .
            '<span aria-hidden="true">↗</span></a><p class="xp-help">' .
            esc_html(wp_xp_core_t('查看项目文档、更新记录与贡献方式。')) .
            '</p></section>';
        self::panel_help();
        echo '<section class="xp-card xp-guide"><h2>' .
            esc_html(wp_xp_core_t('规则说明')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('保存全站规则前，可先了解生效范围。')) .
            '</p><details><summary>' .
            esc_html(wp_xp_core_t('奖励如何生效？')) .
            '</summary><p>' .
            esc_html(
                wp_xp_core_t(
                    '不重算或补发历史经验。0 经验仍记录事件，避免日后重复领取；评论撤销与恢复使用首次奖励数值。',
                ),
            ) .
            '</p></details><details><summary>' .
            esc_html(wp_xp_core_t('数值可以设置多大？')) .
            '</summary><p>' .
            esc_html(
                wp_xp_core_t(
                    '奖励为 0–1000000 的整数，每日次数为 0–1000。等级门槛最高为 1000000000。',
                ),
            ) .
            '</p></details></section></aside></div></div>';
    }
}
add_action('admin_menu', [WP_XP_Core_Settings::class, 'menu']);
add_action('admin_enqueue_scripts', [WP_XP_Core_Settings::class, 'assets']);
