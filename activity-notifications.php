<?php
/** Activity messages are durable inbox facts; optional channels run only after commit. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Activity_Notifications
{
    private static array $channels = [];
    public static function register(string $id, array $definition): bool
    {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
            isset(self::$channels[$id]) ||
            count(self::$channels) >= 10 ||
            array_diff(array_keys($definition), ['label', 'available', 'deliver']) ||
            !is_string($definition['label'] ?? null) ||
            mb_strlen($definition['label']) > 80 ||
            !is_callable($definition['available'] ?? null) ||
            !is_callable($definition['deliver'] ?? null)
        ) {
            return false;
        }
        self::$channels[$id] = $definition;
        return true;
    }
    public static function channels(): array
    {
        return self::$channels;
    }
    public static function template(): string
    {
        return wp_xp_core_t('完成{activity}，本次经验+{xp}');
    }
    public static function render(string $template, string $activity, int $xp, ?int $score): string
    {
        return strtr($template, [
            '{activity}' => $activity,
            '{xp}' => (string) $xp,
            '{score}' => $score === null ? '—' : (string) $score,
        ]);
    }
    public static function snapshot(string $kind, int $xp, array &$detail): void
    {
        if (
            !in_array($kind, ['provider_reward', 'provider_revoke'], true) ||
            !is_string($detail['provider'] ?? null)
        ) {
            return;
        }
        $policy = WP_XP_Core_Settings::rules()['providers'][$detail['provider']] ?? [];
        $channels = $policy['channels'] ?? [];
        $inbox = $policy['inbox'] ?? false;
        if (!$inbox && !$channels) {
            return;
        }
        $label = (string) ($detail['provider_label'] ?? '');
        $message =
            $kind === 'provider_revoke'
                ? self::render(wp_xp_core_t('已撤销{activity}，本次经验{xp}'), $label, $xp, null)
                : self::render(
                    $policy['template'] ?? self::template(),
                    $label,
                    $xp,
                    is_int($detail['activity_score'] ?? null) ? $detail['activity_score'] : null,
                );
        $detail['activity_message'] = ['title' => $label, 'message' => $message];
        $detail['activity_inbox'] = $inbox;
        $detail['activity_channels'] = $channels;
    }
    public static function deliver(array $event): void
    {
        // Isolate all post-commit delivery failures so later task observers still run.
        try {
            WP_XP_Core_Notification_Delivery::committed($event);
        } catch (Throwable $e) {
            error_log('WP XP Core channel delivery unavailable');
        }
    }
}
function wp_xp_core_register_notification_channel(string $id, array $definition): bool
{
    return WP_XP_Core_Activity_Notifications::register($id, $definition);
}
add_action('wp_xp_core_event_committed', [WP_XP_Core_Activity_Notifications::class, 'deliver']);
