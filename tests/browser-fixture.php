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
