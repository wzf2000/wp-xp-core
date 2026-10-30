<?php
/** Real transaction/permission boundaries on the owned synthetic site. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
$checks = [];
$verify = static function ($label, $condition) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$uid = wp_insert_user([
    'user_login' => 'fixture-portable-reader',
    'user_pass' => wp_generate_password(32),
    'user_email' => 'portable@example.invalid',
    'role' => 'subscriber',
]);
$verify('Fresh portable subject', !is_wp_error($uid));
$uid = (int) $uid;
global $wpdb;
$table = Reader_Experience::table();
$rules = WP_XP_Core_Settings::rules();
$original_rules = $rules;
$save_rules = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$nonce = wp_create_nonce('wp_xp_core_manage');
$hold = static fn($minutes, $revision = null) => WP_XP_Core_Reward_Controls::save(
    [
        'user_id' => $uid,
        'minutes' => $minutes,
        'reason' => 'Synthetic hold reason',
        'revision' => $revision ?? WP_XP_Core_Reward_Controls::revision($uid),
    ],
    $nonce,
);
$balance = static fn() => (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
$verify(
    'Provider with independent revocation authorization registers',
    wp_xp_core_register_activity_provider('portable_fixture', [
        'label' => 'Portable fixture',
        'authorize' => static fn($user, $object, $context) => $user === $uid && $object === 42,
        'revoke_authorize' => static fn($user, $object, $context) => $user === $uid &&
            $object === 42,
    ]),
);
$rules['providers']['portable_fixture'] = ['enabled' => true, 'xp' => 9];
$rules['activity_caps'] = ['day' => 4, 'week' => 0];
$verify('Provider policy saves', !is_wp_error($save_rules($rules)));
$receipt = wp_xp_core_record_activity('portable_fixture', 'capped', $uid, 42);
$verify('Capped provider earns actual four', !is_wp_error($receipt) && $balance() === 4);
$verify(
    'Duplicate provider is stable',
    wp_xp_core_record_activity('portable_fixture', 'capped', $uid, 42)['duplicate'] === true,
);
$verify(
    'Foreign original cannot revoke',
    $error(wp_xp_core_revoke_activity('portable_fixture', 'capped', $admin->ID, 42), 'ineligible'),
);
$rules['providers']['portable_fixture']['enabled'] = false;
$verify('Reward provider disabled', !is_wp_error($save_rules($rules)));
$revoke = wp_xp_core_revoke_activity('portable_fixture', 'capped', $uid, 42);
$verify(
    'Disabled provider reverses original capped four',
    !is_wp_error($revoke) && $balance() === 0,
);
$verify(
    'Revoke is idempotent',
    wp_xp_core_revoke_activity('portable_fixture', 'capped', $uid, 42)['duplicate'] === true,
);
$rules['activity_caps'] = ['day' => 0, 'week' => 0];
$rules['providers']['portable_fixture']['enabled'] = true;
$verify('Uncapped policy saves', !is_wp_error($save_rules($rules)));
$revision = WP_XP_Core_Reward_Controls::revision($uid);
$verify('Manual hold saves', !is_wp_error($hold(60)));
$verify('Stale hold rejects', $error($hold(60, $revision), 'conflict'));
$verify(
    'Hold reports only active and until',
    array_keys(WP_XP_Core_Reward_Controls::status($uid)) === ['active', 'until'],
);
$held = wp_xp_core_record_activity('portable_fixture', 'held', $uid, 42);
$verify('Held event persists zero rather than failure', !is_wp_error($held) && $balance() === 0);
$detail = json_decode(
    $wpdb->get_var(
        $wpdb->prepare('SELECT detail FROM ' . $table . ' WHERE id=%d', $held['event_id']),
    ),
    true,
);
$verify(
    'Hold receipt records requested amount without admin reason',
    ($detail['reward_hold']['requested_xp'] ?? 0) === 9 &&
        !str_contains(wp_json_encode($detail), 'Synthetic hold reason'),
);
$verify(
    'Held original can be reversed exactly',
    !is_wp_error(wp_xp_core_revoke_activity('portable_fixture', 'held', $uid, 42)),
);
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'portable-compensation',
        $uid,
        'comment_adjust',
        0,
        3,
        Reader_Experience::day(),
    ),
);
$verify('Original compensation bypasses hold', $balance() === 3);
$verify('Explicit release saves', !is_wp_error($hold(0)));
$verify(
    'Held duplicate never gains difference',
    wp_xp_core_record_activity('portable_fixture', 'held', $uid, 42)['duplicate'] === true &&
        $balance() === 3,
);
$new = wp_xp_core_record_activity('portable_fixture', 'insufficient', $uid, 42);
$verify('New event rewards after release', !is_wp_error($new) && $balance() === 12);
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'portable-spend-fixture',
        $uid,
        'manual_adjust',
        0,
        -8,
        Reader_Experience::day(),
    ),
);
$verify(
    'Insufficient balance rejects entire reversal',
    $error(wp_xp_core_revoke_activity('portable_fixture', 'insufficient', $uid, 42), 'balance') &&
        $balance() === 4,
);
$verify(
    'Failed reversal does not create receipt',
    !(bool) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE event_key=%s',
            'provider-revoke:' . $new['event_id'],
        ),
    ),
);
$fail = static function ($sql) use ($wpdb) {
    if (str_contains($sql, 'SELECT user_id, meta_key, meta_value FROM ' . $wpdb->usermeta)) {
        return 'SELECT * FROM synthetic_portable_missing_table';
    }
    return $sql;
};
$before_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
wp_cache_delete($uid, 'user_meta');
$old_suppress = $wpdb->suppress_errors(true);
add_filter('query', $fail);
try {
    $failed = wp_xp_core_record_activity('portable_fixture', 'hold-read-failed', $uid, 42);
} finally {
    remove_filter('query', $fail);
    $wpdb->suppress_errors($old_suppress);
}
$verify(
    'Hold metadata read failure aborts reward',
    $error($failed, 'storage') &&
        (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === $before_count,
);
$task = [
    'id' => '',
    'title' => 'Preview task',
    'description' => 'Synthetic',
    'icon' => '',
    'type' => 'automatic',
    'period' => 'once',
    'enabled' => false,
    'steps' => [['kind' => 'checkin', 'count' => 1]],
];
$before = WP_XP_Core_Achievements::revision();
$preview = WP_XP_Core_Achievements::preview(
    $task,
    wp_create_nonce('wp_xp_core_achievements'),
    $before,
);
$verify(
    'Preview is read only',
    !is_wp_error($preview) &&
        WP_XP_Core_Achievements::revision() === $before &&
        $preview['task']['id'] === '',
);
$verify(
    'Preview rejects stale revision',
    $error(
        WP_XP_Core_Achievements::preview(
            $task,
            wp_create_nonce('wp_xp_core_achievements'),
            str_repeat('a', 64),
        ),
        'conflict',
    ),
);
$old_tasks = WP_XP_Core_Achievements::definitions();
$saved = WP_XP_Core_Achievements::save($task, wp_create_nonce('wp_xp_core_achievements'), $before);
$verify('Task fixture saves at version one', !is_wp_error($saved));
$existing = WP_XP_Core_Achievements::definitions()[$saved['id']];
$edit = WP_XP_Core_Achievements::validate(array_intersect_key($existing, $preview['task']));
$edit['title'] = 'Edited title only';
$title_preview = WP_XP_Core_Achievements::preview(
    $edit,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$verify(
    'Text-only preview retains actual version and progress',
    !$title_preview['restarts'] && $title_preview['version'] === $existing['version'],
);
$edit['reward_xp'] = 5;
$condition_preview = WP_XP_Core_Achievements::preview(
    $edit,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$verify(
    'Reward preview predicts the save version boundary',
    $condition_preview['restarts'] && $condition_preview['version'] === $existing['version'] + 1,
);
$edit['id'] = '';
$edit['enabled'] = false;
$copy_preview = WP_XP_Core_Achievements::preview(
    $edit,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$verify(
    'Independent copied draft previews version one',
    $copy_preview['new_task'] && $copy_preview['version'] === 1,
);
Reader_Experience::transaction(static function () use ($old_tasks) {
    WP_XP_Core_Management::require_options_transaction();
    Reader_Experience::touch_option(WP_XP_Core_Achievements::OPTION);
    if (!update_option(WP_XP_Core_Achievements::OPTION, $old_tasks, false)) {
        throw new RuntimeException('Task fixture restore failed');
    }
});
WP_XP_Core_Achievements::clear_cache();
$before = WP_XP_Core_Achievements::revision();
$task['prerequisites'] = [['id' => 't_ffffffffffffffff', 'scope' => 'lifetime']];
$verify(
    'Preview checks graph',
    $error(
        WP_XP_Core_Achievements::preview(
            $task,
            wp_create_nonce('wp_xp_core_achievements'),
            $before,
        ),
        'input',
    ),
);
$draft = WP_XP_Core_Achievements_UI::draft([
    'title' => 'Retained title',
    'description' => "Retained description\nSecond line\r\n\tDetail",
    'starts_on' => '2030-02-03',
    'ends_on' => '2030-01-01',
    'type' => 'automatic',
    'period' => 'week',
    'steps' => [['kind' => 'visit', 'mode' => 'objects', 'count' => '3', 'post_ids' => '1,2']],
]);
$verify(
    'Invalid date draft retains title dates description and steps',
    $draft['title'] === 'Retained title' &&
        $draft['description'] === "Retained description\nSecond line\r\n\tDetail" &&
        $draft['starts_on'] === '2030-02-03' &&
        $draft['ends_on'] === '2030-01-01' &&
        $draft['steps'][0]['count'] === '3' &&
        $draft['steps'][0]['post_ids'] === ['1', '2'],
);
$malicious = WP_XP_Core_Achievements_UI::draft([
    'title' => ['nested'],
    'description' => '<script>synthetic</script>',
    'steps' => [['kind' => ['nested'], 'post_ids' => [['nested'], 1]], 'invalid'],
    'prerequisites' => [['id' => ['nested']]],
]);
$verify(
    'Malformed draft arrays remain bounded safe form values',
    $malicious['title'] === '' &&
        $malicious['steps'][0]['kind'] === '' &&
        $malicious['steps'][0]['post_ids'] === ['1'] &&
        $malicious['prerequisites'][0]['id'] === '',
);
$verify(
    'Diagnostics are bounded',
    array_column(WP_XP_Core_Reward_Controls::measure($nonce)['samples'], 'limit') === [50, 500],
);
wp_set_current_user($uid);
$verify(
    'Subscriber cannot change hold',
    $error(
        WP_XP_Core_Reward_Controls::save(
            [
                'user_id' => $uid,
                'minutes' => 1,
                'reason' => 'bad',
                'revision' => WP_XP_Core_Reward_Controls::revision($uid),
            ],
            wp_create_nonce('wp_xp_core_manage'),
        ),
        'forbidden',
    ),
);
$verify(
    'Subscriber cannot preview task',
    $error(
        WP_XP_Core_Achievements::preview(
            $task,
            wp_create_nonce('wp_xp_core_achievements'),
            $before,
        ),
        'forbidden',
    ),
);
$verify(
    'Subscriber cannot run diagnostic',
    $error(WP_XP_Core_Reward_Controls::measure(wp_create_nonce('wp_xp_core_manage')), 'forbidden'),
);
$verify(
    'Portable notification source registers',
    wp_xp_core_register_notification_source('fixture', [
        'label' => 'Fixture source',
        'available' => static fn($user) => $user === $uid,
        'listing' => static fn($user, $cursor, $limit) => [
            'items' => [
                [
                    'id' => 'private',
                    'title' => '<synthetic>',
                    'message' => 'Owner ' . $user,
                    'day' => 'today',
                    'read' => false,
                ],
            ],
            'next_cursor' => null,
        ],
        'mark' => static fn($user, $id) => $user === $uid && $id === 'private'
            ? ['saved' => true]
            : new WP_Error('missing', 'Missing'),
    ]),
);
$rest = wp_create_nonce('wp_rest');
$list = WP_XP_Core_Notification_Sources::request(
    'notification-source-list',
    ['source' => 'fixture', 'limit' => 1],
    $rest,
);
$verify(
    'Private source receives only session user',
    !is_wp_error($list) && $list['items'][0]['message'] === 'Owner ' . $uid,
);
$verify(
    'Source rejects forged user id',
    $error(
        WP_XP_Core_Notification_Sources::request(
            'notification-source-list',
            ['source' => 'fixture', 'user_id' => $admin->ID],
            $rest,
        ),
        'input',
    ),
);
$verify(
    'Source rejects excessive limit',
    $error(
        WP_XP_Core_Notification_Sources::request(
            'notification-source-list',
            ['source' => 'fixture', 'limit' => 51],
            $rest,
        ),
        'input',
    ),
);
$verify(
    'Source mark delegates ownership',
    WP_XP_Core_Notification_Sources::request(
        'notification-source-read',
        ['source' => 'fixture', 'id' => 'private'],
        $rest,
    )['saved'] === true,
);
$verify(
    'Source rejects invalid nonce',
    $error(
        WP_XP_Core_Notification_Sources::request('notification-sources', [], 'expired'),
        'nonce',
    ),
);
wp_set_current_user(0);
$verify(
    'Anonymous source reads denied',
    $error(
        WP_XP_Core_Notification_Sources::request('notification-sources', [], $rest),
        'login_required',
    ),
);
wp_set_current_user($admin->ID);
$verify('Original policy restored', !is_wp_error($save_rules($original_rules)));
echo wp_json_encode(['checks' => $checks, 'count' => count($checks)]);
