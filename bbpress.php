<?php
/** Optional bbPress activity adapter; never scans or adopts historical content. */
defined('ABSPATH') || exit();
final class WP_XP_Core_BBPress
{
    private static array $created = [];
    private static array $deleting = [];
    public static function boot(): void
    {
        if (
            !function_exists('bbp_get_topic_post_type') ||
            !function_exists('bbp_is_forum_public')
        ) {
            return;
        }
        foreach (['topic', 'reply'] as $type) {
            wp_xp_core_register_activity_provider('bbpress_' . $type, [
                'label' =>
                    $type === 'topic'
                        ? wp_xp_core_t('bbPress 公开主题')
                        : wp_xp_core_t('bbPress 公开回复'),
                'authorize' => static fn($uid, $id, $context) => isset(self::$created[$id]) &&
                    self::$created[$id] === $uid &&
                    self::eligible($type, $uid, $id),
                'revoke_authorize' => static function ($uid, $id, $context) use ($type) {
                    $post = get_post($id);
                    if (
                        !$post ||
                        $post->post_type !==
                            ($type === 'topic'
                                ? bbp_get_topic_post_type()
                                : bbp_get_reply_post_type())
                    ) {
                        return false;
                    }
                    return isset(self::$deleting[$id]) ||
                        !self::eligible($type, (int) $post->post_author, $id) ||
                        (current_user_can('manage_options') && current_user_can('edit_post', $id));
                },
            ]);
            foreach (['bbp_new_' . $type, 'bbp_insert_' . $type] as $hook) {
                add_action(
                    $hook,
                    static function ($id) use ($type) {
                        self::reward($type, (int) $id);
                    },
                    100,
                    1,
                );
            }
        }
        add_action(
            'wp_insert_post',
            static function ($id, $post, $update) {
                if (
                    $update ||
                    !in_array(
                        $post->post_type,
                        [bbp_get_topic_post_type(), bbp_get_reply_post_type()],
                        true,
                    )
                ) {
                    return;
                }
                $provider =
                    $post->post_type === bbp_get_topic_post_type()
                        ? 'bbpress_topic'
                        : 'bbpress_reply';
                if (!empty(WP_XP_Core_Settings::rules()['providers'][$provider]['enabled'])) {
                    self::$created[(int) $id] = (int) $post->post_author;
                }
            },
            100,
            3,
        );
        // Only the directly changed object is reversed. Parent changes never scan descendants.
        add_action(
            'transition_post_status',
            static function ($new, $old, $post) {
                if ($new === $old || $old === 'new') {
                    return;
                }
                $type =
                    $post->post_type === bbp_get_topic_post_type()
                        ? 'topic'
                        : ($post->post_type === bbp_get_reply_post_type()
                            ? 'reply'
                            : '');
                if ($type && !self::eligible($type, (int) $post->post_author, (int) $post->ID)) {
                    self::reverse($type, $post);
                }
            },
            100,
            3,
        );
        add_action(
            'before_delete_post',
            static function ($id, $post) {
                $type =
                    $post->post_type === bbp_get_topic_post_type()
                        ? 'topic'
                        : ($post->post_type === bbp_get_reply_post_type()
                            ? 'reply'
                            : '');
                if ($type) {
                    self::$deleting[(int) $id] = true;
                    try {
                        self::reverse($type, $post);
                    } finally {
                        unset(self::$deleting[(int) $id]);
                    }
                }
            },
            100,
            2,
        );
    }
    private static function owned(string $type, int $uid, int $id): bool
    {
        $post = get_post($id);
        return $post &&
            $uid > 0 &&
            (int) $post->post_author === $uid &&
            $post->post_type ===
                ($type === 'topic' ? bbp_get_topic_post_type() : bbp_get_reply_post_type());
    }
    private static function public_post($post): bool
    {
        return $post &&
            $post->post_password === '' &&
            in_array(
                $post->post_status,
                [bbp_get_public_status_id(), bbp_get_closed_status_id()],
                true,
            );
    }
    private static function eligible(string $type, int $uid, int $id): bool
    {
        if (
            !self::owned($type, $uid, $id) ||
            !get_userdata($uid) ||
            !self::public_post(get_post($id))
        ) {
            return false;
        }
        if ($type === 'topic' ? bbp_is_topic_anonymous($id) : bbp_is_reply_anonymous($id)) {
            return false;
        }
        $topic = $type === 'topic' ? $id : (int) bbp_get_reply_topic_id($id);
        $forum =
            $type === 'topic'
                ? (int) bbp_get_topic_forum_id($id)
                : (int) bbp_get_reply_forum_id($id);
        return $topic > 0 &&
            $forum > 0 &&
            self::public_post(get_post($topic)) &&
            self::public_post(get_post($forum)) &&
            bbp_is_forum_public($forum, true) &&
            (int) bbp_get_topic_forum_id($topic) === $forum;
    }
    private static function report($result, string $type, int $id): void
    {
        if (
            !is_wp_error($result) ||
            in_array(
                $result->get_error_code(),
                ['disabled', 'ineligible', 'missing', 'input'],
                true,
            )
        ) {
            return;
        }
        error_log('WP XP Core bbPress activity failed: ' . $result->get_error_code());
        $rows = get_option('wp_xp_core_bbpress_failures', []);
        $rows = is_array($rows) ? $rows : [];
        $rows[] = [
            'type' => $type,
            'object_id' => $id,
            'code' => $result->get_error_code(),
            'time' => time(),
        ];
        update_option('wp_xp_core_bbpress_failures', array_slice($rows, -20), false);
    }
    private static function reward(string $type, int $id): void
    {
        if (!isset(self::$created[$id])) {
            return;
        }
        self::report(
            wp_xp_core_record_activity('bbpress_' . $type, (string) $id, self::$created[$id], $id),
            $type,
            $id,
        );
    }
    private static function reverse(string $type, $post): void
    {
        self::report(
            WP_XP_Core_Providers::revoke_registered_receipt(
                'bbpress_' . $type,
                (string) $post->ID,
                (int) $post->ID,
            ),
            $type,
            (int) $post->ID,
        );
    }
}
add_action('plugins_loaded', [WP_XP_Core_BBPress::class, 'boot'], 30);
