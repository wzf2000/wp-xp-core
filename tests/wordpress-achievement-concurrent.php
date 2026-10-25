<?php
/** Two real independent administrator requests share a task cycle and request UUID. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required.');
}
$fixture = get_option('wp_xp_core_test_achievement_concurrency');
$admin = get_user_by('login', 'fixture-author');
if (!is_array($fixture) || !$admin || !Reader_Experience::ready()) {
    throw new RuntimeException('Owned reward fixture required.');
}
wp_set_current_user($admin->ID);
$clock = static fn($date, $format) => $format === 'Y-m-d' ? '2033-06-15' : $date;
add_filter('wp_date', $clock, 10, 2);
global $wpdb;
if (getenv('WP_XP_TEST_MODE') === 'verify') {
    wp_cache_delete($fixture['user_id'], 'user_meta');
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
            'task:' . $fixture['task_id'] . ':1:2033-06:' . $fixture['user_id'],
        ),
    );
    $balance = (int) get_user_meta($fixture['user_id'], Reader_Experience::balance_meta(), true);
    if ($count !== 1 || $balance !== $fixture['before'] + 5) {
        throw new RuntimeException('Concurrent achievement must award exactly once.');
    }
    delete_option('wp_xp_core_test_achievement_concurrency');
    echo wp_json_encode([
        'checks' => [
            'Concurrent task grant has one canonical event',
            'Concurrent task grant increments balance once',
        ],
    ]) . "\n";
} else {
    $barrier = (float) getenv('WP_XP_TEST_BARRIER');
    while (microtime(true) < $barrier) {
        usleep(10000);
    }
    $input = array_intersect_key($fixture, array_flip(['user_id', 'task_id', 'request_id'])) + [
        'operation' => 'grant',
        'version' => 1,
        'period' => '2033-06',
        'reason' => 'Synthetic concurrent independent verification',
    ];
    $result = WP_XP_Core_Achievements::manage($input, wp_create_nonce('wp_xp_core_achievements'));
    if (is_wp_error($result)) {
        throw new RuntimeException('Concurrent grant failed: ' . $result->get_error_code());
    }
    echo wp_json_encode([
        'checks' => ['Concurrent administrator grant succeeds'],
        'duplicate' => $result['duplicate'],
    ]) . "\n";
}
remove_filter('wp_date', $clock, 10);
