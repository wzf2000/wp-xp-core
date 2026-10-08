<?php
/** Synthetic settings checks; never connects to WordPress or a real database. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'panel_fixture');
$GLOBALS['capable'] = true;
$GLOBALS['native'] = true;
$GLOBALS['options'] = ['wp_xp_core_rules' => ['unchanged' => true]];
$GLOBALS['writes'] = 0;
$GLOBALS['lock_busy'] = false;
$GLOBALS['write_failed'] = false;
function add_action(...$args) {}
function reader_experience_native()
{
    return $GLOBALS['native'];
}
function current_user_can(...$args)
{
    return $GLOBALS['capable'];
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid' && $action === 'wp_xp_core_panel';
}
function wp_cache_delete(...$args) {}
function get_option($key, $default = false)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function update_option($key, $value, ...$args)
{
    if ($GLOBALS['write_failed']) {
        return false;
    }
    $GLOBALS['writes']++;
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_post($id)
{
    $pages = [
        7 => ['page', 'publish', ''],
        8 => ['page', 'draft', ''],
        9 => ['post', 'publish', ''],
        10 => ['page', 'publish', 'protected'],
    ];
    if (!isset($pages[$id])) {
        return null;
    }
    return (object) array_combine(['post_type', 'post_status', 'post_password'], $pages[$id]);
}
class WP_Error
{
    public function __construct(public $code, public $message, public $data) {}
}
class PanelStorage
{
    public $prefix = 'fixture_';
    public $releases = 0;
    public function prepare($sql, ...$args)
    {
        return $sql;
    }
    public function get_var($sql)
    {
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->releases++;
            return 1;
        }
        return $GLOBALS['lock_busy'] ? 0 : 1;
    }
}
$GLOBALS['wpdb'] = new PanelStorage();
require dirname(__DIR__) . '/settings.php';
$checks = 0;
function verify_panel($name, $condition)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks++;
}
function save_panel($id, $nonce = 'valid', $revision = null)
{
    return WP_XP_Core_Settings::save_panel_page(
        $id,
        $nonce,
        $revision ??
            WP_XP_Core_Settings::panel_revision(
                get_option(WP_XP_Core_Settings::PANEL_OPTION, null),
            ),
    );
}
$GLOBALS['capable'] = false;
verify_panel(
    'anonymous save denied',
    save_panel(7)->code === 'forbidden' && $GLOBALS['writes'] === 0,
);
$GLOBALS['capable'] = true;
$GLOBALS['native'] = false;
verify_panel(
    'external profile remains authoritative',
    save_panel(7)->code === 'forbidden' && $GLOBALS['writes'] === 0,
);
$GLOBALS['native'] = true;
verify_panel('nonce required', save_panel(7, 'bad')->code === 'nonce' && $GLOBALS['writes'] === 0);
foreach ([8, 9, 10, 11, -1, '7bad', '07', 7.0, [], '2147483648'] as $id) {
    verify_panel(
        'invalid or unavailable page rejected',
        save_panel($id)->code === 'input' && $GLOBALS['writes'] === 0,
    );
}
$GLOBALS['lock_busy'] = true;
verify_panel('busy save denied', save_panel(7)->code === 'busy' && $GLOBALS['writes'] === 0);
$GLOBALS['lock_busy'] = false;
$stale = WP_XP_Core_Settings::revision(null);
verify_panel(
    'public page saved',
    save_panel('7') === true && get_option(WP_XP_Core_Settings::PANEL_OPTION) === 7,
);
verify_panel('unchanged page does not write', save_panel(7) === true && $GLOBALS['writes'] === 1);
verify_panel(
    'stale form cannot overwrite',
    save_panel(0, 'valid', $stale)->code === 'conflict' &&
        get_option(WP_XP_Core_Settings::PANEL_OPTION) === 7,
);
$GLOBALS['write_failed'] = true;
verify_panel(
    'storage failure preserves selection',
    save_panel(0)->code === 'storage' && get_option(WP_XP_Core_Settings::PANEL_OPTION) === 7,
);
$GLOBALS['write_failed'] = false;
verify_panel(
    'clear selection supported',
    save_panel(0) === true && get_option(WP_XP_Core_Settings::PANEL_OPTION) === 0,
);
verify_panel(
    'rules never rewritten',
    get_option(WP_XP_Core_Settings::OPTION) === ['unchanged' => true],
);
$GLOBALS['options'][WP_XP_Core_Settings::PANEL_OPTION] = '7';
$before = $GLOBALS['writes'];
verify_panel(
    'reloaded string page ID saves unchanged',
    save_panel(7) === true && $GLOBALS['writes'] === $before,
);
$GLOBALS['options'][WP_XP_Core_Settings::PANEL_OPTION] = '0';
verify_panel(
    'reloaded zero saves unchanged',
    save_panel(0) === true && $GLOBALS['writes'] === $before,
);
$shown = WP_XP_Core_Settings::panel_revision(7);
$GLOBALS['options'][WP_XP_Core_Settings::PANEL_OPTION] = '7';
verify_panel(
    'integer form revision matches reloaded string ID',
    save_panel(7, 'valid', $shown) === true,
);
verify_panel('lock released for every acquired save', $GLOBALS['wpdb']->releases === 8);
echo "$checks panel settings checks passed\n";
