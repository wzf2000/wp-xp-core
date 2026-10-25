<?php
/** Translate fixed product strings only. User content and historical snapshots stay verbatim. */
defined('ABSPATH') || exit();
function wp_xp_core_t(string $text): string
{
    return function_exists('__') ? __($text, 'wp-xp-core') : $text;
}
final class WP_XP_Core_I18n
{
    private static ?string $loaded_locale = null;
    public static function load(): void
    {
        $locale = determine_locale();
        if (self::$loaded_locale !== null && self::$loaded_locale !== $locale) {
            unload_textdomain('wp-xp-core', true);
        }
        self::$loaded_locale = $locale;
        load_plugin_textdomain(
            'wp-xp-core',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages',
        );
        if (in_array($locale, ['en_US', 'zh_CN'], true)) {
            load_textdomain(
                'wp-xp-core',
                __DIR__ . '/languages/wp-xp-core-' . $locale . '.mo',
                $locale,
            );
        }
    }
    public static function script(string $handle): void
    {
        if (!function_exists('wp_add_inline_script')) {
            return;
        }
        $file = __DIR__ . '/languages/js-strings.json';
        $strings = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $map = [];
        foreach (is_array($strings) ? $strings : [] as $text) {
            $map[$text] = wp_xp_core_t($text);
        }
        wp_add_inline_script(
            $handle,
            'window.WPXPI18n=Object.assign(window.WPXPI18n||{},' .
                wp_json_encode($map) .
                ');window.WPXPLocale=' .
                wp_json_encode(
                    function_exists('determine_locale')
                        ? str_replace('_', '-', determine_locale())
                        : 'zh-CN',
                ) .
                ';',
            'before',
        );
    }
}
add_action('init', [WP_XP_Core_I18n::class, 'load'], 0);
