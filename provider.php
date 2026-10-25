<?php
/** Server-only activity providers; no REST route accepts identities or reward amounts. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Providers
{
    private static array $providers = [];
    public static function register(string $id, array $definition): bool
    {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
            isset(self::$providers[$id]) ||
            count(self::$providers) >= 20 ||
            array_diff(array_keys($definition), ['label', 'authorize']) ||
            !is_string($definition['label'] ?? null) ||
            mb_strlen($definition['label']) > 80 ||
            !is_callable($definition['authorize'] ?? null)
        ) {
            return false;
        }
        self::$providers[$id] = $definition;
        return true;
    }
    public static function definitions(): array
    {
        return self::$providers;
    }
    public static function record(
        string $provider,
        string $event,
        int $uid,
        int $object_id = 0,
        array $context = [],
    ) {
        if (
            !isset(self::$providers[$provider]) ||
            $event === '' ||
            strlen($event) > 191 ||
            $uid < 1 ||
            $object_id < 0 ||
            !get_userdata($uid) ||
            (is_multisite() && !is_user_member_of_blog($uid))
        ) {
            return new WP_Error('input', wp_xp_core_t('活动提供者或目标无效。'));
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'));
        }
        try {
            return Reader_Experience::transaction(function () use (
                $provider,
                $event,
                $uid,
                $object_id,
                $context,
            ) {
                $definition = self::$providers[$provider];
                $policy = WP_XP_Core_Settings::rules()['providers'][$provider] ?? [
                    'enabled' => false,
                    'xp' => 0,
                ];
                if (!$policy['enabled']) {
                    return new WP_Error('disabled', wp_xp_core_t('活动奖励未启用。'));
                }
                if (
                    !Reader_Experience::ready() ||
                    call_user_func($definition['authorize'], $uid, $object_id, $context) !== true
                ) {
                    return new WP_Error('ineligible', wp_xp_core_t('活动未通过服务端资格检查。'), [
                        'status' => 403,
                    ]);
                }
                $key = 'provider:' . $provider . ':' . hash('sha256', $event);
                global $wpdb;
                $old = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT id,user_id,object_id FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $key,
                    ),
                    ARRAY_A,
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Provider lookup failed');
                }
                if ($old) {
                    if ((int) $old['user_id'] !== $uid || (int) $old['object_id'] !== $object_id) {
                        return new WP_Error('conflict', wp_xp_core_t('活动编号归属冲突。'), [
                            'status' => 409,
                        ]);
                    }
                    return ['event_id' => (int) $old['id'], 'duplicate' => true];
                }
                if (
                    !Reader_Experience::record(
                        $key,
                        $uid,
                        'provider_reward',
                        $object_id,
                        $policy['xp'],
                        Reader_Experience::day(),
                        ['provider' => $provider, 'provider_label' => $definition['label']],
                    )
                ) {
                    throw new RuntimeException('Provider reward failed');
                }
                $recorded = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT id,user_id,object_id,kind FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $key,
                    ),
                    ARRAY_A,
                );
                if (
                    $wpdb->last_error !== '' ||
                    !is_array($recorded) ||
                    (int) $recorded['id'] < 1 ||
                    (int) $recorded['user_id'] !== $uid ||
                    (int) $recorded['object_id'] !== $object_id ||
                    $recorded['kind'] !== 'provider_reward'
                ) {
                    throw new RuntimeException('Provider receipt unavailable');
                }
                return ['event_id' => (int) $recorded['id'], 'duplicate' => false];
            });
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('活动未能记录，请重试。'), [
                'status' => 503,
            ]);
        }
    }
}
function wp_xp_core_register_activity_provider(string $id, array $definition): bool
{
    return WP_XP_Core_Providers::register($id, $definition);
}
function wp_xp_core_record_activity(
    string $provider,
    string $event,
    int $user_id,
    int $object_id = 0,
    array $context = [],
) {
    return WP_XP_Core_Providers::record($provider, $event, $user_id, $object_id, $context);
}
