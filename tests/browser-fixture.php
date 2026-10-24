<?php
/** Serve only synthetic markup and actual installation assets. */
$root = dirname(__DIR__);
$dir = $root . '/.runtime/frontend';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$assets = json_decode(
    file_get_contents($root . '/assets/assets.json'),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
foreach ($assets as $name) {
    copy($root . '/assets/' . $name, $dir . '/' . $name);
}
$html =
    '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="' .
    htmlspecialchars($assets['css']) .
    '"><title>Experience fixture</title><section class="reader-experience-panel"><p class="reader-experience-state"></p><button class="reader-experience-checkin">每日签到 +2</button><p class="reader-experience-message" role="status"></p><ul class="reader-experience-history"></ul></section><button class="favorite">Companion like fixture</button><script>window.ReaderExperience={endpoint:"/api/",nonce:"synthetic-nonce",logged:true,postId:9};</script><script src="' .
    htmlspecialchars($assets['js']) .
    '"></script></html>';
file_put_contents($dir . '/index.html', $html);

// Render the actual PHP settings page with synthetic WordPress primitives.
define('ABSPATH', __DIR__);
class Reader_Experience
{
    const VERSION = 'fixture';
}
function add_action(...$args) {}
function reader_experience_native()
{
    return true;
}
function wp_dropdown_pages($args)
{
    echo '<select id="' .
        htmlspecialchars($args['id'], ENT_QUOTES) .
        '" name="' .
        htmlspecialchars($args['name'], ENT_QUOTES) .
        '"><option value="0">暂不指定</option><option value="7">我的经验</option></select>';
}
function current_user_can(...$args)
{
    return true;
}
function get_option($key, $default = false)
{
    return $default;
}
function esc_attr($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function esc_html($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function wp_nonce_field($action)
{
    echo '<input name="_wpnonce" value="fixture">';
}
function submit_button($label, ...$args)
{
    echo '<button type="submit">' . $label . '</button>';
}
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/settings.php';
ob_start();
WP_XP_Core_Settings::page();
$markup = ob_get_clean();
file_put_contents(
    $dir . '/admin.html',
    '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="' .
        $assets['admin_css'] .
        '">' .
        $markup .
        '<script src="' .
        $assets['admin_js'] .
        '"></script></html>',
);

file_put_contents(
    $dir . '/ranking.html',
    '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="' .
        htmlspecialchars($assets['css']) .
        '"><style>body{margin:0;padding:20px;font:14px/1.8 sans-serif}table{width:100%;border-collapse:collapse}td,th{padding:18px;border:1px solid #dce5e3}th:last-child{width:6em}.pagenest-article-body :is(th,td){white-space:normal!important;overflow-wrap:break-word!important}</style><div class="pagenest-article-body"><section class="site-tools-ranking" data-ranking="community-ranking"><table><thead><tr><th>#</th><th>用户名</th><th>个人简介</th><th>经验</th><th>等级</th></tr></thead><tbody><tr><td>1</td><td>合成用户一</td><td>用于检查等级列的合成介绍，不使用真实账号。</td><td>3000</td><td>Level 10</td></tr><tr><td>2</td><td>合成用户二</td><td>支持最多一百级时仍保持单行。</td><td>9000</td><td>Level 100</td></tr></tbody></table></section></div></html>',
);

// Exercise actual growth markup with private synthetic API responses supplied by Playwright.
function plugins_url($path, $file)
{
    return basename($path);
}
function wp_enqueue_script(...$args) {}
function is_user_logged_in()
{
    return true;
}
function get_current_user_id()
{
    return 7;
}
function esc_url($url)
{
    return htmlspecialchars($url, ENT_QUOTES);
}
class WP_XP_Core_Growth
{
    public static function summary($uid)
    {
        return [
            'experience' => 5,
            'level' => 2,
            'next' => 20,
            'checked_in' => false,
            'checkin_xp' => 2,
            'activities' => ['checkin' => true, 'visit' => true, 'comment' => true],
            'level_profile' => [
                'name' => '探索者',
                'icon' => '芽',
                'color' => '#3856b0',
                'description' => '合成成长资料',
            ],
            'progress_percent' => 0,
            'day' => '2026-10-24',
            'dwell_seconds' => 30,
            'checkins' => [
                'consecutive' => 2,
                'cumulative' => 9,
                'dates' => ['2026-10-24', '2026-10-23'],
            ],
            'streak' => ['enabled' => false, 'mode' => 'consecutive', 'days' => 0, 'rewards' => []],
            'today' => ['visits' => 1, 'visit_limit' => 3, 'comments' => 0, 'comment_limit' => 3],
        ];
    }
}
require $root . '/growth-ui.php';
file_put_contents($dir . '/growth-state.json', json_encode(WP_XP_Core_Growth::summary(7)));
file_put_contents(
    $dir . '/growth.html',
    '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width">' .
        '<style>body{margin:20px;font:16px/1.6 sans-serif;max-width:1000px}*{box-sizing:border-box}</style>' .
        WP_XP_Core_Growth_UI::panel() .
        '<script>window.ReaderExperience={endpoint:"/api/",nonce:"synthetic-nonce",logged:true,postId:null};</script><script src="' .
        $assets['js'] .
        '"></script><script src="' .
        $assets['growth_js'] .
        '"></script></html>',
);
