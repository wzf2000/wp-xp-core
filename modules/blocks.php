<?php
/** Dynamic blocks reuse the existing shortcode renderers and permission boundary. */
defined('ABSPATH') || exit();
add_action('init', function () {
    $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/assets/assets.json'), true);
    wp_register_script(
        'wp-xp-core-blocks',
        plugins_url('assets/' . $manifest['blocks_js'], dirname(__DIR__) . '/wp-xp-core.php'),
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
        Reader_Experience::VERSION,
        true,
    );
    WP_XP_Core_I18n::script('wp-xp-core-blocks');
    register_block_type('wp-xp-core/growth', [
        'api_version' => 2,
        'editor_script' => 'wp-xp-core-blocks',
        'render_callback' => static fn() => Reader_Experience::panel(),
        'supports' => ['html' => false],
    ]);
    register_block_type('wp-xp-core/leaderboard', [
        'api_version' => 2,
        'editor_script' => 'wp-xp-core-blocks',
        'attributes' => [
            'period' => ['type' => 'string', 'enum' => ['week', 'month'], 'default' => 'week'],
            'limit' => ['type' => 'integer', 'default' => 10],
        ],
        'render_callback' => static fn($attributes) => WP_XP_Core_Insights_UI::board([
            'period' => $attributes['period'] ?? 'week',
            'limit' => (string) ($attributes['limit'] ?? 10),
        ]),
        'supports' => ['html' => false],
    ]);
});
