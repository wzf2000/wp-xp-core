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
    foreach (WP_XP_Core_Widgets::TYPES as $type) {
        $attributes = WP_XP_Core_Widgets::attributes();
        if ($type === 'path') {
            $attributes['series'] = ['type' => 'string', 'default' => ''];
        }
        register_block_type('wp-xp-core/' . $type, [
            'api_version' => 2,
            'editor_script' => 'wp-xp-core-blocks',
            'attributes' => $attributes,
            'render_callback' => static fn($attributes) => WP_XP_Core_Widgets::render(
                $type,
                $attributes,
            ),
            'supports' => ['html' => false, 'customClassName' => false],
        ]);
    }
});
