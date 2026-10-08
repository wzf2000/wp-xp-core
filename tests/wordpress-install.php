<?php
/** Real WordPress fixture: native activation, authenticated REST and panel settings. */
if (
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic WordPress installation required.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'wp-xp-core/wp-xp-core.php';
require_once WP_PLUGIN_DIR . '/' . $plugin;
$mode = getenv('WP_XP_TEST_MODE') ?: 'install';
global $wpdb, $checks;
$checks = [];
function xp_verify($name, $condition)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
}
function xp_rest($nonce = null)
{
    $request = new WP_REST_Request('POST', '/reader-experience/v1/checkin');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body('{}');
    if ($nonce !== null) {
        $request->set_header('X-WP-Nonce', $nonce);
    }
    return rest_do_request($request);
}
function xp_save_panel($id, $nonce = null)
{
    return WP_XP_Core_Settings::save_panel_page(
        $id,
        $nonce ?? wp_create_nonce('wp_xp_core_panel'),
        WP_XP_Core_Settings::panel_revision(get_option(WP_XP_Core_Settings::PANEL_OPTION, null)),
    );
}
$admin = get_user_by('login', 'fixture-author');
$reader = get_user_by('login', 'fixture-reader');
$parallel = get_user_by('login', 'fixture-concurrent');
xp_verify('Synthetic users exist', $admin && $reader && $parallel);
wp_set_current_user($admin->ID);
if ($mode === 'install') {
    xp_verify(
        'No external profile is needed',
        reader_experience_native() && reader_experience_configured(),
    );
    xp_verify(
        'Plugin load does not create a ledger',
        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                Reader_Experience::table(),
            ),
        ) === '0',
    );
    xp_verify(
        'A native shortcode is registered before readiness',
        shortcode_exists('reader_experience'),
    );
    xp_verify(
        'Read-only panel reports uninstalled storage',
        !Reader_Experience::ready() && str_contains(do_shortcode('[reader_experience]'), '维护'),
    );
    xp_verify(
        'Read-only load creates no installation options',
        get_option(WP_XP_Core_Lifecycle::SCHEMA_OPTION, null) === null &&
            get_option(WP_XP_Core_Lifecycle::INTENT_OPTION, null) === null &&
            get_option('reader_experience_live', null) === null,
    );
    $pages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page'");
    $result = activate_plugin($plugin);
    xp_verify(
        'Normal WordPress activation succeeds',
        !is_wp_error($result) && is_plugin_active($plugin),
    );
    xp_verify(
        'Native installation is ready',
        Reader_Experience::ready() && WP_XP_Core_Lifecycle::installed(),
    );
    xp_verify(
        'Fresh activation creates an empty InnoDB ledger',
        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                Reader_Experience::table(),
            ),
        ) === 'InnoDB' &&
            $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '0',
    );
    xp_verify(
        'Activation does not initialize existing users or copy rules',
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",
                Reader_Experience::balance_meta(),
            ),
        ) === '0' && get_option(WP_XP_Core_Settings::OPTION, null) === null,
    );
    xp_verify(
        'Activation does not create a page',
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page'") ===
            $pages,
    );
    wp_set_current_user(0);
    xp_verify('Anonymous REST check-in is denied', xp_rest()->get_status() === 401);
    wp_set_current_user($reader->ID);
    xp_verify('Invalid REST nonce is denied', xp_rest('invalid')->get_status() === 403);
    xp_verify(
        'Rejected requests do not add ledger records',
        $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '0',
    );
    $nonce = wp_create_nonce('wp_rest');
    xp_verify('Authenticated native check-in succeeds', xp_rest($nonce)->get_status() === 200);
    xp_verify('Repeating check-in succeeds idempotently', xp_rest($nonce)->get_status() === 200);
    xp_verify(
        'Two check-ins record one default reward',
        $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '1' &&
            (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 2,
    );
    $html = do_shortcode('[reader_experience]');
    xp_verify(
        'Shortcode renders a working signed-in panel',
        str_contains($html, 'reader-experience-panel') &&
            str_contains($html, 'Level 1') &&
            str_contains($html, '今日已签到'),
    );
    wp_set_current_user($admin->ID);
    $page = wp_insert_post(
        [
            'post_title' => 'Synthetic experience',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '[reader_experience]',
        ],
        true,
    );
    xp_verify(
        'Manual native panel page saves',
        !is_wp_error($page) &&
            xp_save_panel((string) $page) === true &&
            reader_experience_panel_url() === get_permalink($page),
    );
    xp_verify(
        'Panel setting requires a valid nonce',
        xp_save_panel($page, 'invalid')->get_error_code() === 'nonce',
    );
    wp_set_current_user($reader->ID);
    xp_verify(
        'Panel setting requires administrator capability',
        xp_save_panel(0)->get_error_code() === 'forbidden',
    );
    wp_set_current_user($admin->ID);
    $rules = WP_XP_Core_Settings::rules();
    $rules['checkin_xp'] = 7;
    $saved = WP_XP_Core_Settings::save(
        $rules,
        wp_create_nonce('wp_xp_core_settings'),
        WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
    );
    xp_verify(
        'Administrator can save rules without altering balance',
        $saved === true &&
            WP_XP_Core_Settings::rules()['checkin_xp'] === 7 &&
            (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 2,
    );
    update_option('reader_experience_live', '0', false);
    $marker = get_option(WP_XP_Core_Lifecycle::SCHEMA_OPTION);
    deactivate_plugins($plugin);
    $result = activate_plugin($plugin);
    xp_verify(
        'Deactivation and reactivation preserve the native installation',
        !is_wp_error($result) &&
            get_option(WP_XP_Core_Lifecycle::SCHEMA_OPTION) === $marker &&
            $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '1',
    );
    xp_verify(
        'Reactivation preserves custom rules and existing maintenance flag',
        get_option('reader_experience_live') === '0' &&
            WP_XP_Core_Settings::rules()['checkin_xp'] === 7 &&
            !Reader_Experience::ready(),
    );
    wp_set_current_user($reader->ID);
    xp_verify(
        'Maintenance flag prevents rewards',
        xp_rest(wp_create_nonce('wp_rest'))->get_status() === 503 &&
            (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 2,
    );
    update_option('reader_experience_live', '1', false);
} elseif ($mode === 'verify') {
    $page = get_option(WP_XP_Core_Settings::PANEL_OPTION);
    xp_verify(
        'WordPress reload exposes the scalar page option as a string',
        is_string($page) && (int) $page > 0,
    );
    xp_verify(
        'Saving the same page after a fresh WordPress load succeeds',
        xp_save_panel((int) $page) === true &&
            reader_experience_panel_url() === get_permalink((int) $page),
    );
    $callbacks = ['wp_ajax_bigfa_like', 'wp_ajax_nopriv_bigfa_like', 'wp_weekly_function_hook'];
    $callback = static function () {};
    foreach ($callbacks as $hook) {
        add_action($hook, $callback);
    }
    Reader_Experience::legacy();
    Reader_Experience::weekly();
    foreach ($callbacks as $hook) {
        xp_verify(
            'Native mode preserves existing callback ' . $hook,
            has_action($hook, $callback) === 10,
        );
    }
    xp_verify(
        'Native reload preserves earlier ledger and rules',
        $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '1' &&
            WP_XP_Core_Settings::rules()['checkin_xp'] === 7,
    );
} elseif ($mode === 'concurrency-verify') {
    $key = 'checkin:' . $parallel->ID . ':' . Reader_Experience::day();
    xp_verify(
        'Concurrent independent clients award exactly once',
        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                $key,
            ),
        ) === '1' &&
            (int) get_user_meta($parallel->ID, Reader_Experience::balance_meta(), true) === 7,
    );
    xp_verify(
        'Concurrent check-in preserves the first reader reward',
        (int) get_user_meta($reader->ID, Reader_Experience::balance_meta(), true) === 2 &&
            $wpdb->get_var('SELECT COUNT(*) FROM ' . Reader_Experience::table()) === '2',
    );
} else {
    throw new RuntimeException('Unknown synthetic test mode.');
}
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
