<?php
/** Optional fixed-origin GitHub release checks. No additional scheduled task. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Updates
{
    const OPTION = 'wp_xp_core_updates';
    const CACHE = 'wp_xp_core_release_cache_v1';
    const API = 'https://api.github.com/repos/wzf2000/wp-xp-core/releases?per_page=30';
    public static function settings(): array
    {
        $raw = get_option(self::OPTION, []);
        return [
            'enabled' =>
                !(function_exists('is_multisite') && is_multisite()) &&
                is_array($raw) &&
                ($raw['enabled'] ?? false) === true,
            'channel' =>
                is_array($raw) && ($raw['channel'] ?? '') === 'prerelease'
                    ? 'prerelease'
                    : 'stable',
        ];
    }
    public static function revision(): string
    {
        return WP_XP_Core_Settings::revision(get_option(self::OPTION, null));
    }
    public static function save(array $input, $nonce)
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return new WP_Error(
                'network_unsupported',
                wp_xp_core_t('多站点网络请由网络管理员手动更新；本站不提供共享更新器设置。'),
                ['status' => 403],
            );
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', wp_xp_core_t('没有管理设置的权限。'), [
                'status' => 403,
            ]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_configuration')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        if (
            array_diff(array_keys($input), ['enabled', 'channel', 'revision']) ||
            !is_bool($input['enabled'] ?? null) ||
            !in_array($input['channel'] ?? '', ['stable', 'prerelease'], true) ||
            !is_string($input['revision'] ?? null)
        ) {
            return new WP_Error('input', wp_xp_core_t('更新设置无效。'));
        }
        try {
            return Reader_Experience::transaction(function () use ($input) {
                WP_XP_Core_Management::require_options_transaction();
                wp_cache_delete(self::OPTION, 'options');
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                if (!hash_equals(self::revision(), $input['revision'])) {
                    return new WP_Error('conflict', wp_xp_core_t('更新设置已变化，请刷新。'), [
                        'status' => 409,
                    ]);
                }
                $next = ['enabled' => $input['enabled'], 'channel' => $input['channel']];
                if (get_option(self::OPTION, null) !== $next) {
                    Reader_Experience::touch_option(self::OPTION);
                    if (!update_option(self::OPTION, $next, false)) {
                        throw new RuntimeException('Update preference failed');
                    }
                }
                return true;
            });
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('更新设置未能保存。'), ['status' => 503]);
        }
    }
    public static function candidate(array $releases, string $channel, string $current): ?array
    {
        $best = null;
        foreach (array_slice($releases, 0, 30) as $release) {
            if (
                !is_array($release) ||
                !empty($release['draft']) ||
                !is_string($release['tag_name'] ?? null)
            ) {
                continue;
            }
            $version = preg_replace('/^v/', '', $release['tag_name']);
            if (
                !preg_match(
                    '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(alpha|beta|rc)\.[1-9][0-9]*)?$/D',
                    $version,
                )
            ) {
                continue;
            }
            if (
                $channel === 'stable' &&
                (!empty($release['prerelease']) || str_contains($version, '-'))
            ) {
                continue;
            }
            if (
                !version_compare($version, $current, '>') ||
                ($best && !version_compare($version, $best['version'], '>'))
            ) {
                continue;
            }
            $name = 'wp-xp-core-' . $version . '.zip';
            $expected =
                'https://github.com/wzf2000/wp-xp-core/releases/download/v' .
                $version .
                '/' .
                $name;
            foreach (
                array_slice(is_array($release['assets'] ?? null) ? $release['assets'] : [], 0, 20)
                as $asset
            ) {
                if (
                    is_array($asset) &&
                    ($asset['name'] ?? null) === $name &&
                    ($asset['browser_download_url'] ?? null) === $expected &&
                    is_int($asset['size'] ?? null) &&
                    $asset['size'] > 0 &&
                    $asset['size'] <= 33554432
                ) {
                    $best = [
                        'version' => $version,
                        'package' => $expected,
                        'url' => 'https://github.com/wzf2000/wp-xp-core/releases/tag/v' . $version,
                    ];
                    break;
                }
            }
        }
        return $best;
    }
    public static function releases(): array
    {
        $cache = get_transient(self::CACHE);
        if (is_array($cache)) {
            return $cache;
        }
        $response = wp_remote_get(self::API, [
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 262144,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WP-XP-Core/' . Reader_Experience::VERSION,
            ],
        ]);
        $rows =
            !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200
                ? json_decode(wp_remote_retrieve_body($response), true, 32)
                : [];
        $rows = is_array($rows) ? array_slice($rows, 0, 30) : [];
        set_transient(self::CACHE, $rows, $rows ? 21600 : 900);
        return $rows;
    }
    public static function filter($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $file = plugin_basename(__DIR__ . '/wp-xp-core.php');
        $settings = self::settings();
        unset($transient->response[$file], $transient->no_update[$file]);
        if (!$settings['enabled']) {
            return $transient;
        }
        $candidate = self::candidate(
            self::releases(),
            $settings['channel'],
            Reader_Experience::VERSION,
        );
        if ($candidate) {
            $transient->response[$file] = (object) [
                'slug' => 'wp-xp-core',
                'plugin' => $file,
                'new_version' => $candidate['version'],
                'url' => $candidate['url'],
                'package' => $candidate['package'],
                'requires' => '6.0',
                'requires_php' => '8.0',
            ];
        }
        return $transient;
    }
}
add_filter('pre_set_site_transient_update_plugins', [WP_XP_Core_Updates::class, 'filter']);
add_filter('site_transient_update_plugins', function ($value) {
    if (!WP_XP_Core_Updates::settings()['enabled'] && is_object($value)) {
        $file = plugin_basename(__DIR__ . '/wp-xp-core.php');
        unset($value->response[$file], $value->no_update[$file]);
    }
    return $value;
});
