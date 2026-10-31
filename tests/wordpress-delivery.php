<?php
/** Optional channels retry original messages only; reward records remain immutable. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
global $wpdb;
$checks = [];
$verify = static function ($name, $value) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$uid = wp_insert_user([
    'user_login' => 'fixture-delivery',
    'user_email' => 'delivery@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Delivery subject exists', !is_wp_error($uid));
$uid = (int) $uid;
$original_rules = WP_XP_Core_Settings::rules();
$original_tasks = WP_XP_Core_Achievements::definitions();
$original_state = get_option(WP_XP_Core_Notification_Delivery::OPTION, null);
$original_legacy = get_option(WP_XP_Core_Notification_Delivery::LEGACY, null);
$save = static fn($rules) => WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$recent = static fn() => WP_XP_Core_Notification_Delivery::recent(
    wp_create_nonce('wp_xp_core_manage'),
);
$retry = static fn($input) => WP_XP_Core_Notification_Delivery::retry(
    $input,
    wp_create_nonce('wp_xp_core_manage'),
);
$find = static function ($id, $channel = 'delivery_probe') use ($recent) {
    foreach ($recent()['rows'] as $row) {
        if ($row['event_id'] === $id && $row['channel'] === $channel) {
            return $row;
        }
    }
    return null;
};
$input = static fn($row) => [
    'event_id' => $row['event_id'],
    'channel' => $row['channel'],
    'revision' => $row['revision'],
    'reason' => 'Synthetic reviewed retry',
];
$messages = [];
$mode = 'failed';
$nested_id = null;
$fanout = 0;
wp_xp_core_register_activity_provider('delivery_probe', [
    'label' => 'Original activity label',
    'authorize' => static fn($user) => $user === $uid,
]);
wp_xp_core_register_activity_provider('delivery_inner', [
    'label' => 'Nested activity',
    'authorize' => static fn($user) => $user === $uid,
]);
wp_xp_core_register_notification_channel('delivery_inner', [
    'label' => 'Nested channel',
    'available' => static fn() => true,
    'deliver' => static function ($user, $message) use (&$messages) {
        $messages[] = $message;
        return true;
    },
]);
wp_xp_core_register_notification_channel('delivery_probe', [
    'label' => 'Original channel',
    'available' => static fn() => true,
    'deliver' => static function ($user, $message) use (
        &$messages,
        &$mode,
        &$nested_id,
        &$fanout,
        $uid,
    ) {
        $messages[] = $message;
        if ($mode === 'unknown') {
            throw new RuntimeException('Synthetic uncertain external effect');
        }
        if ($mode === 'nested') {
            $event = wp_xp_core_record_activity('delivery_inner', 'nested-one', $uid);
            if (is_wp_error($event)) {
                throw new RuntimeException('Nested activity failed');
            }
            $nested_id = $event['event_id'];
            return true;
        }
        if ($mode === 'fanout') {
            for ($i = 0; $i < 51; $i++) {
                $event = wp_xp_core_record_activity('delivery_inner', 'fanout-' . $i, $uid);
                if (is_wp_error($event)) {
                    throw new RuntimeException('Nested fanout failed');
                }
                $fanout++;
            }
            return true;
        }
        return $mode === 'failed' ? new WP_Error('offline') : true;
    },
]);
$snapshot = static fn() => [
    $wpdb->get_results('SELECT * FROM ' . Reader_Experience::table() . ' ORDER BY id', ARRAY_A),
    $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
    WP_XP_Core_Settings::rules(),
    WP_XP_Core_Achievements::definitions(),
];
try {
    delete_option(WP_XP_Core_Notification_Delivery::OPTION);
    delete_option(WP_XP_Core_Notification_Delivery::LEGACY);
    $verify(
        'Reading empty channel results never creates storage',
        $recent()['rows'] === [] &&
            get_option(WP_XP_Core_Notification_Delivery::OPTION, null) === null,
    );
    $rules = $original_rules;
    $rules['providers']['delivery_probe'] = [
        'enabled' => true,
        'xp' => 0,
        'inbox' => true,
        'template' => 'Original {activity} +{xp}',
        'channels' => ['delivery_probe'],
    ];
    $rules['providers']['delivery_inner'] = [
        'enabled' => true,
        'xp' => 0,
        'channels' => ['delivery_inner'],
    ];
    $verify('Synthetic delivery rules save', $save($rules) === true);
    $event = wp_xp_core_record_activity('delivery_probe', 'original', $uid);
    $verify(
        'A failed channel never rolls back XP receipt',
        !is_wp_error($event) && $find($event['event_id'])['status'] === 'failed',
    );
    $original_message = $messages[0];
    $request = $input($find($event['event_id']));
    $before = $snapshot();
    $verify(
        'Retry nonce is required',
        $error(WP_XP_Core_Notification_Delivery::retry($request, 'bad'), 'nonce'),
    );
    wp_set_current_user($uid);
    $verify('Subscriber cannot retry activity delivery', $error($retry($request), 'forbidden'));
    $listing = WP_XP_Core_Notifications::listing([], wp_create_nonce('wp_rest'));
    $preferences = $listing['preferences'];
    $preferences['activity'] = false;
    WP_XP_Core_Notifications::save(
        ['preferences' => $preferences, 'revision' => $listing['revision']],
        wp_create_nonce('wp_rest'),
    );
    wp_set_current_user($admin->ID);
    $verify(
        'Owner activity preference blocks retry without sending',
        $error($retry($request), 'preference') && count($messages) === 1,
    );
    wp_set_current_user($uid);
    $listing = WP_XP_Core_Notifications::listing([], wp_create_nonce('wp_rest'));
    $preferences['activity'] = true;
    WP_XP_Core_Notifications::save(
        ['preferences' => $preferences, 'revision' => $listing['revision']],
        wp_create_nonce('wp_rest'),
    );
    wp_set_current_user($admin->ID);
    $deny = static fn($caps, $cap, $user, $args) => $cap === 'edit_user' &&
    ($args[0] ?? null) === $uid
        ? ['do_not_allow']
        : $caps;
    add_filter('map_meta_cap', $deny, 10, 4);
    $verify(
        'Retry enforces original receipt owner permission',
        $error($retry($request), 'forbidden'),
    );
    $verify(
        'Manage-options alone cannot read a forbidden recipient delivery revision or reason',
        !array_filter(
            $recent()['rows'],
            static fn($row) => $row['event_id'] === $event['event_id'],
        ),
    );
    remove_filter('map_meta_cap', $deny, 10);
    $verify(
        'Channel outside original snapshot cannot be substituted',
        $error($retry(array_replace($request, ['channel' => 'delivery_inner'])), 'snapshot'),
    );
    $rules['providers']['delivery_probe']['channels'] = [];
    $rules['providers']['delivery_probe']['template'] = 'Changed {activity} +{xp}';
    $save($rules);
    $providers = new ReflectionProperty(WP_XP_Core_Providers::class, 'providers');
    $providers->setAccessible(true);
    $registered = $providers->getValue();
    $missing = $registered;
    unset($missing['delivery_probe']);
    $providers->setValue(null, $missing);
    $mode = 'delivered';
    $before = $snapshot();
    try {
        $result = $retry($request);
    } finally {
        $providers->setValue(null, $registered);
    }
    $verify(
        'Missing source can resend original snapshot without validating activity',
        !is_wp_error($result) &&
            $result['status'] === 'delivered' &&
            $messages[1] === $original_message,
    );
    $verify(
        'Retry preserves all ledger rows, user state and reward rules',
        $snapshot() === $before,
    );
    $verify(
        'Successful message cannot be resent',
        $error($retry($request), 'state') && count($messages) === 2,
    );
    $rules['providers']['delivery_probe']['channels'] = ['delivery_probe'];
    $save($rules);
    $mode = 'unknown';
    $uncertain = wp_xp_core_record_activity('delivery_probe', 'uncertain', $uid);
    $verify(
        'External exception leaves durable unknown state',
        $find($uncertain['event_id'])['status'] === 'unknown',
    );
    $verify(
        'Unknown delivery cannot be retried',
        $error($retry($input($find($uncertain['event_id']))), 'state'),
    );
    $mode = 'failed';
    $legacy = wp_xp_core_record_activity('delivery_probe', 'legacy', $uid);
    // Keep the old result format intact, while removing only this synthetic new attempt.
    $state = get_option(WP_XP_Core_Notification_Delivery::OPTION);
    unset($state['entries'][hash('sha256', $legacy['event_id'] . ':delivery_probe')]);
    update_option(WP_XP_Core_Notification_Delivery::OPTION, $state, false);
    update_option(
        WP_XP_Core_Notification_Delivery::LEGACY,
        [
            [
                'event_id' => $legacy['event_id'],
                'channel' => 'delivery_probe',
                'code' => 'offline',
                'time' => time(),
            ],
        ],
        false,
    );
    $legacy_raw = get_option(WP_XP_Core_Notification_Delivery::LEGACY);
    $legacy_row = $find($legacy['event_id']);
    $verify(
        'Old bounded failure rows remain readable without migration',
        $legacy_row['status'] === 'failed' &&
            get_option(WP_XP_Core_Notification_Delivery::LEGACY) === $legacy_raw,
    );
    $channels = new ReflectionProperty(WP_XP_Core_Activity_Notifications::class, 'channels');
    $channels->setAccessible(true);
    $registered_channels = $channels->getValue();
    $missing_channels = $registered_channels;
    unset($missing_channels['delivery_probe']);
    $channels->setValue(null, $missing_channels);
    try {
        $verify('Missing channel blocks retry', $error($retry($input($legacy_row)), 'unavailable'));
    } finally {
        $channels->setValue(null, $registered_channels);
    }
    $mode = 'delivered';
    $verify(
        'Legacy explicit failure can be adopted only by explicit retry',
        $retry($input($legacy_row))['status'] === 'delivered' &&
            get_option(WP_XP_Core_Notification_Delivery::LEGACY) === $legacy_raw,
    );
    $rules['providers']['delivery_probe']['xp'] = 2;
    $rules['providers']['delivery_inner']['xp'] = 3;
    $rules['activity_caps'] = ['day' => 0, 'week' => 0];
    $save($rules);
    $mode = 'nested';
    $before_messages = count($messages);
    $outer = wp_xp_core_record_activity('delivery_probe', 'nested-outer', $uid);
    $verify(
        'Nested post-commit delivery preserves both terminal states',
        !is_wp_error($outer) &&
            $find($outer['event_id'])['status'] === 'delivered' &&
            $find($nested_id, 'delivery_inner')['status'] === 'delivered' &&
            count($messages) === $before_messages + 2,
    );
    WP_XP_Core_Activity_Notifications::deliver([
        'id' => $outer['event_id'],
        'detail' => ['activity_channels' => ['delivery_probe']],
    ]);
    WP_XP_Core_Activity_Notifications::deliver([
        'id' => $nested_id,
        'detail' => ['activity_channels' => ['delivery_inner']],
    ]);
    $verify(
        'Nested event replay does not resend either channel',
        count($messages) === $before_messages + 2,
    );
    $verify(
        'Nested callbacks retain exactly their two original XP receipts',
        (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Reader_Experience::table() . ' WHERE id IN (%d,%d)',
                $outer['event_id'],
                $nested_id,
            ),
        ) === 2 &&
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT SUM(xp) FROM ' . Reader_Experience::table() . ' WHERE id IN (%d,%d)',
                    $outer['event_id'],
                    $nested_id,
                ),
            ) === 5,
    );
    $mode = 'fanout';
    $evicted = wp_xp_core_record_activity('delivery_probe', 'evicted-outer', $uid);
    $before_messages = count($messages);
    WP_XP_Core_Activity_Notifications::deliver([
        'id' => $evicted['event_id'],
        'detail' => ['activity_channels' => ['delivery_probe']],
    ]);
    $verify(
        'Inner dispatch eviction cannot resurrect outer attempt',
        $fanout === 51 &&
            $find($evicted['event_id']) === null &&
            count($messages) === $before_messages &&
            count(get_option(WP_XP_Core_Notification_Delivery::OPTION)['entries']) === 50,
    );
    $rules['providers']['delivery_probe']['channels'] = ['delivery_probe', 'delivery_inner'];
    $save($rules);
    $mode = 'failed';
    $retained = wp_xp_core_record_activity('delivery_probe', 'retained-failure', $uid);
    $verify(
        'One event can retain independent failed and successful channel states',
        $find($retained['event_id'])['status'] === 'failed' &&
            $find($retained['event_id'], 'delivery_inner')['status'] === 'delivered',
    );
    $higher = wp_xp_core_record_activity('delivery_inner', 'higher-id-before-retry', $uid);
    $retry($input($find($retained['event_id'])));
    for ($i = 0; $i < 49; $i++) {
        wp_xp_core_record_activity('delivery_inner', 'retained-window-' . $i, $uid);
    }
    $window = get_option(WP_XP_Core_Notification_Delivery::OPTION);
    $retained_row = $find($retained['event_id']);
    $mode = 'delivered';
    $verify(
        'Eviction high-water mark never blocks a retained explicit failure',
        $window['closed_through'] >= $higher['event_id'] &&
            $retained_row['status'] === 'failed' &&
            $retry($input($retained_row))['status'] === 'delivered',
    );
    $task_saved = WP_XP_Core_Achievements::save(
        [
            'id' => '',
            'title' => 'Delivery observer isolation',
            'description' => '',
            'icon' => '◇',
            'type' => 'automatic',
            'period' => 'once',
            'enabled' => true,
            'reward_xp' => 0,
            'steps' => [
                ['kind' => 'provider_reward', 'provider_id' => 'delivery_probe', 'count' => 1],
            ],
        ],
        wp_create_nonce('wp_xp_core_achievements'),
        WP_XP_Core_Achievements::revision(),
    );
    $verify('Observer isolation task exists', !is_wp_error($task_saved));
    $fault = static fn($sql) => str_contains(
        $sql,
        "WHERE option_name='wp_xp_core_channel_delivery'",
    )
        ? 'SELECT * FROM synthetic_missing_delivery_state'
        : $sql;
    $suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $fault);
    $observer = 0;
    $observe = static function ($event) use (&$observer) {
        if ($event['kind'] === 'provider_reward') {
            $observer++;
        }
    };
    add_action('wp_xp_core_event_committed', $observe, 99);
    try {
        $isolated = wp_xp_core_record_activity('delivery_probe', 'storage-failure', $uid);
    } finally {
        remove_filter('query', $fault);
        $wpdb->suppress_errors($suppressed);
        remove_action('wp_xp_core_event_committed', $observe, 99);
    }
    $verify(
        'Post-commit storage failure preserves receipt and subsequent observers',
        !is_wp_error($isolated) && $observer === 1,
    );
    $verify(
        'Channel storage failure cannot hide the automatic task observer',
        WP_XP_Core_Achievements::progress(
            WP_XP_Core_Achievements::definitions()[$task_saved['id']],
            $uid,
        )['earned'],
    );
} finally {
    wp_set_current_user($admin->ID);
    $save($original_rules);
    update_option(WP_XP_Core_Achievements::OPTION, $original_tasks, false);
    WP_XP_Core_Achievements::clear_cache();
    foreach (
        [
            WP_XP_Core_Notification_Delivery::OPTION => $original_state,
            WP_XP_Core_Notification_Delivery::LEGACY => $original_legacy,
        ]
        as $option => $value
    ) {
        if ($value === null) {
            delete_option($option);
        } else {
            update_option($option, $value, false);
        }
    }
}
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
