<?php
/** Administrator operations on an explicitly isolated synthetic WordPress database. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required.');
}
$checks = [];
$verify = static function ($label, $condition) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$error = static fn($value, $code = null) => is_wp_error($value) &&
    ($code === null || $value->get_error_code() === $code);
$admin = get_user_by('login', 'fixture-author');
$reader = get_user_by('login', 'fixture-reader');
wp_set_current_user($admin->ID);
$nonce = wp_create_nonce('wp_xp_core_manage');
$before = WP_XP_Core_Management::user_summary($reader->ID);
$input = [
    'request_id' => wp_generate_uuid4(),
    'user_id' => (int) $reader->ID,
    'xp' => 8,
    'reason' => 'Synthetic administrator correction',
];
$result = WP_XP_Core_Management::adjust($input, $nonce);
$verify(
    'Administrator adjustment uses an appended event',
    !is_wp_error($result) &&
        $result['event_id'] > 0 &&
        WP_XP_Core_Management::user_summary($reader->ID)['balance'] === $before['balance'] + 8,
);
$repeat = WP_XP_Core_Management::adjust($input, $nonce);
$verify(
    'Repeated adjustment awards once',
    !is_wp_error($repeat) &&
        $repeat['duplicate'] === true &&
        WP_XP_Core_Management::user_summary($reader->ID)['balance'] === $before['balance'] + 8,
);
$changed = $input;
$changed['xp'] = 9;
$verify(
    'Same request with another amount is rejected',
    $error(WP_XP_Core_Management::adjust($changed, $nonce)),
);
$reverse = [
    'request_id' => wp_generate_uuid4(),
    'event_id' => $result['event_id'],
    'reason' => 'Synthetic reversal',
];
$reversal = WP_XP_Core_Management::reverse($reverse, $nonce);
$verify(
    'Reversal restores the earlier balance with another event',
    !is_wp_error($reversal) &&
        WP_XP_Core_Management::user_summary($reader->ID)['balance'] === $before['balance'],
);
$verify(
    'Repeating reversal is idempotent',
    WP_XP_Core_Management::reverse($reverse, $nonce)['duplicate'] === true,
);
$reverse['request_id'] = wp_generate_uuid4();
$verify(
    'Another reversal of the same event is rejected',
    $error(WP_XP_Core_Management::reverse($reverse, $nonce)),
);
$negative = $input;
$negative['request_id'] = wp_generate_uuid4();
$negative['xp'] = -1000000;
$verify(
    'Insufficient experience cannot be deducted',
    $error(WP_XP_Core_Management::adjust($negative, $nonce)),
);
$verify(
    'Invalid nonce cannot change experience',
    $error(WP_XP_Core_Management::adjust($input, 'invalid'), 'nonce'),
);
wp_set_current_user($reader->ID);
$verify(
    'Subscriber cannot query the administrative ledger',
    $error(WP_XP_Core_Management::events()),
);
$verify(
    'Subscriber cannot adjust experience',
    $error(WP_XP_Core_Management::adjust($input, wp_create_nonce('wp_xp_core_manage'))),
);
$private = WP_XP_Core_Management::personal_history(['limit' => 1]);
$verify(
    'Private history has no administrative evidence',
    !is_wp_error($private) &&
        (!isset($private['items'][0]) || !isset($private['items'][0]['detail'])),
);
wp_set_current_user($admin->ID);
$events = WP_XP_Core_Management::events(['user_id' => (int) $reader->ID, 'limit' => 1]);
$verify(
    'Real ledger queries have a continuation cursor',
    !is_wp_error($events) && count($events['items']) === 1 && $events['next_cursor'] > 0,
);
$next = WP_XP_Core_Management::events([
    'user_id' => (int) $reader->ID,
    'limit' => 1,
    'cursor' => $events['next_cursor'],
]);
$verify(
    'Next page does not repeat the preceding event',
    $next['items'][0]['id'] < $events['items'][0]['id'],
);
$verify(
    'User projection matches the ledger',
    WP_XP_Core_Management::user_summary($reader->ID)['consistent'],
);
$diagnostics = WP_XP_Core_Management::diagnostics();
$verify(
    'Read-only consistency query runs against real database',
    !is_wp_error($diagnostics) && count($diagnostics['items']) > 0,
);
$raw = get_option(WP_XP_Core_Settings::OPTION, null);
$rules = WP_XP_Core_Settings::rules();
$rules['checkin_xp']++;
$saved = WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision($raw),
);
$history = WP_XP_Core_Management::audit_history();
$verify(
    'Rule changes have atomic audit evidence',
    $saved === true &&
        !is_wp_error($history) &&
        $history[0]['before'] === $raw &&
        $history[0]['after'] === $rules &&
        $history[0]['actor'] === (int) $admin->ID,
);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
WP_XP_Core_Management_UI::page();
$html = ob_get_clean();
$verify(
    'Actual ledger screen renders query and adjustment forms',
    str_contains($html, '记录经验调整') &&
        str_contains($html, '导出当前页 CSV') &&
        !str_contains($html, '查询条件无效'),
);
$_GET['view'] = 'diagnostics';
ob_start();
WP_XP_Core_Management_UI::page();
$html = ob_get_clean();
$verify(
    'Actual diagnostic screen renders consistency and audit',
    str_contains($html, '账本与余额核对') && str_contains($html, '规则修改记录'),
);
unset($_GET['view']);
// Exercise WordPress's real menu registry and access checks, without changing site data.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$menu_globals = [
    'menu',
    'submenu',
    'admin_page_hooks',
    '_registered_pages',
    '_parent_pages',
    '_wp_real_parent_file',
    '_wp_menu_nopriv',
    '_wp_submenu_nopriv',
    'pagenow',
    'plugin_page',
    'parent_file',
    'typenow',
    'title',
];
$saved_menu_globals = [];
foreach ($menu_globals as $name) {
    $saved_menu_globals[$name] = $GLOBALS[$name] ?? null;
}
$saved_get = $_GET;
$prepare_menu = static function () {
    $GLOBALS['menu'] = [['Settings', 'manage_options', 'options-general.php', 'Settings']];
    $GLOBALS['submenu'] = [
        'options-general.php' => [['General', 'manage_options', 'options-general.php', 'General']],
    ];
    $GLOBALS['admin_page_hooks'] = ['options-general.php' => 'settings'];
    foreach (
        [
            '_registered_pages',
            '_parent_pages',
            '_wp_real_parent_file',
            '_wp_menu_nopriv',
            '_wp_submenu_nopriv',
        ]
        as $name
    ) {
        $GLOBALS[$name] = [];
    }
    $GLOBALS['pagenow'] = 'options-general.php';
    $GLOBALS['plugin_page'] = null;
    $GLOBALS['parent_file'] = '';
    $GLOBALS['typenow'] = '';
    do_action('admin_menu');
};
$screens = [
    'wp-xp-core' => [WP_XP_Core_Settings::class, 'page'],
    'wp-xp-ledger' => [WP_XP_Core_Management_UI::class, 'page'],
    'wp-xp-growth' => [WP_XP_Core_Growth_UI::class, 'settings_page'],
    'wp-xp-achievements' => [WP_XP_Core_Achievements_UI::class, 'page'],
    'wp-xp-insights' => [WP_XP_Core_Insights_UI::class, 'page'],
    'wp-xp-configuration' => [WP_XP_Core_Configuration_UI::class, 'page'],
];
try {
    $prepare_menu();
    $visible = array_column($GLOBALS['submenu']['options-general.php'], 2);
    $verify(
        'Settings retains only one WP XP Core entry and preserves General',
        $visible === ['options-general.php', 'wp-xp-core'],
    );
    foreach ($screens as $slug => $callback) {
        $GLOBALS['plugin_page'] = $slug;
        $GLOBALS['parent_file'] = '';
        $_GET = ['page' => $slug, 'user_id' => '7', 'view' => 'diagnostics', 'task' => 't_fixture'];
        $hook = get_plugin_page_hook($slug, 'options-general.php');
        $verify(
            $slug .
                ' keeps its original registered hook, parent, callback and administrator access',
            $hook === 'settings_page_' . $slug &&
                isset($GLOBALS['_registered_pages'][$hook]) &&
                $GLOBALS['_parent_pages'][$slug] === 'options-general.php' &&
                has_action($hook, $callback) !== false &&
                user_can_access_admin_page(),
        );
        $verify(
            $slug . ' selects Settings and the shared WP XP Core entry',
            apply_filters('parent_file', '') === 'options-general.php' &&
                apply_filters('submenu_file', null, 'options-general.php') === 'wp-xp-core',
        );
        $GLOBALS['title'] = '';
        do_action('load-' . $hook);
        $verify($slug . ' retains its WordPress page title', get_admin_page_title() !== '');
    }
    $GLOBALS['plugin_page'] = 'unrelated-plugin';
    $verify(
        'Other plugin menu selections remain unchanged',
        apply_filters('parent_file', 'tools.php') === 'tools.php' &&
            apply_filters('submenu_file', 'unrelated-plugin', 'tools.php') === 'unrelated-plugin',
    );
    wp_set_current_user($reader->ID);
    $prepare_menu();
    foreach (array_keys($screens) as $slug) {
        $GLOBALS['plugin_page'] = $slug;
        $GLOBALS['parent_file'] = '';
        $verify(
            $slug . ' still denies a non-administrator direct URL',
            !user_can_access_admin_page(),
        );
    }
} finally {
    wp_set_current_user($admin->ID);
    $_GET = $saved_get;
    foreach ($saved_menu_globals as $name => $value) {
        $GLOBALS[$name] = $value;
    }
}
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
