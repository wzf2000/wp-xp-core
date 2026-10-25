<?php
/** Optional gross-first-reward caps and explicit public-board participation. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Fairness
{
    const EXCLUDED = 'wp_xp_core_board_exclusions';
    const FIRST_REWARD_KINDS = [
        'checkin',
        'visit',
        'article',
        'comment',
        'milestone_view',
        'milestone_like',
        'streak_reward',
        'task_complete',
    ];
    public static function cap(int $uid, string $kind, int $xp, string $day, array &$detail): int
    {
        if ($xp <= 0 || !in_array($kind, self::FIRST_REWARD_KINDS, true)) {
            return $xp;
        }
        $limits = WP_XP_Core_Settings::rules()['activity_caps'];
        if (!$limits['day'] && !$limits['week']) {
            return $xp;
        }
        if (!Reader_Experience::transaction_active()) {
            throw new RuntimeException('Reward caps require the ledger lock');
        }
        global $wpdb;
        $from = (new DateTimeImmutable($day, new DateTimeZone('UTC')))
            ->modify('monday this week')
            ->format('Y-m-d');
        $quoted = implode(
            ',',
            array_map(fn($v) => $wpdb->prepare('%s', $v), self::FIRST_REWARD_KINDS),
        );
        $used = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(CASE WHEN local_day=%s THEN xp ELSE 0 END),0) AS daily,COALESCE(SUM(xp),0) AS weekly FROM ' .
                    Reader_Experience::table() .
                    " WHERE user_id=%d AND xp>0 AND kind IN ($quoted) AND local_day BETWEEN %s AND %s",
                $day,
                $uid,
                $from,
                $day,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '' || !is_array($used)) {
            throw new RuntimeException('Activity cap totals unavailable');
        }
        $actual = min(
            $xp,
            $limits['day'] ? max(0, $limits['day'] - (int) $used['daily']) : $xp,
            $limits['week'] ? max(0, $limits['week'] - (int) $used['weekly']) : $xp,
        );
        $detail['activity_cap'] = [
            'requested_xp' => $xp,
            'actual_xp' => $actual,
            'daily_limit' => $limits['day'],
            'weekly_limit' => $limits['week'],
            'daily_gross_before' => (int) $used['daily'],
            'weekly_gross_before' => (int) $used['weekly'],
            'week_from' => $from,
            'truncated' => $actual < $xp,
        ];
        return $actual;
    }
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    public static function meta_key(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp_xp_core_public_board';
    }
    public static function excluded(): array
    {
        $raw = get_option(self::EXCLUDED, []);
        return is_array($raw)
            ? array_values(array_filter($raw, fn($id) => is_int($id) && $id > 0))
            : [];
    }
    public static function exclusion_revision(): string
    {
        return WP_XP_Core_Settings::revision(get_option(self::EXCLUDED, null));
    }
    public static function status(int $uid): string
    {
        if (in_array($uid, self::excluded(), true)) {
            return 'excluded';
        }
        return (string) get_user_meta($uid, self::meta_key(), true) === '0'
            ? 'opted_out'
            : 'active';
    }
    private static function permission($nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', '请先登录。', 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        return Reader_Experience::ready()
            ? true
            : self::error('maintenance', '经验系统维护中。', 503);
    }
    public static function personal($nonce)
    {
        $permission = self::permission($nonce);
        if ($permission !== true) {
            return $permission;
        }
        $uid = get_current_user_id();
        wp_cache_delete($uid, 'user_meta');
        $raw = metadata_exists('user', $uid, self::meta_key())
            ? get_user_meta($uid, self::meta_key(), true)
            : null;
        return [
            'participating' => (string) $raw !== '0',
            'status' => self::status($uid),
            'revision' => WP_XP_Core_Settings::revision($raw),
        ];
    }
    public static function save_personal(array $input, $nonce)
    {
        $permission = self::permission($nonce);
        if ($permission !== true) {
            return $permission;
        }
        if (
            array_diff(array_keys($input), ['participating', 'revision']) ||
            !is_bool($input['participating'] ?? null) ||
            !is_string($input['revision'] ?? null)
        ) {
            return self::error('input', '榜单参与设置无效。');
        }
        try {
            return Reader_Experience::transaction(function () use ($input, $nonce) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                $current = self::personal($nonce);
                if (is_wp_error($current)) {
                    return $current;
                }
                if (!hash_equals($current['revision'], $input['revision'])) {
                    return self::error('conflict', '榜单偏好已更新，请刷新后再修改。', 409);
                }
                $uid = get_current_user_id();
                $value = $input['participating'] ? '1' : '0';
                if (
                    (string) get_user_meta($uid, self::meta_key(), true) !== $value &&
                    !update_user_meta($uid, self::meta_key(), $value)
                ) {
                    throw new RuntimeException('Board preference write failed');
                }
                return self::personal($nonce);
            });
        } catch (Throwable $e) {
            return self::error('storage', '榜单偏好未能保存，请重试。', 503);
        }
    }
    public static function exclude(array $input, $nonce)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', '没有管理榜单的权限。', 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_insights')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        if (
            array_diff(array_keys($input), ['user_id', 'excluded', 'revision']) ||
            !is_string($input['revision'] ?? null) ||
            !in_array($input['excluded'] ?? null, [true, false, 0, 1, '0', '1'], true) ||
            (!is_int($input['user_id'] ?? null) && !is_string($input['user_id'] ?? null)) ||
            !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $input['user_id']) ||
            (int) $input['user_id'] > 2147483647
        ) {
            return self::error('input', '榜单排除设置无效。');
        }
        $uid = (int) $input['user_id'];
        $permission = WP_XP_Core_Management::permission($uid);
        if ($permission !== true) {
            return $permission;
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', '经验系统维护中。', 503);
        }
        try {
            return Reader_Experience::transaction(function () use ($input, $uid) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                WP_XP_Core_Management::require_options_transaction();
                foreach ([self::EXCLUDED, 'alloptions', 'notoptions'] as $key) {
                    wp_cache_delete($key, 'options');
                }
                if (!hash_equals(self::exclusion_revision(), $input['revision'])) {
                    return self::error('conflict', '榜单排除列表已更新。', 409);
                }
                $permission = WP_XP_Core_Management::permission($uid);
                if ($permission !== true) {
                    return $permission;
                }
                if (!get_userdata($uid)) {
                    return self::error('missing', '用户不存在。', 404);
                }
                $old = self::excluded();
                $next = array_values(array_filter($old, fn($id) => $id !== $uid));
                if ((bool) $input['excluded']) {
                    $next[] = $uid;
                }
                sort($next, SORT_NUMERIC);
                if (count($next) > 1000) {
                    return self::error('limit', '最多排除一千个账号。');
                }
                if ($old !== $next) {
                    Reader_Experience::touch_option(self::EXCLUDED);
                    if (!update_option(self::EXCLUDED, $next, false)) {
                        throw new RuntimeException('Exclusion write failed');
                    }
                    WP_XP_Core_Management::audit('board_exclusions', $old, $next);
                }
                return ['saved' => true];
            });
        } catch (Throwable $e) {
            return self::error('storage', '榜单排除设置未能保存。', 503);
        }
    }
}
