<?php
/** Independent applications of the same preview serialize and reject the stale second revision. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
$admin = get_user_by('login', 'fixture-author');
if (!$admin || !Reader_Experience::ready()) {
    throw new RuntimeException('Prepared synthetic administrator required');
}
wp_set_current_user($admin->ID);
global $wpdb;
$option = 'wp_xp_core_test_configuration_concurrency';
$mode = getenv('WP_XP_TEST_MODE');
if ($mode === 'prepare') {
    $nonce = wp_create_nonce('wp_xp_core_configuration');
    $doc = WP_XP_Core_Configuration::export($nonce);
    if (is_wp_error($doc) || !$doc['tasks']) {
        throw new RuntimeException('Portable tasks required');
    }
    $doc['tasks'][0]['title'] = 'Synthetic concurrent display update';
    $json = wp_json_encode($doc, JSON_UNESCAPED_UNICODE);
    $plan = WP_XP_Core_Configuration::preview($json, 'update', $nonce);
    if (is_wp_error($plan)) {
        throw new RuntimeException('Concurrent preview failed');
    }
    update_option(
        $option,
        [
            'json' => $json,
            'plan' => $plan,
            'tasks' => WP_XP_Core_Achievements::definitions(),
            'target' => $doc['tasks'][0]['id'],
            'events' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()),
        ],
        false,
    );
    echo wp_json_encode(['checks' => ['Concurrent preview prepared']]) . "\n";
} elseif ($mode === 'verify') {
    $fixture = get_option($option);
    $tasks = WP_XP_Core_Achievements::definitions();
    if (
        !is_array($fixture) ||
        $tasks[$fixture['target']]['title'] !== 'Synthetic concurrent display update' ||
        (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) !==
            $fixture['events']
    ) {
        throw new RuntimeException('Concurrent import did not preserve ledger');
    }
    foreach ($fixture['tasks'] as $id => $task) {
        if (
            $task['version'] !== $tasks[$id]['version'] ||
            $task['start_event_id'] !== $tasks[$id]['start_event_id']
        ) {
            throw new RuntimeException('Display import reset an earned identity');
        }
    }
    delete_option($option);
    echo wp_json_encode([
        'checks' => [
            'Concurrent import applies one display change',
            'Concurrent import leaves versions, boundaries and ledger unchanged',
        ],
    ]) . "\n";
} else {
    $fixture = get_option($option);
    if (!is_array($fixture)) {
        throw new RuntimeException('Prepared preview required');
    }
    $barrier = (float) getenv('WP_XP_TEST_BARRIER');
    while (microtime(true) < $barrier) {
        usleep(10000);
    }
    $result = WP_XP_Core_Configuration::apply(
        $fixture['json'],
        'update',
        $fixture['plan']['revision'],
        $fixture['plan']['proof'],
        wp_create_nonce('wp_xp_core_configuration'),
    );
    if (is_wp_error($result) && $result->get_error_code() !== 'conflict') {
        throw new RuntimeException(
            'Unexpected concurrent import error: ' . $result->get_error_code(),
        );
    }
    echo wp_json_encode(['result' => is_wp_error($result) ? 'conflict' : 'applied']) . "\n";
}
