<?php
/** One source at a time: trusted activity, reward rule, private message, preview/save. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Activities_UI
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }
    public static function assets($hook): void
    {
        if ($hook === 'settings_page_wp-xp-activities') {
            WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
        }
    }
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            wp_xp_core_t('活动与通知'),
            wp_xp_core_t('活动与通知'),
            'manage_options',
            'wp-xp-activities',
            [self::class, 'page'],
        );
    }
    private static function draft($raw, array $fallback): array
    {
        if (!is_array($raw)) {
            return $fallback;
        }
        foreach (['xp', 'day_limit', 'min_score', 'template'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $fallback[$key] = mb_substr((string) $raw[$key], 0, $key === 'template' ? 200 : 16);
            }
        }
        foreach (['enabled', 'inbox'] as $key) {
            $fallback[$key] = in_array($raw[$key] ?? false, [true, 1, '1'], true);
        }
        if (is_array($raw['channels'] ?? null)) {
            $fallback['channels'] = array_values(
                array_filter(
                    array_slice($raw['channels'], 0, 10),
                    static fn($id) => is_string($id) &&
                        preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id),
                ),
            );
        } else {
            $fallback['channels'] = [];
        }
        return $fallback;
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有管理设置的权限。'));
        }
        $definitions = WP_XP_Core_Providers::definitions();
        $rules = WP_XP_Core_Settings::rules();
        $ids = array_unique(array_merge(array_keys($definitions), array_keys($rules['providers'])));
        $source = is_string($_REQUEST['source'] ?? null)
            ? sanitize_text_field(wp_unslash($_REQUEST['source']))
            : '';
        if (!in_array($source, $ids, true)) {
            $source = $ids[0] ?? '';
        }
        $policy = $rules['providers'][$source] ?? [
            'enabled' => false,
            'xp' => 0,
            'day_limit' => 0,
            'min_score' => 0,
            'inbox' => false,
            'template' => WP_XP_Core_Activity_Notifications::template(),
            'channels' => [],
        ];
        $result = null;
        $preview = false;
        $unlimited = !array_key_exists('day_limit', $policy) || $policy['day_limit'] === null;
        $limit_mode = $unlimited ? 'unlimited' : 'limited';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_xp_core_settings')) {
                    throw new InvalidArgumentException(wp_xp_core_t('验证已过期，请刷新页面。'));
                }
                $raw = wp_unslash($_POST['activity'] ?? []);
                $limit_mode =
                    $unlimited && is_array($raw) && ($raw['limit_mode'] ?? '') === 'unlimited'
                        ? 'unlimited'
                        : 'limited';
                if (!is_array($raw) || !isset($definitions[$source])) {
                    throw new InvalidArgumentException(wp_xp_core_t('活动来源尚未加载。'));
                }
                $legacy = $unlimited;
                $draft = self::draft($raw, $policy);
                $policy = [
                    'enabled' => $raw['enabled'] ?? false,
                    'xp' => $raw['xp'] ?? null,
                    'day_limit' =>
                        $legacy && ($raw['limit_mode'] ?? '') === 'unlimited'
                            ? null
                            : $raw['day_limit'] ?? null,
                    'inbox' => $raw['inbox'] ?? false,
                    'template' => $raw['template'] ?? '',
                    'channels' => $raw['channels'] ?? [],
                ];
                if (($definitions[$source]['fields'] ?? []) === ['score']) {
                    $policy['min_score'] = $raw['min_score'] ?? 0;
                }
                $candidate = $rules;
                $candidate['providers'][$source] = $policy;
                $candidate = WP_XP_Core_Settings::validate($candidate);
                $policy = $candidate['providers'][$source];
                $revision = $_POST['revision'] ?? '';
                if (
                    !is_string($revision) ||
                    !hash_equals(
                        WP_XP_Core_Settings::revision(
                            get_option(WP_XP_Core_Settings::OPTION, null),
                        ),
                        $revision,
                    )
                ) {
                    throw new InvalidArgumentException(
                        wp_xp_core_t('设置已被其他管理员更新，请刷新后重新修改。'),
                    );
                }
                if (($_POST['operation'] ?? '') === 'preview') {
                    $preview = true;
                } else {
                    $result = WP_XP_Core_Settings::save(
                        $candidate,
                        $_POST['_wpnonce'] ?? '',
                        $revision,
                    );
                }
            } catch (InvalidArgumentException $e) {
                $policy = $draft ?? $policy;
                $result = new WP_Error('input', $e->getMessage());
            }
        }
        echo '<div class="wrap wp-xp-core-admin xp-activities"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>' .
            esc_html(wp_xp_core_t('活动与通知')) .
            '</h1></div></header>';
        WP_XP_Core_Management_UI::nav('activities');
        WP_XP_Core_Growth_UI::notice($result);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('选择可信活动来源')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '只配置已安装扩展提供的服务端活动，不执行自定义代码。来源未加载时保留原设置。最多注册二十项活动。',
                ),
            ) .
            '</p><form method="get"><input type="hidden" name="page" value="wp-xp-activities"><label class="xp-field">' .
            esc_html(wp_xp_core_t('活动来源')) .
            '<select name="source">';
        foreach ($ids as $id) {
            echo '<option value="' .
                esc_attr($id) .
                '"' .
                selected($source, $id, false) .
                '>' .
                esc_html($definitions[$id]['label'] ?? $id) .
                '</option>';
        }
        echo '</select></label><button class="button">' .
            esc_html(wp_xp_core_t('查看规则')) .
            '</button></form><p>' .
            esc_html(
                function_exists('site_tools_game_settled_receipt')
                    ? wp_xp_core_t('已检测到游戏结算适配能力。')
                    : wp_xp_core_t(
                        '游戏扩展未加载或尚不支持结算适配；升级适配包后可选择已验证游戏。',
                    ),
            ) .
            '</p>';
        if (WP_XP_Core_Game_Activities::overflow()) {
            echo '<p>' .
                esc_html(
                    wp_xp_core_t(
                        '活动数量超过二十项，部分游戏未注册。请由扩展维护者减少来源数量。',
                    ),
                ) .
                '</p>';
        }
        if (!isset($definitions[$source])) {
            echo '<p>' . esc_html(wp_xp_core_t('活动来源尚未加载。')) . '</p></section></div>';
            return;
        }
        echo '<p>' .
            esc_html($definitions[$source]['description'] ?? '') .
            '</p><p>' .
            esc_html(
                WP_XP_Core_Providers::available($source)
                    ? wp_xp_core_t('来源可用')
                    : wp_xp_core_t('来源暂不可用，设置仍保留。'),
            ) .
            '</p></section><section class="xp-card"><form method="post">';
        wp_nonce_field('wp_xp_core_settings');
        echo '<input type="hidden" name="source" value="' .
            esc_attr($source) .
            '"><input type="hidden" name="revision" value="' .
            esc_attr(WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null))) .
            '"><h2>' .
            esc_html(wp_xp_core_t('设置经验规则')) .
            '</h2><label class="xp-field"><input type="hidden" name="activity[enabled]" value="0"><input type="checkbox" name="activity[enabled]" value="1"' .
            checked($policy['enabled'], true, false) .
            '>' .
            esc_html(wp_xp_core_t('启用此活动奖励')) .
            '</label><div class="xp-fields xp-fields-three"><label class="xp-field">' .
            esc_html(wp_xp_core_t('每次固定经验')) .
            '<input type="number" min="0" max="1000000" name="activity[xp]" required value="' .
            esc_attr((string) $policy['xp']) .
            '"></label>';
        $legacy = $unlimited;
        if ($legacy) {
            echo '<label class="xp-field">' .
                esc_html(wp_xp_core_t('每日次数模式')) .
                '<select name="activity[limit_mode]"><option value="unlimited"' .
                selected($limit_mode, 'unlimited', false) .
                '>' .
                esc_html(wp_xp_core_t('不限（保留原规则）')) .
                '</option><option value="limited"' .
                selected($limit_mode, 'limited', false) .
                '>' .
                esc_html(wp_xp_core_t('按填写次数')) .
                '</option></select></label>';
        }
        echo '<label class="xp-field">' .
            esc_html(wp_xp_core_t('每日奖励次数，0 表示停止')) .
            '<input type="number" min="0" max="100000" name="activity[day_limit]" value="' .
            esc_attr((string) ($policy['day_limit'] ?? 0)) .
            '" required></label>';
        if (($definitions[$source]['fields'] ?? []) === ['score']) {
            echo '<label class="xp-field">' .
                esc_html(wp_xp_core_t('最低分数')) .
                '<input type="number" min="0" max="1000000000" name="activity[min_score]" value="' .
                esc_attr((string) ($policy['min_score'] ?? 0)) .
                '" required></label>';
        }
        echo '</div><h2>' .
            esc_html(wp_xp_core_t('设置活动通知')) .
            '</h2><label class="xp-field"><input type="hidden" name="activity[inbox]" value="0"><input type="checkbox" name="activity[inbox]" value="1"' .
            checked($policy['inbox'] ?? false, true, false) .
            '>' .
            esc_html(wp_xp_core_t('发送私人站内通知')) .
            '</label><label class="xp-field">' .
            esc_html(wp_xp_core_t('通知文案')) .
            '<input name="activity[template]" maxlength="200" value="' .
            esc_attr($policy['template'] ?? WP_XP_Core_Activity_Notifications::template()) .
            '"></label><p>{activity} · {xp} · {score}</p><details><summary>' .
            esc_html(wp_xp_core_t('其他通知渠道（可选）')) .
            '</summary><p>' .
            esc_html(
                wp_xp_core_t(
                    '由扩展注册，默认关闭。仅新记账后尝试一次，不保证送达，不自动重试；活动通知偏好关闭时不会发送。',
                ),
            ) .
            '</p>';
        $channels = WP_XP_Core_Activity_Notifications::channels();
        foreach ($channels as $id => $channel) {
            echo '<label class="xp-field"><input type="checkbox" name="activity[channels][]" value="' .
                esc_attr($id) .
                '"' .
                checked(in_array($id, $policy['channels'] ?? [], true), true, false) .
                '>' .
                esc_html($channel['label']) .
                '</label>';
        }
        foreach (array_diff($policy['channels'] ?? [], array_keys($channels)) as $id) {
            echo '<input type="hidden" name="activity[channels][]" value="' . esc_attr($id) . '">';
        }
        echo '</details><p>' .
            esc_html(
                wp_xp_core_t(
                    '启用或修改经验、次数、最低分后，只累计新活动。文案修改不重启奖励，原事件不会重复发放。',
                ),
            ) .
            '</p><button class="button" name="operation" value="preview">' .
            esc_html(wp_xp_core_t('预览规则与通知')) .
            '</button> <button class="button button-primary" name="operation" value="save">' .
            esc_html(wp_xp_core_t('保存活动规则')) .
            '</button></form>';
        if ($preview) {
            echo '<div class="notice notice-info"><p>' .
                esc_html(wp_xp_core_t('仅预览，尚未保存。')) .
                '</p><p>' .
                esc_html(
                    WP_XP_Core_Activity_Notifications::render(
                        $policy['template'] ?? WP_XP_Core_Activity_Notifications::template(),
                        $definitions[$source]['label'],
                        (int) $policy['xp'],
                        isset($policy['min_score']) ? (int) $policy['min_score'] : null,
                    ),
                ) .
                '</p><p>' .
                esc_html(wp_xp_core_t('实际经验受活动上限和临时暂停影响；为零时如实显示 +0。')) .
                '</p></div>';
        }
        $results = get_option('wp_xp_core_channel_results', []);
        if ($results) {
            echo '<details><summary>' .
                esc_html(wp_xp_core_t('最近渠道结果')) .
                '</summary><pre>' .
                esc_html(wp_json_encode($results, JSON_PRETTY_PRINT)) .
                '</pre></details>';
        }
        echo '</section></div>';
    }
}
WP_XP_Core_Activities_UI::boot();
