<?php
/** Bounded activity policies, immutable zero receipts, private messages and optional delivery. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Owned synthetic database required');
}
$checks = [];
$verify = static function ($name, $ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
do_action('admin_enqueue_scripts', 'settings_page_wp-xp-activities');
$verify('Activity tab loads the shared admin styles', wp_style_is('wp-xp-core-admin', 'enqueued'));
$uid = wp_insert_user([
    'user_login' => 'fixture-easy-activity',
    'user_email' => 'easy-activity@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Fresh activity subject', !is_wp_error($uid));
$uid = (int) $uid;
$original = WP_XP_Core_Settings::rules();
$rules = $original;
$save = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$attempts = 0;
wp_xp_core_register_notification_channel('easy_channel', [
    'label' => 'Synthetic channel',
    'available' => static fn() => true,
    'deliver' => static function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Synthetic private exception must not be stored');
    },
]);
$created = 0;
$score = 20;
$verify(
    'Trusted score provider registers',
    wp_xp_core_register_activity_provider('easy_fixture', [
        'label' => 'Synthetic participation',
        'authorize' => static fn($user, $object, $context, $event) => $user === $uid &&
            $object === 77,
        'fields' => ['score'],
        'facts' => static function () use (&$created, &$score) {
            return ['created_ms' => $created, 'score' => $score];
        },
    ]),
);
$verify(
    'Score declaration requires facts',
    !wp_xp_core_register_activity_provider('bad_fields', [
        'label' => 'Invalid',
        'authorize' => static fn() => true,
        'fields' => ['score'],
    ]),
);
$rules['providers']['easy_fixture'] = [
    'enabled' => true,
    'xp' => 5,
    'day_limit' => 1,
    'min_score' => 10,
    'inbox' => true,
    'template' => '完成{activity}，本次经验+{xp}',
    'channels' => ['easy_channel'],
];
$rules['activity_caps'] = ['day' => 0, 'week' => 0];
$verify('Activity policy saves', !is_wp_error($save($rules)));
$boundary = WP_XP_Core_Providers::boundary('easy_fixture');
$created = $boundary + 1;
$verify('Server boundary created', $boundary > 0);
$event = static fn($id) => wp_xp_core_record_activity('easy_fixture', $id, $uid, 77, [
    'score' => 999999,
]);
$score = 1;
$verify(
    'Forged context score cannot bypass authoritative minimum',
    $error($event('under-score'), 'threshold'),
);
$score = 20;
$one = $event('first');
$verify('New event commits despite channel failure', !is_wp_error($one) && $attempts === 1);
$two = $event('quota-zero');
$verify('Exhausted event commits permanent zero receipt', !is_wp_error($two) && $attempts === 2);
global $wpdb;
$table = Reader_Experience::table();
$row = $wpdb->get_row(
    $wpdb->prepare('SELECT xp,detail FROM ' . $table . ' WHERE id=%d', $two['event_id']),
    ARRAY_A,
);
$verify(
    'Quota receipt explains zero XP',
    (int) $row['xp'] === 0 &&
        (json_decode($row['detail'], true)['provider_quota']['exhausted'] ?? false) === true,
);
$export = WP_XP_Core_Privacy::export('easy-activity@example.invalid');
$quota_export = array_values(
    array_filter(
        $export['data'],
        static fn($item) => $item['item_id'] === 'wp-xp-core-event-' . $two['event_id'],
    ),
);
$verify('Owner export includes the exhausted activity receipt', count($quota_export) === 1);
$export_values = array_column($quota_export[0]['data'], 'value', 'name');
$quota_snapshot = json_decode($export_values[__('Snapshot', 'wp-xp-core')], true);
$verify(
    'Owner export preserves why activity earned zero XP',
    ($quota_snapshot['provider_quota'] ?? null) === [
        'limit' => 1,
        'exhausted' => true,
        'requested_xp' => 5,
    ],
);
$verify(
    'Activity export does not reveal delivery or private source context',
    !isset($quota_snapshot['activity_channels']) &&
        !isset($quota_snapshot['event_key']) &&
        !str_contains(wp_json_encode($quota_snapshot), 'easy_channel'),
);
$clock = static fn($value, $format) => $format === 'Y-m-d' ? '2038-02-03' : $value;
add_filter('wp_date', $clock, 10, 2);
$duplicate = $event('quota-zero');
remove_filter('wp_date', $clock, 10);
$verify(
    'Cross-day duplicate never gains XP or resends channels',
    !is_wp_error($duplicate) && $duplicate['duplicate'] && $attempts === 2,
);
$rules['providers']['easy_fixture']['template'] = '参与{activity}，经验+{xp}';
$verify('Text change saves', !is_wp_error($save($rules)));
$verify(
    'Text change preserves server boundary',
    WP_XP_Core_Providers::boundary('easy_fixture') === $boundary,
);
$rules['providers']['easy_fixture']['day_limit'] = 0;
$verify('Explicit zero limit saves', !is_wp_error($save($rules)));
$created = WP_XP_Core_Providers::boundary('easy_fixture') + 1;
$verify('Zero limit stops new receipts', $error($event('stopped'), 'quota'));
$rules['providers']['easy_fixture']['day_limit'] = 2;
$verify('Quota restart saves', !is_wp_error($save($rules)));
$created = WP_XP_Core_Providers::boundary('easy_fixture') - 1;
$verify('New boundary rejects old source facts', $error($event('old-after-restart'), 'historical'));
$created = WP_XP_Core_Providers::boundary('easy_fixture') + 1;
$hold = WP_XP_Core_Reward_Controls::save(
    [
        'user_id' => $uid,
        'minutes' => 30,
        'reason' => 'Synthetic reward hold',
        'revision' => WP_XP_Core_Reward_Controls::revision($uid),
    ],
    wp_create_nonce('wp_xp_core_manage'),
);
$verify('Reward hold saves', !is_wp_error($hold));
$held = $event('held');
$verify('Hold participation still produces message', !is_wp_error($held));
$row = $wpdb->get_row(
    $wpdb->prepare('SELECT xp,detail FROM ' . $table . ' WHERE id=%d', $held['event_id']),
    ARRAY_A,
);
$detail = json_decode($row['detail'], true);
$verify(
    'Message uses actual zero XP',
    (int) $row['xp'] === 0 && str_contains($detail['activity_message']['message'], '+0'),
);
wp_set_current_user($uid);
$list = WP_XP_Core_Notifications::listing(['limit' => 50], wp_create_nonce('wp_rest'));
$verify(
    'Private inbox exposes durable activity facts',
    is_array($list) && in_array('activity', array_column($list['items'], 'type'), true),
);
$prefs = $list['preferences'];
$prefs['activity'] = false;
$verify(
    'User can opt out of activity messages',
    !is_wp_error(
        WP_XP_Core_Notifications::save(
            ['revision' => $list['revision'], 'preferences' => $prefs],
            wp_create_nonce('wp_rest'),
        ),
    ),
);
$before_attempts = $attempts;
wp_set_current_user($admin->ID);
$rules['providers']['easy_fixture']['day_limit'] = 10;
$save($rules);
$created = WP_XP_Core_Providers::boundary('easy_fixture') + 1;
$verify(
    'Opt-out event still commits',
    !is_wp_error($event('opt-out')) && $attempts === $before_attempts,
);
$fault = static function ($sql) {
    return str_contains($sql, 'SELECT meta_value FROM') && str_contains($sql, 'wp_xp_core_inbox')
        ? 'SELECT * FROM synthetic_activity_missing_table'
        : $sql;
};
$old_suppress = $wpdb->suppress_errors(true);
add_filter('query', $fault);
try {
    $blocked = false;
    try {
        WP_XP_Core_Notifications::activity_enabled($uid);
    } catch (RuntimeException $e) {
        $blocked = true;
    }
} finally {
    remove_filter('query', $fault);
    $wpdb->suppress_errors($old_suppress);
}
$verify('Preference read fault fails closed', $blocked);
$fault = static fn($sql) => str_contains($sql, 'SELECT option_value FROM') &&
str_contains($sql, WP_XP_Core_Providers::BOUNDARIES)
    ? 'SELECT * FROM synthetic_activity_missing_table'
    : $sql;
$old_suppress = $wpdb->suppress_errors(true);
add_filter('query', $fault);
try {
    $broken = $event('boundary-read-failure');
} finally {
    remove_filter('query', $fault);
    $wpdb->suppress_errors($old_suppress);
}
$verify('Boundary read fault aborts reward', $error($broken, 'storage'));
$rows = get_option(WP_XP_Core_Providers::BOUNDARIES, []);
unset($rows['easy_fixture']);
update_option(WP_XP_Core_Providers::BOUNDARIES, $rows, false);
$verify('Missing trusted boundary rejects facts', $error($event('missing-boundary'), 'historical'));
$verify(
    'Unchanged rule save initializes missing server boundary',
    !is_wp_error($save($rules)) && WP_XP_Core_Providers::boundary('easy_fixture') > 0,
);
$created = WP_XP_Core_Providers::boundary('easy_fixture') + 1;
$day_calls = 0;
$clock = static function ($value, $format) use (&$day_calls) {
    if ($format !== 'Y-m-d') {
        return $value;
    }
    return ++$day_calls === 1 ? '2038-02-04' : '2038-02-05';
};
add_filter('wp_date', $clock, 10, 2);
$midnight = $event('midnight');
remove_filter('wp_date', $clock, 10);
$verify(
    'Quota and receipt use one locked local day',
    !is_wp_error($midnight) &&
        $day_calls === 1 &&
        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT local_day FROM ' . $table . ' WHERE id=%d',
                $midnight['event_id'],
            ),
        ) === '2038-02-04',
);
$verify('Original rules restore', !is_wp_error($save($original)));
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
