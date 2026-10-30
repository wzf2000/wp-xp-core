<?php
/** Process races for provider receipts and administrator hold CAS, on owned synthetic storage. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$mode = getenv('WP_XP_TEST_MODE');
$fixture_key = 'wp_xp_core_provider_race_fixture';
global $wpdb;
if ($mode === 'prepare') {
    if (get_option($fixture_key, false) !== false) {
        throw new RuntimeException('Race subject must be fresh');
    }
    $uid = wp_insert_user([
        'user_login' => 'fixture-provider-race',
        'user_pass' => wp_generate_password(32),
        'user_email' => 'provider-race@example.invalid',
        'role' => 'subscriber',
    ]);
    if (is_wp_error($uid)) {
        throw new RuntimeException('Race user unavailable');
    }
    $rules = WP_XP_Core_Settings::rules();
    $next = $rules;
    $next['providers']['race_fixture'] = ['enabled' => true, 'xp' => 5];
    $next['providers']['quota_race'] = [
        'enabled' => true,
        'xp' => 4,
        'day_limit' => 1,
        'inbox' => true,
        'template' => '完成{activity}，本次经验+{xp}',
        'channels' => ['race_channel'],
    ];
    $next['activity_caps'] = ['day' => 0, 'week' => 0];
    if (
        WP_XP_Core_Settings::save(
            $next,
            wp_create_nonce('wp_xp_core_settings'),
            WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
        ) !== true
    ) {
        throw new RuntimeException('Race policy unavailable');
    }
    $audit = WP_XP_Core_Management::audit_history();
    if (is_wp_error($audit)) {
        throw new RuntimeException('Race audit unavailable');
    }
    $fixture = [
        'uid' => (int) $uid,
        'rules' => $rules,
        'hold_revision' => WP_XP_Core_Reward_Controls::revision((int) $uid),
        'audit_before' => count(
            array_filter($audit, static fn($row) => $row['action'] === 'reward_hold'),
        ),
    ];
    if (!add_option($fixture_key, $fixture, '', false)) {
        throw new RuntimeException('Race marker unavailable');
    }
    echo wp_json_encode(['prepared' => true]);
    return;
}
$fixture = get_option($fixture_key);
if (!is_array($fixture) || !get_userdata($fixture['uid'])) {
    throw new RuntimeException('Race marker required');
}
$uid = $fixture['uid'];
wp_xp_core_register_activity_provider('race_fixture', [
    'label' => 'Synthetic provider race',
    'authorize' => static fn($user, $object, $context) => $user === $uid && $object === 99,
    'revoke_authorize' => static fn($user, $object, $context) => $user === $uid && $object === 99,
]);
wp_xp_core_register_activity_provider('quota_race', [
    'label' => 'Quota race',
    'authorize' => static fn($user, $object) => $user === $uid && $object === 99,
]);
wp_xp_core_register_notification_channel('race_channel', [
    'label' => 'Race channel',
    'available' => static fn() => true,
    'deliver' => static function ($user, $message) {
        if (!add_option('wp_xp_quota_delivery_' . $message['event_id'], 1, '', false)) {
            throw new RuntimeException('Duplicate channel attempt');
        }
        return true;
    },
]);
if (in_array($mode, ['record', 'revoke', 'quota', 'hold'], true)) {
    $barrier = (float) getenv('WP_XP_TEST_BARRIER');
    if ($barrier < microtime(true) - 5 || $barrier > microtime(true) + 10) {
        throw new RuntimeException('Bounded race barrier required');
    }
    while (microtime(true) < $barrier) {
        usleep(10000);
    }
    $result = match ($mode) {
        'record' => wp_xp_core_record_activity('race_fixture', 'single-original', $uid, 99),
        'revoke' => wp_xp_core_revoke_activity('race_fixture', 'single-original', $uid, 99),
        'quota' => wp_xp_core_record_activity('quota_race', 'quota-' . getmypid(), $uid, 99),
        'hold' => WP_XP_Core_Reward_Controls::save(
            [
                'user_id' => $uid,
                'minutes' => 30,
                'reason' => 'Synthetic CAS race',
                'revision' => $fixture['hold_revision'],
            ],
            wp_create_nonce('wp_xp_core_manage'),
        ),
    };
    echo wp_json_encode(is_wp_error($result) ? ['error' => $result->get_error_code()] : $result);
    return;
}
if ($mode !== 'verify') {
    throw new RuntimeException('Explicit race mode required');
}
$rows = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT kind,xp FROM ' .
            Reader_Experience::table() .
            " WHERE user_id=%d AND (kind='provider_revoke' OR JSON_UNQUOTE(JSON_EXTRACT(detail,'$.provider'))='race_fixture') ORDER BY id",
        $uid,
    ),
    ARRAY_A,
);
wp_cache_delete($uid, 'user_meta');
if (
    $wpdb->last_error !== '' ||
    array_column($rows, 'kind') !== ['provider_reward', 'provider_revoke'] ||
    array_map('intval', array_column($rows, 'xp')) !== [5, -5] ||
    (int) get_user_meta($uid, Reader_Experience::balance_meta(), true) !== 4
) {
    throw new RuntimeException(
        'Provider race must append exactly one reward and one exact reversal',
    );
}
$quota = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id,xp,detail FROM ' .
            Reader_Experience::table() .
            " WHERE user_id=%d AND kind='provider_reward' AND JSON_UNQUOTE(JSON_EXTRACT(detail,'$.provider'))='quota_race' ORDER BY xp",
        $uid,
    ),
    ARRAY_A,
);
if (count($quota) !== 2 || array_map('intval', array_column($quota, 'xp')) !== [0, 4]) {
    throw new RuntimeException(
        'Concurrent daily quota must award once and persist one zero receipt',
    );
}
foreach ($quota as $row) {
    if (
        !isset(json_decode($row['detail'], true)['activity_message']) ||
        (int) get_option('wp_xp_quota_delivery_' . $row['id'], 0) !== 1
    ) {
        throw new RuntimeException(
            'Quota event must have one private message and one channel attempt',
        );
    }
}
$audit = WP_XP_Core_Management::audit_history();
if (
    is_wp_error($audit) ||
    count(array_filter($audit, static fn($row) => $row['action'] === 'reward_hold')) !==
        $fixture['audit_before'] + 1
) {
    throw new RuntimeException('Hold race must append one audit');
}
if (!WP_XP_Core_Reward_Controls::status($uid)['active']) {
    throw new RuntimeException('Winning hold missing');
}
if (
    WP_XP_Core_Settings::save(
        $fixture['rules'],
        wp_create_nonce('wp_xp_core_settings'),
        WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
    ) !== true
) {
    throw new RuntimeException('Original race policy restore failed');
}
echo wp_json_encode([
    'checks' => [
        'Exactly one provider reward',
        'Exactly one original-amount reversal',
        'No negative balance',
        'Exactly one winning hold audit',
        'Winning hold is active',
        'Original policy restored',
    ],
]);
