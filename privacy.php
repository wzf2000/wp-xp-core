<?php
/** Bounded WordPress personal-data export. Retention is deliberate; no destructive eraser. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Privacy
{
    public static function exporters(array $exporters): array
    {
        $exporters['wp-xp-core'] = [
            'exporter_friendly_name' => __('WP XP Core experience', 'wp-xp-core'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }
    public static function export(string $email, int $page = 1): array
    {
        $empty = ['data' => [], 'done' => true];
        $user = get_user_by('email', $email);
        if (
            !$user ||
            $page < 1 ||
            $page > 10000000 ||
            !Reader_Experience::ready() ||
            (is_multisite() && !is_user_member_of_blog($user->ID))
        ) {
            return $empty;
        }
        global $wpdb;
        $limit = 50;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,kind,object_id,local_day,xp,created_at,detail FROM ' .
                    Reader_Experience::table() .
                    ' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
                $user->ID,
                $limit + 1,
                ($page - 1) * $limit,
            ),
            ARRAY_A,
        );
        if (!is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('Personal export unavailable');
        }
        $done = count($rows) <= $limit;
        $data = [];
        if ($page === 1) {
            $data[] = [
                'group_id' => 'wp-xp-core',
                'group_label' => __('Experience and growth', 'wp-xp-core'),
                'item_id' => 'wp-xp-core-profile',
                'data' => [
                    [
                        'name' => __('Balance', 'wp-xp-core'),
                        'value' => (string) (int) get_user_meta(
                            $user->ID,
                            Reader_Experience::balance_meta(),
                            true,
                        ),
                    ],
                    [
                        'name' => __('Public standings', 'wp-xp-core'),
                        'value' => WP_XP_Core_Fairness::status($user->ID),
                    ],
                    [
                        'name' => __('Notification preferences and read state', 'wp-xp-core'),
                        'value' => wp_json_encode(self::preferences($user->ID)),
                    ],
                ],
            ];
        }
        foreach (array_slice($rows, 0, $limit) as $row) {
            $detail = json_decode($row['detail'] ?? '', true);
            $snapshot = is_array($detail)
                ? array_intersect_key(
                    $detail,
                    array_flip([
                        'task_id',
                        'version',
                        'period',
                        'title',
                        'text',
                        'icon',
                        'category',
                        'series',
                        'condition_text',
                        'reward_xp',
                        'decision',
                        'level_transition',
                        'activity_cap',
                        'provider',
                        'provider_label',
                    ]),
                )
                : [];
            $data[] = [
                'group_id' => 'wp-xp-core',
                'group_label' => __('Experience and growth', 'wp-xp-core'),
                'item_id' => 'wp-xp-core-event-' . $row['id'],
                'data' => [
                    ['name' => __('Event', 'wp-xp-core'), 'value' => (string) $row['id']],
                    ['name' => __('Source', 'wp-xp-core'), 'value' => $row['kind']],
                    [
                        'name' => __('Content ID', 'wp-xp-core'),
                        'value' => (string) $row['object_id'],
                    ],
                    ['name' => __('Date', 'wp-xp-core'), 'value' => $row['local_day']],
                    ['name' => __('XP', 'wp-xp-core'), 'value' => (string) $row['xp']],
                    [
                        'name' => __('Recorded at (UTC timestamp)', 'wp-xp-core'),
                        'value' => (string) $row['created_at'],
                    ],
                    [
                        'name' => __('Snapshot', 'wp-xp-core'),
                        'value' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ];
        }
        return ['data' => $data, 'done' => $done];
    }
    private static function preferences(int $uid): array
    {
        global $wpdb;
        $raw = get_user_meta($uid, $wpdb->prefix . 'wp_xp_core_inbox', true);
        return is_array($raw)
            ? array_intersect_key($raw, array_flip(['preferences', 'read_until', 'read_ids']))
            : [];
    }
    public static function policy(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(
                'WP XP Core',
                wp_kses_post(
                    __(
                        '<p>WP XP Core records account-linked activity, XP, task submissions and achievement snapshots, plus private notification preferences/read state. Public standings show participating accounts. Administrators can export personal experience data through WordPress privacy tools. Deactivation, updates and deletion of plugin files retain the ledger, balances and settings. No automatic expiry or personal-data eraser is provided: deleting append-only records without a reviewed accounting and identity policy could break balances and audit evidence. Site administrators determine legal retention and respond to erasure requests using their site policy.</p>',
                        'wp-xp-core',
                    ),
                ),
            );
        }
    }
}
add_filter('wp_privacy_personal_data_exporters', [WP_XP_Core_Privacy::class, 'exporters']);
add_action('admin_init', [WP_XP_Core_Privacy::class, 'policy']);
