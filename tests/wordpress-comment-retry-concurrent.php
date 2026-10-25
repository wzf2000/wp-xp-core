<?php
/** Independent retries of one captured compensation share the authoritative request key. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required.');
}
$fixture = get_option('wp_xp_core_test_comment_retry_concurrency');
$admin = get_user_by('login', 'fixture-author');
if (!is_array($fixture) || !$admin || !Reader_Experience::ready()) {
    throw new RuntimeException('Prepared compensation fixture required.');
}
wp_set_current_user($admin->ID);
global $wpdb;
if (getenv('WP_XP_TEST_MODE') === 'verify') {
    wp_cache_delete($fixture['user_id'], 'user_meta');
    $balance = (int) get_user_meta($fixture['user_id'], Reader_Experience::balance_meta(), true);
    $net = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT SUM(xp) FROM ' .
                Reader_Experience::table() .
                " WHERE object_id=%d AND user_id=%d AND kind IN ('comment','comment_adjust')",
            $fixture['comment_id'],
            $fixture['user_id'],
        ),
    );
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
            'manage:' . $fixture['request_id'],
        ),
    );
    if ($balance !== 0 || $net !== 0 || $count !== 1) {
        throw new RuntimeException('Concurrent compensation must reconcile exactly once.');
    }
    delete_option('wp_xp_core_test_comment_retry_concurrency');
    echo wp_json_encode([
        'checks' => [
            'Concurrent deleted-comment compensation changes XP once',
            'Concurrent retry has one request receipt',
        ],
    ]) . "\n";
} else {
    $barrier = (float) getenv('WP_XP_TEST_BARRIER');
    while (microtime(true) < $barrier) {
        usleep(10000);
    }
    $result = WP_XP_Core_Management::retry(
        [
            'failure_id' => $fixture['failure_id'],
            'request_id' => $fixture['request_id'],
            'reason' => 'Synthetic concurrent recheck original deleted comment',
        ],
        wp_create_nonce('wp_xp_core_manage'),
    );
    if (is_wp_error($result)) {
        throw new RuntimeException(
            'Concurrent compensation retry failed: ' . $result->get_error_code(),
        );
    }
    echo wp_json_encode([
        'checks' => ['Concurrent controlled retry succeeds'],
        'duplicate' => $result['duplicate'],
    ]) . "\n";
}
