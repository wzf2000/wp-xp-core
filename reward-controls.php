<?php
/** Explicit, temporary holds affect only future automatic positive rewards. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Reward_Controls
{
    public static function key(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp_xp_core_reward_hold';
    }
    private static function raw(int $uid)
    {
        global $wpdb;
        wp_cache_delete($uid, 'user_meta');
        $wpdb->last_error = '';
        $exists = metadata_exists('user', $uid, self::key());
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Reward hold read failed');
        }
        if (!$exists) {
            return null;
        }
        $raw = get_user_meta($uid, self::key(), true);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Reward hold read failed');
        }
        return $raw;
    }
    public static function status(int $uid): array
    {
        $raw = self::raw($uid);
        $until = is_array($raw) && is_int($raw['until'] ?? null) ? $raw['until'] : 0;
        return ['active' => $until > time(), 'until' => $until > time() ? $until : 0];
    }
    public static function revision(int $uid): string
    {
        return WP_XP_Core_Settings::revision(self::raw($uid));
    }
    public static function apply(int $uid, string $kind, int $xp, array &$detail): int
    {
        if ($xp <= 0 || !in_array($kind, WP_XP_Core_Fairness::FIRST_REWARD_KINDS, true)) {
            return $xp;
        }
        if (!Reader_Experience::transaction_active()) {
            throw new RuntimeException('Reward hold requires transaction');
        }
        $state = self::status($uid);
        if (!$state['active']) {
            return $xp;
        }
        $detail['reward_hold'] = [
            'requested_xp' => $xp,
            'until' => $state['until'],
            'held' => true,
        ];
        return 0;
    }
    public static function save(array $input, $nonce)
    {
        $uid =
            is_scalar($input['user_id'] ?? null) &&
            preg_match('/^[1-9][0-9]{0,9}$/D', (string) $input['user_id'])
                ? (int) $input['user_id']
                : 0;
        $permission = WP_XP_Core_Management::permission($uid);
        if ($permission !== true) {
            return $permission;
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_manage')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        if (
            array_diff(array_keys($input), ['user_id', 'minutes', 'reason', 'revision']) ||
            !$uid ||
            !get_userdata($uid) ||
            !is_string($input['reason'] ?? null) ||
            trim($input['reason']) === '' ||
            mb_strlen($input['reason']) > 300 ||
            preg_match('/[<>\x00-\x1f]/u', $input['reason']) ||
            !is_scalar($input['minutes'] ?? null) ||
            !preg_match('/^(0|[1-9][0-9]{0,3})$/D', (string) $input['minutes']) ||
            (int) $input['minutes'] > 1440 ||
            !is_string($input['revision'] ?? null)
        ) {
            return new WP_Error(
                'input',
                wp_xp_core_t('请填写用户、原因和不超过二十四小时的暂停时间。'),
            );
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), ['status' => 503]);
        }
        try {
            return Reader_Experience::transaction(function () use ($input, $uid) {
                $permission = WP_XP_Core_Management::permission($uid);
                if ($permission !== true) {
                    return $permission;
                }
                if (!Reader_Experience::ready()) {
                    throw new RuntimeException('Unavailable');
                }
                WP_XP_Core_Management::require_options_transaction();
                $raw = self::raw($uid);
                if (!hash_equals(WP_XP_Core_Settings::revision($raw), $input['revision'])) {
                    return new WP_Error('conflict', wp_xp_core_t('暂停状态已更新，请重新读取。'), [
                        'status' => 409,
                    ]);
                }
                $next = [
                    'until' => (int) $input['minutes'] ? time() + (int) $input['minutes'] * 60 : 0,
                    'reason' => trim($input['reason']),
                    'actor' => get_current_user_id(),
                ];
                Reader_Experience::touch_user($uid);
                if (!update_user_meta($uid, self::key(), $next) && self::raw($uid) !== $next) {
                    throw new RuntimeException('Hold write failed');
                }
                WP_XP_Core_Management::audit(
                    'reward_hold',
                    ['user_id' => $uid, 'hold' => $raw],
                    ['user_id' => $uid, 'hold' => $next],
                );
                return ['saved' => true];
            });
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('暂停状态未能保存，请重试。'), [
                'status' => 503,
            ]);
        }
    }
    /** Two primary-key windows, no aggregate over the full ledger and no event payload output. */
    public static function measure($nonce)
    {
        if (
            !current_user_can('manage_options') ||
            !is_string($nonce) ||
            !wp_verify_nonce($nonce, 'wp_xp_core_manage')
        ) {
            return new WP_Error('forbidden', wp_xp_core_t('没有查看统计的权限。'), [
                'status' => 403,
            ]);
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), ['status' => 503]);
        }
        global $wpdb;
        $out = [];
        foreach ([50, 500] as $limit) {
            $queries = $wpdb->num_queries;
            $start = microtime(true);
            $rows = $wpdb->get_results(
                'SELECT id FROM ' .
                    Reader_Experience::table() .
                    ' ORDER BY id DESC LIMIT ' .
                    $limit,
                ARRAY_A,
            );
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                return new WP_Error('storage', wp_xp_core_t('暂时无法读取经验记录。'), [
                    'status' => 503,
                ]);
            }
            $out[] = [
                'limit' => $limit,
                'rows' => count($rows),
                'queries' => $wpdb->num_queries - $queries,
                'milliseconds' => round((microtime(true) - $start) * 1000, 3),
            ];
        }
        return ['samples' => $out];
    }
}
