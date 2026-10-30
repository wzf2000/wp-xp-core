<?php
/** Optional bridge uses only the published server facade, never another plugin's classes/tables. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Game_Activities
{
    private static array $providers = [];
    private static bool $overflow = false;
    public static function overflow(): bool
    {
        return self::$overflow;
    }
    public static function boot(): void
    {
        if (
            !function_exists('site_tools_game_activity_catalog') ||
            !function_exists('site_tools_game_settled_receipt')
        ) {
            return;
        }
        try {
            $catalog = site_tools_game_activity_catalog();
        } catch (Throwable $e) {
            return;
        }
        if (!is_array($catalog)) {
            return;
        }
        foreach ($catalog as $slug => $game) {
            if (!is_string($slug) || !is_array($game) || !is_string($game['label'] ?? null)) {
                continue;
            }
            $id = 'game_' . substr(hash('sha256', $slug), 0, 20);
            $accepted = wp_xp_core_register_activity_provider($id, [
                'label' => $game['label'],
                'description' => wp_xp_core_t('完成服务端验证的新游戏局后，按固定规则记录经验。'),
                'fields' => ['score'],
                'available' => static function () use ($slug) {
                    $current = site_tools_game_activity_catalog();
                    return ($current[$slug]['available'] ?? false) === true;
                },
                'authorize' => static function ($uid, $object, $context, $event) use ($slug) {
                    if (
                        $object !== 0 ||
                        !is_string($context['run'] ?? null) ||
                        !hash_equals($event, $context['run'])
                    ) {
                        return false;
                    }
                    $receipt = is_string($context['run'] ?? null)
                        ? site_tools_game_settled_receipt($context['run'], $uid)
                        : null;
                    return is_array($receipt) &&
                        $receipt['user_id'] === $uid &&
                        $receipt['slug'] === $slug &&
                        $receipt['finished'] === true &&
                        $receipt['verified'] === true &&
                        preg_match('/^[a-f0-9]{64}$/D', $receipt['proof_hash']) === 1;
                },
                'facts' => static function ($uid, $object, $context) {
                    $receipt = site_tools_game_settled_receipt($context['run'], $uid);
                    return is_array($receipt)
                        ? ['score' => $receipt['score'], 'created_ms' => $receipt['created_ms']]
                        : null;
                },
            ]);
            if ($accepted) {
                self::$providers[$slug] = $id;
            } else {
                self::$overflow = true;
            }
        }
        if (function_exists('site_tools_register_game_settled_observer')) {
            site_tools_register_game_settled_observer('wp_xp_core', [self::class, 'settled']);
        } else {
            add_action('site_tools_game_settled', [self::class, 'settled'], 10, 2);
        }
    }
    public static function settled(string $run, int $uid): void
    {
        try {
            $receipt = site_tools_game_settled_receipt($run, $uid);
            if (!is_array($receipt) || !isset(self::$providers[$receipt['slug']])) {
                return;
            }
            $result = wp_xp_core_record_activity(
                self::$providers[$receipt['slug']],
                $run,
                $uid,
                0,
                ['run' => $run],
            );
            if (
                is_wp_error($result) &&
                !in_array(
                    $result->get_error_code(),
                    ['disabled', 'quota', 'threshold', 'historical', 'ineligible'],
                    true,
                )
            ) {
                error_log('WP XP Core game activity failed: ' . $result->get_error_code());
            }
        } catch (Throwable $e) {
            error_log('WP XP Core game activity failed: exception');
        }
    }
}
add_action('init', [WP_XP_Core_Game_Activities::class, 'boot'], 30);
