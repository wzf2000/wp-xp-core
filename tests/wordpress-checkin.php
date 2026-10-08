<?php
/** One authenticated synthetic client, launched twice in independent WordPress processes. */
if (
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic WordPress installation required.');
}
$reader = get_user_by('login', 'fixture-concurrent');
if (!$reader || !Reader_Experience::ready()) {
    throw new RuntimeException('Prepared native installation required.');
}
wp_set_current_user($reader->ID);
$barrier = (float) getenv('WP_XP_TEST_BARRIER');
while (microtime(true) < $barrier) {
    usleep(10000);
}
$request = new WP_REST_Request('POST', '/reader-experience/v1/checkin');
$request->set_header('Content-Type', 'application/json');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$request->set_body('{}');
$response = rest_do_request($request);
if ($response->get_status() !== 200) {
    throw new RuntimeException('Authenticated concurrent check-in failed.');
}
echo wp_json_encode(['checks' => ['Authenticated concurrent check-in succeeds']]) . "\n";
