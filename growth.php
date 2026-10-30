<?php
/** User growth profiles and opt-in check-in milestones. Ordinary reads never award rewards. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Growth
{
    const OPTION = 'wp_xp_core_growth';
    public static function defaults(): array
    {
        return [
            'levels' => [],
            'streak' => [
                'enabled' => false,
                'mode' => 'consecutive',
                'rewards' => [],
                'start_event_id' => 0,
                'generation' => 0,
            ],
        ];
    }
    public static function settings(): array
    {
        $raw = get_option(self::OPTION, null);
        if (!is_array($raw)) {
            return self::defaults();
        }
        try {
            $candidate = [
                'levels' => $raw['levels'] ?? [],
                'streak' => array_intersect_key(
                    $raw['streak'] ?? [],
                    array_flip(['enabled', 'mode', 'rewards']),
                ),
            ];
            $clean = self::validate($candidate);
            $clean['streak']['start_event_id'] = self::integer(
                $raw['streak']['start_event_id'] ?? 0,
                0,
                PHP_INT_MAX,
            );
            $clean['streak']['generation'] = self::integer(
                $raw['streak']['generation'] ?? 0,
                0,
                2147483647,
            );
            return $clean;
        } catch (Throwable $e) {
            return self::defaults();
        }
    }
    public static function revision(): string
    {
        return WP_XP_Core_Settings::revision(get_option(self::OPTION, null));
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
            throw new InvalidArgumentException(wp_xp_core_t('请填写有效的整数。'));
        }
        return (int) $value;
    }
    private static function text($value, int $limit): string
    {
        if (
            !is_string($value) ||
            mb_strlen($value) > $limit ||
            preg_match('/[<>\x00-\x1f]/u', $value)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('名称或说明包含无效内容。'));
        }
        return trim($value);
    }
    public static function validate(array $input): array
    {
        if (
            array_diff(array_keys($input), ['levels', 'streak']) ||
            !is_array($input['levels'] ?? null) ||
            !is_array($input['streak'] ?? null) ||
            count($input['levels']) > 100
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('成长设置不完整。'));
        }
        $levels = [];
        foreach ($input['levels'] as $number => $row) {
            $number = self::integer($number, 1, 100);
            if (
                !is_array($row) ||
                array_diff(array_keys($row), ['name', 'icon', 'color', 'description'])
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('等级资料无效。'));
            }
            $color = $row['color'] ?? '#3856b0';
            if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/iD', $color)) {
                throw new InvalidArgumentException(wp_xp_core_t('请选择有效的等级颜色。'));
            }
            $levels[$number] = [
                'name' => self::text($row['name'] ?? '', 40),
                'icon' => self::text($row['icon'] ?? '', 8),
                'color' => $color,
                'description' => self::text($row['description'] ?? '', 160),
            ];
        }
        $s = $input['streak'];
        if (
            array_diff(array_keys($s), ['enabled', 'mode', 'rewards']) ||
            !in_array($s['enabled'] ?? false, [true, false, 0, 1, '0', '1'], true) ||
            !in_array($s['mode'] ?? '', ['consecutive', 'cumulative'], true) ||
            !is_array($s['rewards'] ?? null) ||
            count($s['rewards']) > 10
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('签到成长设置无效。'));
        }
        $rewards = [];
        $days = 0;
        $ids = [];
        foreach ($s['rewards'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'days', 'xp'])) {
                throw new InvalidArgumentException(wp_xp_core_t('签到奖励无效。'));
            }
            $id = $row['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $id) || isset($ids[$id])) {
                throw new InvalidArgumentException(wp_xp_core_t('签到奖励编号无效或重复。'));
            }
            $n = self::integer($row['days'] ?? null, 1, 365);
            if ($n <= $days) {
                throw new InvalidArgumentException(wp_xp_core_t('签到奖励天数必须严格递增。'));
            }
            $rewards[] = [
                'id' => $id,
                'days' => $n,
                'xp' => self::integer($row['xp'] ?? null, 0, 1000000),
            ];
            $days = $n;
            $ids[$id] = true;
        }
        return [
            'levels' => $levels,
            'streak' => [
                'enabled' => (bool) $s['enabled'],
                'mode' => $s['mode'],
                'rewards' => $rewards,
            ],
        ];
    }
    public static function save(array $input, $nonce, $revision)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', wp_xp_core_t('没有管理成长设置的权限。'), [
                'status' => 403,
            ]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_growth')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), ['status' => 503]);
        }
        try {
            $value = self::validate($input);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('input', $e->getMessage(), ['status' => 400]);
        }
        try {
            return Reader_Experience::transaction(function () use ($value, $revision) {
                if (!Reader_Experience::ready()) {
                    return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), [
                        'status' => 503,
                    ]);
                }
                WP_XP_Core_Management::require_options_transaction();
                self::clear_cache();
                $old = get_option(self::OPTION, null);
                if (
                    !is_string($revision) ||
                    !hash_equals(WP_XP_Core_Settings::revision($old), $revision)
                ) {
                    return new WP_Error('conflict', wp_xp_core_t('成长设置已更新，请刷新页面。'), [
                        'status' => 409,
                    ]);
                }
                $previous = self::settings();
                $next = $value;
                $reset =
                    $next['streak']['enabled'] &&
                    (!$previous['streak']['enabled'] ||
                        $previous['streak']['mode'] !== $next['streak']['mode'] ||
                        $previous['streak']['rewards'] !== $next['streak']['rewards']);
                $next['streak']['start_event_id'] = $reset
                    ? self::last_event_id()
                    : $previous['streak']['start_event_id'];
                $next['streak']['generation'] = $reset
                    ? $previous['streak']['generation'] + 1
                    : $previous['streak']['generation'];
                if ($old === $next) {
                    return true;
                }
                Reader_Experience::touch_option(self::OPTION);
                if (!update_option(self::OPTION, $next, false)) {
                    throw new RuntimeException('Growth settings write failed');
                }
                WP_XP_Core_Management::audit('growth_settings', $old, $next);
                return true;
            });
        } catch (Throwable $e) {
            self::clear_cache();
            return new WP_Error('storage', wp_xp_core_t('成长设置未能保存，请重试。'), [
                'status' => 503,
            ]);
        }
    }
    public static function clear_cache(): void
    {
        foreach ([self::OPTION, 'alloptions', 'notoptions'] as $key) {
            wp_cache_delete($key, 'options');
        }
    }
    private static function last_event_id(): int
    {
        global $wpdb;
        $id = $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . Reader_Experience::table());
        if ($wpdb->last_error !== '' || $id === null) {
            throw new RuntimeException('Growth boundary unavailable');
        }
        return (int) $id;
    }

    public static function level_profile(int $number): array
    {
        $raw = self::settings()['levels'][$number] ?? [];
        return array_replace(
            ['name' => 'Level ' . $number, 'icon' => '', 'color' => '#3856b0', 'description' => ''],
            $raw,
            ['name' => ($raw['name'] ?? '') !== '' ? $raw['name'] : 'Level ' . $number],
        );
    }
    public static function checkins(int $uid): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT local_day FROM ' .
                    Reader_Experience::table() .
                    " WHERE user_id=%d AND kind='checkin' ORDER BY local_day DESC",
                $uid,
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Check-in history unavailable');
        }
        $today = Reader_Experience::day();
        $day = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        $streak = 0;
        $expected = isset($rows[0]) && $rows[0] === $today ? $day : $day->modify('-1 day');
        foreach ($rows as $date) {
            if ($date !== $expected->format('Y-m-d')) {
                break;
            }
            $streak++;
            $expected = $expected->modify('-1 day');
        }
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT local_day) FROM ' .
                    Reader_Experience::table() .
                    " WHERE user_id=%d AND kind='checkin'",
                $uid,
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Check-in totals unavailable');
        }
        return [
            'consecutive' => $streak,
            'cumulative' => $total,
            'dates' => array_slice($rows, 0, 35),
        ];
    }
    public static function streak_progress(int $uid): array
    {
        global $wpdb;
        $s = self::settings()['streak'];
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT local_day FROM ' .
                    Reader_Experience::table() .
                    " WHERE user_id=%d AND kind='checkin' AND id>%d ORDER BY local_day DESC LIMIT 366",
                $uid,
                (int) $s['start_event_id'],
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Check-in progress unavailable');
        }
        $count = 0;
        if ($s['mode'] === 'cumulative') {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT local_day) FROM ' .
                        Reader_Experience::table() .
                        " WHERE user_id=%d AND kind='checkin' AND id>%d",
                    $uid,
                    (int) $s['start_event_id'],
                ),
            );
        } else {
            $date = new DateTimeImmutable(Reader_Experience::day(), new DateTimeZone('UTC'));
            if (($rows[0] ?? '') !== $date->format('Y-m-d')) {
                $date = $date->modify('-1 day');
            }
            foreach ($rows as $row) {
                if ($row !== $date->format('Y-m-d')) {
                    break;
                }
                $count++;
                $date = $date->modify('-1 day');
            }
        }
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Check-in progress unavailable');
        }
        return [
            'enabled' => $s['enabled'],
            'mode' => $s['mode'],
            'days' => $count,
            'rewards' => $s['rewards'],
            'generation' => $s['generation'],
        ];
    }
    /** Apply derived XP inside the originating check-in transaction. */
    public static function apply(array $event): void
    {
        if (($event['kind'] ?? '') !== 'checkin') {
            return;
        }
        if (!Reader_Experience::transaction_active()) {
            throw new RuntimeException('Growth reward transaction required');
        }
        self::clear_cache();
        $s = self::settings()['streak'];
        if (!$s['enabled'] || (int) $event['id'] <= $s['start_event_id']) {
            return;
        }
        $uid = (int) $event['user_id'];
        $progress = self::streak_progress($uid);
        foreach ($s['rewards'] as $reward) {
            if ($progress['days'] >= $reward['days']) {
                Reader_Experience::record(
                    'streak:' . $uid . ':' . $s['generation'] . ':' . $reward['id'],
                    $uid,
                    'streak_reward',
                    0,
                    $reward['xp'],
                    Reader_Experience::day(),
                    [
                        'days' => $reward['days'],
                        'mode' => $s['mode'],
                        'trigger_event_id' => $event['id'],
                    ],
                );
            }
        }
    }
    public static function summary(int $uid): array
    {
        $state = Reader_Experience::state($uid);
        $rules = WP_XP_Core_Settings::rules();
        $minimum = $rules['levels'][$state['level'] - 1];
        $next = $state['next'];
        $state['level_profile'] = self::level_profile($state['level']);
        $state['progress_percent'] =
            $next === null
                ? 100
                : min(
                    100,
                    max(
                        0,
                        (int) floor(
                            (100 * ($state['experience'] - $minimum)) / max(1, $next - $minimum),
                        ),
                    ),
                );
        $state['reward_hold'] = WP_XP_Core_Reward_Controls::status($uid);
        if ($state['reward_hold']['active']) {
            $state['checkin_xp'] = 0;
        }
        $state['day'] = Reader_Experience::day();
        $state['checkins'] = self::checkins($uid);
        $state['streak'] = self::streak_progress($uid);
        $state['today'] = [
            'visits' => $state['visit_today'],
            'visit_limit' => $rules['visit_limit'],
            'comments' => $state['comment_today'],
            'comment_limit' => $rules['comment_limit'],
        ];
        return $state;
    }
}
