<?php
/** Built-in defaults and strict external integration; loading never installs storage. */
defined('ABSPATH') || exit();
function reader_experience_defaults()
{
    return [
        'table_suffix' => 'reader_experience_events',
        'live_option' => 'reader_experience_live',
        'week_option' => 'reader_experience_week_rotated',
        'event_lock' => 'reader_experience_events',
        'weekly_lock' => 'reader_experience_scoring',
        'rest_namespace' => 'reader-experience/v1',
        'rest_aliases' => [],
        'shortcodes' => ['reader_experience'],
        'panel_page_id' => 0,
        'weekly_hook' => 'wp_weekly_function_hook',
    ];
}
function reader_experience_validate($input)
{
    $defaults = reader_experience_defaults();
    if (!is_array($input) || array_diff(array_keys($input), array_keys($defaults))) {
        throw new InvalidArgumentException('Invalid experience profile.');
    }
    $config = array_replace($defaults, $input);
    foreach (
        ['table_suffix', 'live_option', 'week_option', 'event_lock', 'weekly_lock', 'weekly_hook']
        as $key
    ) {
        if (
            !is_string($config[$key]) ||
            strlen($config[$key]) > 100 ||
            !preg_match('/^[a-zA-Z0-9_-]+$/D', $config[$key])
        ) {
            throw new InvalidArgumentException('Invalid experience identifier.');
        }
    }
    if (strlen($config['weekly_lock']) > 64) {
        throw new InvalidArgumentException('Weekly lock exceeds database limit.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $config['table_suffix'])) {
        throw new InvalidArgumentException('Invalid experience table.');
    }
    if (!is_int($config['panel_page_id']) || $config['panel_page_id'] < 0) {
        throw new InvalidArgumentException('Invalid experience page.');
    }
    foreach (['rest_aliases', 'shortcodes'] as $key) {
        if (!is_array($config[$key]) || array_values($config[$key]) !== $config[$key]) {
            throw new InvalidArgumentException('Invalid experience list.');
        }
    }
    foreach (array_merge([$config['rest_namespace']], $config['rest_aliases']) as $namespace) {
        if (
            !is_string($namespace) ||
            !preg_match('/^[a-zA-Z0-9_-]+\/v[1-9][0-9]*$/D', $namespace)
        ) {
            throw new InvalidArgumentException('Invalid experience namespace.');
        }
    }
    foreach ($config['shortcodes'] as $name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new InvalidArgumentException('Invalid experience shortcode.');
        }
    }
    return $config;
}
function reader_experience_load_profile()
{
    if (!defined('PAGENEST_COMPATIBILITY_PROFILE_FILE')) {
        return reader_experience_defaults();
    }
    $path = realpath(PAGENEST_COMPATIBILITY_PROFILE_FILE);
    $root = realpath(ABSPATH);
    if (
        !$path ||
        !$root ||
        !is_file($path) ||
        !is_readable($path) ||
        $path === $root ||
        str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        filesize($path) > 1048576
    ) {
        throw new InvalidArgumentException('Experience profile must be outside web root.');
    }
    $profile = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (
        !is_array($profile) ||
        ($profile['schema_version'] ?? null) !== 1 ||
        array_diff(array_keys($profile), [
            'schema_version',
            'theme',
            'mail',
            'paragraphs',
            'chapters',
            'likes',
            'experience',
        ])
    ) {
        throw new InvalidArgumentException('Invalid shared profile.');
    }
    if (!isset($profile['experience'])) {
        throw new InvalidArgumentException(
            'Experience section is required in an external profile.',
        );
    }
    return reader_experience_validate($profile['experience']);
}
try {
    $GLOBALS['reader_experience_profile'] = reader_experience_load_profile();
} catch (Throwable $error) {
    $GLOBALS['reader_experience_profile'] = null;
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>WP XP Core compatibility profile is invalid; features are disabled.</p></div>';
    });
}
function reader_experience_native(): bool
{
    return !defined('PAGENEST_COMPATIBILITY_PROFILE_FILE');
}
function reader_experience_external(): bool
{
    return !reader_experience_native();
}
function reader_experience_configured()
{
    return is_array($GLOBALS['reader_experience_profile'] ?? null);
}
function reader_experience_config($key)
{
    return ($GLOBALS['reader_experience_profile'] ?? reader_experience_defaults())[$key];
}
function reader_experience_lock($key)
{
    global $wpdb;
    if ($key === 'weekly_lock') {
        return reader_experience_config($key);
    }
    return substr(reader_experience_config($key), 0, 25) .
        ':' .
        substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 32);
}
function reader_experience_panel_url()
{
    $id = reader_experience_config('panel_page_id');
    if (reader_experience_native()) {
        $id = (int) get_option('wp_xp_core_panel_page_id', 0);
        $page = $id > 0 ? get_post($id) : null;
        $id =
            $page &&
            $page->post_type === 'page' &&
            $page->post_status === 'publish' &&
            $page->post_password === ''
                ? $id
                : 0;
    }
    return $id > 0 ? get_permalink($id) : (is_singular() ? get_permalink() : home_url('/'));
}
function reader_experience_route_matches($route)
{
    foreach (
        array_merge(
            [reader_experience_config('rest_namespace')],
            reader_experience_config('rest_aliases'),
        )
        as $namespace
    ) {
        if (str_starts_with($route, '/' . $namespace . '/')) {
            return true;
        }
    }
    return false;
}
