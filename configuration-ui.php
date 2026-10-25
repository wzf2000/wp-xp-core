<?php
/** Administrator-only preview and explicit application of portable configuration. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Configuration_UI
{
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            wp_xp_core_t('配置工具'),
            wp_xp_core_t('配置工具'),
            'manage_options',
            'wp-xp-configuration',
            [self::class, 'page'],
        );
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        nocache_headers();
        WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
        $nonce = $_POST['_wpnonce'] ?? '';
        $json = '';
        $mode = 'update';
        $result = null;
        $preview = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
            $json = is_string($_POST['configuration'] ?? null)
                ? wp_unslash($_POST['configuration'])
                : '';
            $mode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : 'update';
            if ($action === 'updates') {
                $result = WP_XP_Core_Updates::save(
                    [
                        'enabled' => isset($_POST['enabled']),
                        'channel' => $_POST['channel'] ?? '',
                        'revision' => $_POST['revision'] ?? '',
                    ],
                    $nonce,
                );
            } elseif ($action === 'export') {
                $result = WP_XP_Core_Configuration::export($nonce);
                if (!is_wp_error($result)) {
                    $json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $result = null;
                }
            } elseif ($action === 'template') {
                $auth = WP_XP_Core_Configuration::export($nonce);
                if (is_wp_error($auth)) {
                    $result = $auth;
                } else {
                    try {
                        $json = WP_XP_Core_Configuration::template_document(
                            is_string($_POST['template'] ?? null) ? $_POST['template'] : '',
                        );
                        $mode = 'template';
                        $preview = WP_XP_Core_Configuration::preview($json, $mode, $nonce);
                    } catch (Throwable $e) {
                        $result = new WP_Error('input', wp_xp_core_t('未知模板。'));
                    }
                }
            } elseif ($action === 'preview') {
                $preview = WP_XP_Core_Configuration::preview($json, $mode, $nonce);
            } elseif ($action === 'apply') {
                $revision = is_string($_POST['revision'] ?? null)
                    ? json_decode(wp_unslash($_POST['revision']), true)
                    : null;
                $result =
                    is_array($revision) && is_string($_POST['proof'] ?? null)
                        ? WP_XP_Core_Configuration::apply(
                            $json,
                            $mode,
                            $revision,
                            $_POST['proof'],
                            $nonce,
                        )
                        : new WP_Error('input', wp_xp_core_t('请先预览本次配置。'));
            } else {
                $result = new WP_Error('input', wp_xp_core_t('未知操作。'));
            }
        }
        echo '<div class="wrap wp-xp-core-admin"><h1>' .
            esc_html(wp_xp_core_t('配置工具')) .
            '</h1>';
        WP_XP_Core_Management_UI::nav('configuration');
        foreach ([$result, $preview] as $notice) {
            if (is_wp_error($notice)) {
                echo '<div class="notice notice-error"><p>' .
                    esc_html($notice->get_error_message()) .
                    '</p></div>';
            }
        }
        if (is_array($result) && !empty($result['applied'])) {
            echo '<div class="notice notice-success"><p>' .
                esc_html(wp_xp_core_t('配置已应用。')) .
                '</p></div>';
        }
        if ($result === true) {
            echo '<div class="notice notice-success"><p>' .
                esc_html(wp_xp_core_t('更新偏好已保存。')) .
                '</p></div>';
        }
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('配置导出与导入')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '只包含规则、成长与任务。不会导出账号、通知、审计、余额、账本或存储配置。导入保留未列出的任务，不删除历史；先预览，再显式应用。跨站内容 ID 和图片 ID 需要先核对。',
                ),
            ) .
            '</p><form method="post">';
        wp_nonce_field('wp_xp_core_configuration');
        echo '<input type="hidden" name="action" value="export"><button class="button">' .
            esc_html(wp_xp_core_t('生成配置 JSON')) .
            '</button></form><form method="post">';
        wp_nonce_field('wp_xp_core_configuration');
        echo '<label for="xp-config-document">' .
            esc_html(wp_xp_core_t('配置 JSON')) .
            '</label><textarea id="xp-config-document" name="configuration" rows="14" class="large-text code" maxlength="' .
            WP_XP_Core_Configuration::MAX_BYTES .
            '" required>' .
            esc_textarea($json) .
            '</textarea><p><label>' .
            esc_html(wp_xp_core_t('导入方式 ')) .
            '<select name="mode"><option value="update"' .
            selected($mode, 'update', false) .
            '>' .
            esc_html(wp_xp_core_t('同站更新（匹配已保存 ID）')) .
            '</option><option value="template"' .
            selected($mode, 'template', false) .
            '>' .
            esc_html(wp_xp_core_t('外站模板（重新映射 ID）')) .
            '</option></select></label></p>';
        if (is_array($preview)) {
            echo '<h3>' .
                esc_html(wp_xp_core_t('预览')) .
                '</h3><p>' .
                esc_html(wp_xp_core_t('规则变化：')) .
                ($preview['rules_changed'] ? wp_xp_core_t('是') : wp_xp_core_t('否')) .
                wp_xp_core_t('；成长变化：') .
                ($preview['growth_changed'] ? wp_xp_core_t('是') : wp_xp_core_t('否')) .
                '</p><ul>';
            echo '</ul><details><summary>' .
                esc_html(wp_xp_core_t('里程碑变更')) .
                '</summary><pre>' .
                esc_html(
                    wp_json_encode(
                        $preview['milestones'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                    ),
                ) .
                '</pre></details><ul>';
            foreach ($preview['differences'] as $group => $rows) {
                echo '<h4>' .
                    esc_html($group) .
                    '</h4><table class="widefat xp-configuration-diff"><thead><tr><th>' .
                    esc_html(wp_xp_core_t('设置')) .
                    '</th><th>' .
                    esc_html(wp_xp_core_t('原值')) .
                    '</th><th>' .
                    esc_html(wp_xp_core_t('新值')) .
                    '</th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    echo '<tr><th scope="row">' .
                        esc_html($row['field']) .
                        '</th><td>' .
                        esc_html(wp_json_encode($row['before'], JSON_UNESCAPED_UNICODE)) .
                        '</td><td>' .
                        esc_html(wp_json_encode($row['after'], JSON_UNESCAPED_UNICODE)) .
                        '</td></tr>';
                }
                echo '</tbody></table>';
            }
            $labels = [
                'new' => wp_xp_core_t('新建'),
                'modified' => wp_xp_core_t('修改展示'),
                'new_version' => wp_xp_core_t('建立新版本'),
                'unchanged' => wp_xp_core_t('未改变'),
                'preserved' => wp_xp_core_t('保留'),
            ];
            foreach ($preview['changes'] as $change) {
                echo '<li>' .
                    esc_html($change['title']) .
                    ' · ' .
                    esc_html($labels[$change['status']]) .
                    ' · v' .
                    (int) $change['version'] .
                    (isset($change['condition_text'])
                        ? '<p>' .
                            esc_html($change['description']) .
                            ' · ' .
                            esc_html($change['condition_text']) .
                            ' · ' .
                            esc_html(wp_xp_core_t('奖励经验')) .
                            ' ' .
                            (int) $change['reward_xp'] .
                            '</p>'
                        : '') .
                    '</li>';
            }
            echo '</ul><input type="hidden" name="revision" value="' .
                esc_attr(wp_json_encode($preview['revision'])) .
                '"><input type="hidden" name="proof" value="' .
                esc_attr($preview['proof']) .
                '"><button class="button button-primary" name="action" value="apply">' .
                esc_html(wp_xp_core_t('应用已预览配置')) .
                '</button> ';
        }
        echo '<button class="button" name="action" value="preview">' .
            esc_html(wp_xp_core_t('预览配置')) .
            '</button></form></section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('任务模板')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '模板只供预览，不会自动创建任务。重复使用同一模板更新其已导入任务，不重新发放已领取奖励。',
                ),
            ) .
            '</p><form method="post">';
        wp_nonce_field('wp_xp_core_configuration');
        echo '<input type="hidden" name="action" value="template"><label>' .
            esc_html(wp_xp_core_t('选择模板 ')) .
            '<select name="template">';
        foreach (WP_XP_Core_Configuration::templates() as $key => $template) {
            echo '<option value="' .
                esc_attr($key) .
                '">' .
                esc_html($template['title']) .
                '</option>';
        }
        echo '</select></label> <button class="button">' .
            esc_html(wp_xp_core_t('预览模板')) .
            '</button></form></section>';
        if (function_exists('is_multisite') && is_multisite()) {
            echo '<section class="xp-card"><h2>' .
                esc_html(wp_xp_core_t('GitHub 更新检查')) .
                '</h2><p>' .
                esc_html(
                    wp_xp_core_t('多站点网络请由网络管理员手动更新；本站不提供共享更新器设置。'),
                ) .
                '</p></section></div>';
            return;
        }
        $updates = WP_XP_Core_Updates::settings();
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('GitHub 更新检查')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '默认关闭。仅检查 wzf2000/wp-xp-core 官方安装 ZIP；稳定渠道忽略预发布，预发布渠道同时接受更新的稳定版。不会降级，不新增定时任务。缓存最多六小时。',
                ),
            ) .
            '</p><form method="post">';
        wp_nonce_field('wp_xp_core_configuration');
        echo '<input type="hidden" name="action" value="updates"><input type="hidden" name="revision" value="' .
            esc_attr(WP_XP_Core_Updates::revision()) .
            '"><label><input type="checkbox" name="enabled"' .
            checked($updates['enabled'], true, false) .
            '>' .
            esc_html(wp_xp_core_t('启用官方更新检查')) .
            '</label><p><label>' .
            esc_html(wp_xp_core_t('渠道 ')) .
            '<select name="channel"><option value="stable"' .
            selected($updates['channel'], 'stable', false) .
            '>' .
            esc_html(wp_xp_core_t('稳定版')) .
            '</option><option value="prerelease"' .
            selected($updates['channel'], 'prerelease', false) .
            '>' .
            esc_html(wp_xp_core_t('稳定版与预发布')) .
            '</option></select></label></p><button class="button">' .
            esc_html(wp_xp_core_t('保存更新偏好')) .
            '</button></form></section></div>';
    }
}
add_action('admin_menu', [WP_XP_Core_Configuration_UI::class, 'menu']);
