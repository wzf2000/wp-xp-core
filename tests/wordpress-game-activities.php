<?php
/** Optional real replay/settlement bridge, restricted to a marked synthetic local game lab. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local' ||
    !function_exists('site_tools_game_settled_receipt') ||
    !function_exists('site_tools_register_game_settled_observer')
) {
    throw new RuntimeException('Owned local WordPress with candidate game pack required');
}
$owner_path = realpath((string) getenv('WP_XP_TEST_OWNER_FILE'));
$owner = $owner_path ? json_decode(file_get_contents($owner_path), true) : null;
if (
    !$owner_path ||
    !str_starts_with($owner_path, '/tmp/') ||
    !is_array($owner) ||
    ($owner['token'] ?? null) !== get_option('wp_xp_core_test_owner') ||
    realpath($owner['site'] ?? '') !== realpath(ABSPATH)
) {
    throw new RuntimeException('Matching local owner marker required');
}
$root = realpath((string) getenv('WP_XP_GAME_FIXTURE_ROOT'));
if (
    !$root ||
    is_link($root . '/tests/replay-fixture.cjs') ||
    !is_file($root . '/tests/replay-fixture.cjs')
) {
    throw new RuntimeException('Explicit maintained game fixture root required');
}
$installed = dirname(dirname((new ReflectionClass(\SiteTools\Games::class))->getFileName()));
foreach (['game-core.js', 'game-engine-2048.js'] as $asset) {
    if (
        !is_file($root . '/assets/' . $asset) ||
        hash_file('sha256', $root . '/assets/' . $asset) !==
            hash_file('sha256', $installed . '/assets/' . $asset)
    ) {
        throw new RuntimeException('Replay fixture assets differ from installed game pack');
    }
}
$checks = [];
$verify = static function ($label, $ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
\SiteTools\Games::install();
$uid = wp_insert_user([
    'user_login' => 'fixture-game-activity',
    'user_email' => 'game-activity@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Fresh game subject', !is_wp_error($uid));
$uid = (int) $uid;
$id = 'game_' . substr(hash('sha256', '2048'), 0, 20);
$verify('Actual registered game provider exists', isset(WP_XP_Core_Providers::definitions()[$id]));
$original = WP_XP_Core_Settings::rules();
$rules = $original;
$rules['activity_caps'] = ['day' => 0, 'week' => 0];
$save = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$pipes = [];
$process = proc_open(
    ['/usr/bin/node', $root . '/tests/replay-fixture.cjs', '2048'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (!is_resource($process)) {
    throw new RuntimeException('Replay fixture unavailable');
}
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('Synthetic replay generation failed');
}
$proof = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
$verify('Shipped engine produced completed transcript', ($proof['over'] ?? false) === true);
$request = static function ($action, array $data) {
    $r = new WP_REST_Request('POST', '/easy-games/v1/games/' . $action);
    $r->set_header('Content-Type', 'application/json');
    $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $r->set_body(wp_json_encode($data));
    return rest_do_request($r);
};
global $wpdb;
$runs = \SiteTools\Games::table();
$start = static function () use ($request, $uid, $wpdb, $runs, $proof) {
    wp_set_current_user($uid);
    $response = $request('start', ['slug' => '2048', 'request_id' => wp_generate_uuid4()]);
    if ($response->get_status() !== 200) {
        throw new RuntimeException('Actual game start route failed');
    }
    $run = $response->get_data()['run'];
    // Explicit synthetic replay fixture: fixed engine seed and elapsed wall time, not human play.
    $created = (int) floor(microtime(true) * 1000) - $proof['input']['elapsed'] - 1000;
    if (
        $wpdb->update(
            $runs,
            ['seed' => $proof['input']['seed'], 'created_ms' => $created],
            ['public_id' => $run, 'user_id' => $uid],
        ) === false
    ) {
        throw new RuntimeException('Owned replay clock setup failed');
    }
    $v = array_intersect_key($proof['input'], array_flip(['events', 'elapsed', 'frames']));
    $v['run'] = $run;
    return [$v, $created];
};
$lock_released = [];
site_tools_register_game_settled_observer('test_throw', static function () {
    throw new RuntimeException('Synthetic observer failure');
});
site_tools_register_game_settled_observer('test_lock', static function ($run, $user) use (
    &$lock_released,
    $wpdb,
) {
    $lock_released[$run] =
        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT IS_USED_LOCK(%s)',
                \SiteTools\Profile::get('games.scoring_lock'),
            ),
        ) === null;
});
[$disabled, $created] = $start();
$disabled_result = $request('finish', $disabled);
$verify(
    'Default-disabled XP never blocks verified scores',
    $disabled_result->get_status() === 200 &&
        ($disabled_result->get_data()['verified'] ?? false) &&
        $disabled_result->get_data()['awards'] === [],
);
$balance = static function () use ($uid) {
    wp_cache_delete($uid, 'user_meta');
    return (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
};
$verify('Disabled activity does not award XP', $balance() === 0);
wp_set_current_user($admin->ID);
$rules['providers'][$id] = [
    'enabled' => true,
    'xp' => 6,
    'day_limit' => 1,
    'min_score' => 0,
    'inbox' => true,
    'template' => '完成{activity}，本次经验+{xp}',
    'channels' => ['game_failure'],
];
$verify('Game reward rule saves', !is_wp_error($save($rules)));
wp_xp_core_register_notification_channel('game_failure', [
    'label' => 'Synthetic game delivery',
    'available' => static fn() => true,
    'deliver' => static function () {
        throw new RuntimeException('Synthetic delivery failure');
    },
]);
[$v, $created] = $start();
// Move only this synthetic provider boundary before the simulated completed run.
$boundaries = get_option(WP_XP_Core_Providers::BOUNDARIES, []);
$boundaries[$id]['starts_ms'] = $created - 1;
update_option(WP_XP_Core_Providers::BOUNDARIES, $boundaries, false);
$result = $request('finish', $v);
$verify(
    'Real replay commits XP while failed notifications cannot fail score',
    $result->get_status() === 200 && $result->get_data()['verified'] && $balance() === 6,
);
$verify(
    'All settlement observers run after shared lock release',
    ($lock_released[$v['run']] ?? false) === true,
);
$receipt = site_tools_game_settled_receipt($v['run'], $uid);
$verify(
    'Public server receipt is verified and owner-bound',
    is_array($receipt) && $receipt['verified'] && $receipt['score'] === $proof['score'],
);
$foreign = site_tools_game_settled_receipt($v['run'], $admin->ID);
$verify('Foreign receipt is refused', is_wp_error($foreign));
$forged = wp_xp_core_record_activity($id, 'different-event', $uid, 0, [
    'run' => $v['run'],
    'score' => 999999,
]);
$verify(
    'Changing event cannot mint a second reward',
    is_wp_error($forged) && $forged->get_error_code() === 'ineligible',
);
$fake = $v;
$fake['score'] = 999999;
$verify(
    'Actual finish route refuses browser score field',
    $request('finish', $fake)->get_status() === 400,
);
$retry = $request('finish', $v);
$verify(
    'Same proof retries keep exact score response and XP',
    $retry->get_status() === 200 && $retry->get_data() === $result->get_data() && $balance() === 6,
);
[$quota, $created] = $start();
$boundaries[$id]['starts_ms'] = $created - 1;
update_option(WP_XP_Core_Providers::BOUNDARIES, $boundaries, false);
$verify(
    'Exhausted quota never rejects verified score',
    $request('finish', $quota)->get_status() === 200 && $balance() === 6,
);
$clock = static fn($value, $format) => $format === 'Y-m-d' ? '2038-03-02' : $value;
add_filter('wp_date', $clock, 10, 2);
$again = $request('finish', $quota);
remove_filter('wp_date', $clock, 10);
$verify(
    'Next-day same proof cannot backfill exhausted XP',
    $again->get_status() === 200 && $balance() === 6,
);
wp_set_current_user($admin->ID);
$rules['providers'][$id]['day_limit'] = 0;
$save($rules);
wp_set_current_user($uid);
$verify(
    'Changed rules do not backfill old settled runs',
    $request('finish', $disabled)->get_status() === 200 && $balance() === 6,
);
wp_set_current_user($admin->ID);
$verify('Original rules restored', !is_wp_error($save($original)));
echo wp_json_encode([
    'checks' => $checks,
    'count' => count($checks),
    'fixture_timing' => 'Synthetic engine seed and elapsed wall time; no claim of human play',
]);
