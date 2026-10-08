<?php
/** A fresh native load registers features but never installs or writes storage. */
define('ABSPATH', __DIR__);
$hooks = [];
function add_action($name, $callback, ...$rest)
{
    global $hooks;
    $hooks[] = $name;
}
function add_filter(...$arguments) {}
function register_activation_hook(...$arguments) {}
$shortcodes = [];
function add_shortcode($name, $callback)
{
    $GLOBALS['shortcodes'][] = $name;
}
function get_option($key, $default = false)
{
    return $default;
}
require dirname(__DIR__) . '/wp-xp-core.php';
$checks = 0;
function check($name, $condition)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks++;
}
check('absent like provider disabled', WP_XP_Core_Settings::likes_ready() === false);
check('native mode is configured', reader_experience_native() && reader_experience_configured());
check('not ready before activation', Reader_Experience::ready() === false);
check('native shortcode registered', $shortcodes === ['reader_experience']);
check(
    'usual policy hooks registered',
    in_array('transition_post_status', $hooks, true) && in_array('rest_api_init', $hooks, true),
);
$admin_styles = [];
$admin_scripts = [];
function wp_enqueue_script(...$args)
{
    $GLOBALS['admin_scripts'][] = $args;
}
function plugins_url($path, $file)
{
    return '/plugins/wp-xp-core/' . $path;
}
function wp_enqueue_style($handle, $url, $dependencies, $version)
{
    $GLOBALS['admin_styles'][] = compact('handle', 'url', 'dependencies', 'version');
}
WP_XP_Core_Settings::assets('dashboard');
WP_XP_Core_Settings::assets('settings_page_other-plugin');
check('admin styles absent on unrelated screens', $admin_styles === []);
WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
check('one scoped admin script', count($admin_scripts) === 1);
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/assets/assets.json'), true);
check(
    'settings screen uses immutable admin stylesheet',
    count($admin_styles) === 1 &&
        $admin_styles[0]['url'] === '/plugins/wp-xp-core/assets/' . $manifest['admin_css'] &&
        $admin_styles[0]['version'] === Reader_Experience::VERSION &&
        $admin_styles[0]['dependencies'] === [],
);
check(
    'ten-level policy retained',
    Reader_Experience::MINS === [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000],
);
$config = reader_experience_validate([
    'table_suffix' => 'fixture_events',
    'panel_page_id' => 7,
    'rest_aliases' => ['fixture-experience/v1'],
]);
check(
    'legacy table and REST accepted',
    $config['table_suffix'] === 'fixture_events' &&
        $config['rest_aliases'] === ['fixture-experience/v1'],
);
foreach (
    [
        ['unknown' => 1],
        ['rank_option' => 'legacy_ranks'],
        ['table_suffix' => 'events;DROP'],
        ['panel_page_id' => '7'],
        ['rest_aliases' => ['bad/url']],
    ]
    as $input
) {
    try {
        reader_experience_validate($input);
        check('invalid experience profile rejected', false);
    } catch (InvalidArgumentException $error) {
        check('invalid experience profile rejected', true);
    }
}
check(
    'uninstalled native account panel preserves input',
    reader_experience_account_panel('fixture-panel') === 'fixture-panel',
);
$GLOBALS['reader_experience_profile'] = reader_experience_validate([
    'weekly_lock' => 'shared_fixture_lock',
]);
check(
    'weekly lock uses exact shared configured name',
    reader_experience_lock('weekly_lock') === 'shared_fixture_lock',
);
try {
    reader_experience_validate(['weekly_lock' => str_repeat('x', 65)]);
    check('long weekly lock rejected', false);
} catch (InvalidArgumentException $error) {
    check('long weekly lock rejected', true);
}
$GLOBALS['reader_experience_profile'] = reader_experience_defaults();
echo "$checks WP XP Core contract checks passed\n";
