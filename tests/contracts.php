<?php
/** An unconfigured install neither writes nor installs account policy hooks. */
define('ABSPATH', __DIR__);
$hooks = [];
function add_action($name, $callback, ...$rest)
{
    global $hooks;
    $hooks[] = $name;
}
function add_filter(...$arguments) {}
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
check('unconfigured ready false', Reader_Experience::ready() === false);
check('no policy hooks on load', $hooks === ['admin_menu']);
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
    'unconfigured account panel preserves input',
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
$GLOBALS['reader_experience_profile'] = null;
echo "$checks WP XP Core contract checks passed\n";
