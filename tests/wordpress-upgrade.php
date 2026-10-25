<?php
/** Public-release upgrade checks, exclusively in the runner's owned synthetic site. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated local upgrade database required.');
}
$site = realpath(ABSPATH);
$owner_file = getenv('WP_XP_TEST_OWNER_FILE');
if (
    !$site ||
    !str_starts_with($site, '/tmp/') ||
    $owner_file !== dirname($site) . '/.wp-xp-core-test-owner' ||
    is_link($owner_file) ||
    !is_readable($owner_file) ||
    !preg_match('~^(127\.0\.0\.1:[0-9]+|localhost:/tmp/[^\x00]+)$~D', DB_HOST)
) {
    throw new RuntimeException('Owned temporary loopback upgrade fixture required.');
}
$owner = json_decode(file_get_contents($owner_file), true);
if (
    !is_array($owner) ||
    ($owner['site'] ?? '') !== $site ||
    ($owner['database'] ?? '') !== DB_NAME ||
    !is_string($owner['token'] ?? null) ||
    !hash_equals((string) get_option('wp_xp_core_test_owner', ''), $owner['token'])
) {
    throw new RuntimeException('Upgrade fixture ownership mismatch.');
}
$checks = [];
$verify = static function (string $label, $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$mode = getenv('WP_XP_TEST_MODE');
$verify('Upgrade phase is explicit', in_array($mode, ['seed', 'verify', 'restart'], true));
$state_file = dirname($site) . '/upgrade-state.json';
$verify('Upgrade state is never a symlink', !is_link($state_file));
$admin = get_user_by('login', 'fixture-author');
$reader = get_user_by('login', 'fixture-reader');
$verify('Upgrade synthetic subjects exist', $admin && $reader);
$day = '2034-01-10';
$clock = static function ($date, $format) use (&$day) {
    return $format === 'Y-m-d' ? $day : $date;
};
add_filter('wp_date', $clock, 10, 2);
$checkin = static function ($nonce = null) {
    $request = new WP_REST_Request('POST', '/reader-experience/v1/checkin');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body('{}');
    if ($nonce !== null) {
        $request->set_header('X-WP-Nonce', $nonce);
    }
    return rest_do_request($request);
};
global $wpdb;
$table = Reader_Experience::table();
$digest = static fn($value) => hash('sha256', wp_json_encode($value));
$snapshot = static function (int $page) use ($wpdb, $table, $digest): array {
    $read = static function ($sql) use ($wpdb) {
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Upgrade snapshot query failed.');
        }
        return $rows;
    };
    $options = $read(
        "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE " .
            $wpdb->prepare(
                'option_name LIKE %s OR option_name LIKE %s',
                $wpdb->esc_like('reader_experience_') . '%',
                $wpdb->esc_like('wp_xp_core_') . '%',
            ) .
            ' ORDER BY option_name',
    );
    $indices = array_map(
        static fn($row) => array_diff_key($row, ['Cardinality' => true]),
        $read('SHOW INDEX FROM ' . $table),
    );
    return [
        'ledger' => $digest($read('SELECT * FROM ' . $table . ' ORDER BY id')),
        'balance_and_user_state' => $digest(
            $read("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id"),
        ),
        'plugin_options_and_schema_marker' => $digest($options),
        'ledger_schema' => $digest([$read('SHOW FULL COLUMNS FROM ' . $table), $indices]),
        'manual_panel_page' => $digest(
            $read($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d", $page)),
        ),
        'cron' => $digest(get_option('cron')),
    ];
};
$save_state = static function (array $state) use ($state_file): void {
    if (file_put_contents($state_file, wp_json_encode($state, JSON_PRETTY_PRINT)) === false) {
        throw new RuntimeException('Upgrade fixture state could not be saved.');
    }
};
wp_set_current_user($admin->ID);
$verify('Existing activated installation is ready', Reader_Experience::ready());
if ($mode === 'seed') {
    $verify('Old state is seeded only once', !file_exists($state_file));
    $rules = WP_XP_Core_Settings::rules();
    $rules['checkin_xp'] = 7;
    $verify(
        'Old release saves nondefault rules',
        WP_XP_Core_Settings::save(
            $rules,
            wp_create_nonce('wp_xp_core_settings'),
            WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
        ) === true,
    );
    $page = wp_insert_post(
        [
            'post_title' => 'Synthetic retained upgrade experience',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '[reader_experience]',
        ],
        true,
    );
    $verify('Old release creates a manual panel page', !is_wp_error($page) && $page > 0);
    $verify(
        'Old release saves the manual panel setting',
        WP_XP_Core_Settings::save_panel_page(
            (string) $page,
            wp_create_nonce('wp_xp_core_panel'),
            WP_XP_Core_Settings::panel_revision(
                get_option(WP_XP_Core_Settings::PANEL_OPTION, null),
            ),
        ) === true,
    );
    wp_set_current_user($reader->ID);
    $verify(
        'Old release awards synthetic checkin',
        $checkin(wp_create_nonce('wp_rest'))->get_status() === 200,
    );
    $verify(
        'Old checkin uses the nondefault reward',
        (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 7,
    );
    $has_tasks = class_exists('WP_XP_Core_Achievements');
    if ($has_tasks) {
        wp_set_current_user($admin->ID);
        $saved = WP_XP_Core_Achievements::save(
            [
                'id' => '',
                'title' => 'Retained upgrade honor snapshot',
                'description' => 'Synthetic release-upgrade honor',
                'icon' => '★',
                'type' => 'review',
                'period' => 'once',
                'enabled' => true,
                'steps' => [],
            ],
            wp_create_nonce('wp_xp_core_achievements'),
            WP_XP_Core_Achievements::revision(),
        );
        $verify('Old task API saves a synthetic honor', !is_wp_error($saved));
        $task = WP_XP_Core_Achievements::definitions()[$saved['id']];
        $granted = WP_XP_Core_Achievements::manage(
            [
                'operation' => 'grant',
                'request_id' => wp_generate_uuid4(),
                'reason' => 'Synthetic pre-upgrade honor',
                'user_id' => $reader->ID,
                'task_id' => $task['id'],
                'version' => $task['version'],
                'period' => WP_XP_Core_Achievements::period($task)['key'],
            ],
            wp_create_nonce('wp_xp_core_achievements'),
        );
        $verify('Old task API grants a historical snapshot', !is_wp_error($granted));
        wp_set_current_user($reader->ID);
        $nonce = wp_create_nonce('wp_rest');
        $preference = WP_XP_Core_Fairness::personal($nonce);
        $verify(
            'Old release saves leaderboard privacy',
            !is_wp_error(
                WP_XP_Core_Fairness::save_personal(
                    [
                        'participating' => false,
                        'revision' => $preference['revision'],
                    ],
                    $nonce,
                ),
            ),
        );
        $inbox = WP_XP_Core_Notifications::listing([], $nonce);
        $verify(
            'Old release saves notification preferences',
            !is_wp_error(
                WP_XP_Core_Notifications::save(
                    [
                        'revision' => $inbox['revision'],
                        'preferences' => ['task' => false, 'review' => true, 'level' => false],
                    ],
                    $nonce,
                ),
            ),
        );
    }
    $state = [
        'old_version' => Reader_Experience::VERSION,
        'page' => (int) $page,
        'has_tasks' => $has_tasks,
        'snapshot' => $snapshot((int) $page),
    ];
    $save_state($state);
} else {
    $verify('Old upgrade state exists', is_readable($state_file));
    $state = json_decode(file_get_contents($state_file), true);
    $verify(
        'Upgrade state has its original snapshot',
        is_array($state) && is_array($state['snapshot'] ?? null),
    );
    foreach ($snapshot($state['page']) as $key => $value) {
        $verify('Upgrade preserves ' . $key, $state['snapshot'][$key] === $value);
    }
    wp_set_current_user($reader->ID);
    $verify(
        'Retained manual page still renders a signed-in panel',
        reader_experience_panel_url() === get_permalink($state['page']) &&
            str_contains(
                do_shortcode(get_post_field('post_content', $state['page'])),
                'reader-experience-panel',
            ),
    );
    if ($mode === 'verify') {
        $verify(
            'Current code replaced the old release',
            Reader_Experience::VERSION !== $state['old_version'],
        );
        $before = $snapshot($state['page']);
        $verify(
            'Old daily checkin remains idempotent',
            $checkin(wp_create_nonce('wp_rest'))->get_status() === 200,
        );
        wp_set_current_user(0);
        $verify('Upgraded anonymous checkin remains denied', $checkin()->get_status() === 401);
        wp_set_current_user($reader->ID);
        $verify('Upgraded invalid nonce remains denied', $checkin('invalid')->get_status() === 403);
        $verify(
            'Duplicate and denied requests preserve all old state',
            $before === $snapshot($state['page']),
        );
        $day = '2034-01-11';
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        $nonce = wp_create_nonce('wp_rest');
        $verify(
            'New activity works after code-only upgrade',
            $checkin($nonce)->get_status() === 200 && $checkin($nonce)->get_status() === 200,
        );
        wp_cache_delete($reader->ID, 'user_meta');
        $verify(
            'New activity awards the retained seven XP once',
            (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 14 &&
                (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === $count + 1 &&
                WP_XP_Core_Settings::rules()['checkin_xp'] === 7,
        );
        $state['snapshot'] = $snapshot($state['page']);
        $save_state($state);
    }
}
remove_filter('wp_date', $clock, 10);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
