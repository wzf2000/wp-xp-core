<?php
/** Dedicated-process notification retries and an interrupted external delivery. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
global $wpdb;
$mode = getenv('WP_XP_TEST_MODE');
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$key = 'wp_xp_core_delivery_race_fixture';
$fixture = get_option($key, null);
wp_xp_core_register_notification_channel('delivery_race', [
    'label' => 'Synthetic delivery race',
    'available' => static fn() => true,
    'deliver' => static function ($uid, $message) use ($mode) {
        if ($mode === 'prepare') {
            return new WP_Error('offline');
        }
        $marker =
            $mode === 'interrupt'
                ? 'wp_xp_delivery_interrupt_attempt'
                : 'wp_xp_delivery_race_attempt';
        if (!add_option($marker, 1, '', false)) {
            throw new RuntimeException('Duplicate external attempt');
        }
        if ($mode === 'interrupt') {
            echo wp_json_encode(['interrupted' => true]);
            exit(0);
        }
        usleep(200000);
        return true;
    },
]);
$snapshot = static function ($uid): array {
    global $wpdb;
    return [
        'ledger' => $wpdb->get_results(
            'SELECT * FROM ' . Reader_Experience::table() . ' ORDER BY id',
            ARRAY_A,
        ),
        'users' => $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
        'rules' => WP_XP_Core_Settings::rules(),
        'tasks' => WP_XP_Core_Achievements::definitions(),
    ];
};
if ($mode === 'prepare') {
    if ($fixture !== null) {
        throw new RuntimeException('Fresh notification race fixture required');
    }
    $uid = wp_insert_user([
        'user_login' => 'fixture-delivery-race',
        'user_pass' => wp_generate_password(32),
        'user_email' => 'delivery-race@example.invalid',
        'role' => 'subscriber',
    ]);
    if (is_wp_error($uid)) {
        throw new RuntimeException('Fresh delivery subject required');
    }
    $uid = (int) $uid;
    wp_xp_core_register_activity_provider('delivery_race', [
        'label' => 'Delivery race activity',
        'authorize' => static fn($user) => $user === $uid,
    ]);
    $original = WP_XP_Core_Settings::rules();
    $rules = $original;
    $rules['providers']['delivery_race'] = [
        'enabled' => true,
        'xp' => 0,
        'channels' => ['delivery_race'],
    ];
    if (
        WP_XP_Core_Settings::save(
            $rules,
            wp_create_nonce('wp_xp_core_settings'),
            WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
        ) !== true
    ) {
        throw new RuntimeException('Delivery race rules failed');
    }
    $events = [];
    foreach (['retry', 'interrupt'] as $operation) {
        $event = wp_xp_core_record_activity('delivery_race', $operation, $uid);
        if (is_wp_error($event)) {
            throw new RuntimeException('Delivery race seed failed');
        }
        $events[$operation] = $event['event_id'];
    }
    $requests = [];
    foreach (
        WP_XP_Core_Notification_Delivery::recent(wp_create_nonce('wp_xp_core_manage'))['rows']
        as $row
    ) {
        $operation = array_search($row['event_id'], $events, true);
        if ($operation !== false) {
            $requests[$operation] = [
                'event_id' => $row['event_id'],
                'channel' => $row['channel'],
                'revision' => $row['revision'],
                'reason' => 'Synthetic concurrent delivery',
            ];
        }
    }
    if (count($requests) !== 2) {
        throw new RuntimeException('Explicit failed delivery receipts required');
    }
    add_option(
        $key,
        [
            'uid' => $uid,
            'original' => $original,
            'events' => $events,
            'requests' => $requests,
            'snapshot' => $snapshot($uid),
        ],
        '',
        false,
    );
    echo wp_json_encode(['prepared' => true]);
    return;
}
if (!is_array($fixture)) {
    throw new RuntimeException('Prepared delivery race required');
}
if ($mode === 'retry' || $mode === 'interrupt') {
    if ($mode === 'retry') {
        $barrier = (float) getenv('WP_XP_TEST_BARRIER');
        if ($barrier < microtime(true) - 5 || $barrier > microtime(true) + 10) {
            throw new RuntimeException('Bounded retry barrier required');
        }
        while (microtime(true) < $barrier) {
            usleep(10000);
        }
    }
    $result = WP_XP_Core_Notification_Delivery::retry(
        $fixture['requests'][$mode],
        wp_create_nonce('wp_xp_core_manage'),
    );
    echo wp_json_encode(is_wp_error($result) ? ['error' => $result->get_error_code()] : $result);
    return;
}
if ($mode !== 'verify') {
    throw new RuntimeException('Explicit delivery fixture mode required');
}
$rows = WP_XP_Core_Notification_Delivery::recent(wp_create_nonce('wp_xp_core_manage'))['rows'];
$states = [];
foreach ($rows as $row) {
    if (in_array($row['event_id'], $fixture['events'], true)) {
        $states[$row['event_id']] = $row['status'];
    }
}
if (
    ($states[$fixture['events']['retry']] ?? '') !== 'delivered' ||
    ($states[$fixture['events']['interrupt']] ?? '') !== 'unknown' ||
    (int) get_option('wp_xp_delivery_race_attempt') !== 1 ||
    (int) get_option('wp_xp_delivery_interrupt_attempt') !== 1
) {
    throw new RuntimeException(
        'Concurrent retry must attempt once and interrupted delivery must remain unknown',
    );
}
foreach ($fixture['requests'] as $request) {
    $result = WP_XP_Core_Notification_Delivery::retry(
        $request,
        wp_create_nonce('wp_xp_core_manage'),
    );
    if (!is_wp_error($result) || $result->get_error_code() !== 'state') {
        throw new RuntimeException('Delivered and interrupted attempts cannot replay');
    }
}
if ($snapshot($fixture['uid']) !== $fixture['snapshot']) {
    throw new RuntimeException(
        'Notification retries must preserve the entire ledger, all user state and rules',
    );
}
WP_XP_Core_Settings::save(
    $fixture['original'],
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
echo wp_json_encode([
    'checks' => [
        'Concurrent administrators make one external attempt',
        'Interrupted external attempt remains unknown',
        'Delivered and unknown attempts cannot replay',
        'All ledger rows and user state remain unchanged',
        'Original reward rules restored',
    ],
]);
