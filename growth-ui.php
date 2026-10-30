<?php
/** Growth settings and private user panel. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Growth_UI
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('rest_api_init', [self::class, 'routes']);
    }
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            wp_xp_core_t('成长中心设置'),
            wp_xp_core_t('成长中心'),
            'manage_options',
            'wp-xp-growth',
            [self::class, 'settings_page'],
        );
    }
    public static function assets($hook): void
    {
        if (
            !in_array(
                $hook,
                ['settings_page_wp-xp-growth', 'settings_page_wp-xp-achievements'],
                true,
            )
        ) {
            return;
        }
        WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
        if ($hook === 'settings_page_wp-xp-achievements' && current_user_can('upload_files')) {
            wp_enqueue_media();
        }
        $assets = json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true);
        wp_enqueue_script(
            'wp-xp-core-growth-admin',
            plugins_url('assets/' . $assets['growth_admin_js'], __FILE__),
            [],
            Reader_Experience::VERSION,
            true,
        );
    }
    public static function settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有管理成长中心的权限。'));
        }
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = wp_unslash($_POST['growth'] ?? []);
            if (is_array($input)) {
                $input['streak'] = is_array($input['streak'] ?? null) ? $input['streak'] : [];
                $input['streak']['rewards'] = $input['streak']['rewards'] ?? [];
                $result = WP_XP_Core_Growth::save(
                    $input,
                    $_POST['_wpnonce'] ?? '',
                    $_POST['revision'] ?? '',
                );
            } else {
                $result = new WP_Error('input', wp_xp_core_t('成长设置无效。'));
            }
        }
        $settings = WP_XP_Core_Growth::settings();
        $levels = WP_XP_Core_Settings::rules()['levels'];
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>' .
            esc_html(wp_xp_core_t('成长中心')) .
            '</h1><p>' .
            esc_html(wp_xp_core_t('为等级赋予名称，让签到进度清楚可见。')) .
            '</p></div></header>';
        WP_XP_Core_Management_UI::nav('growth');
        self::notice($result);
        echo '<form method="post" class="xp-growth-form">';
        wp_nonce_field('wp_xp_core_growth');
        echo '<input type="hidden" name="revision" value="' .
            esc_attr(WP_XP_Core_Growth::revision()) .
            '"><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('等级资料')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '最低经验在“经验规则”中设置。这里可修改每级的名称、图标字符、颜色和说明。',
                ),
            ) .
            '</p><div class="xp-level-profiles">';
        foreach ($levels as $index => $minimum) {
            $n = $index + 1;
            $profile = WP_XP_Core_Growth::level_profile($n);
            echo '<fieldset class="xp-profile-row"><legend>Level ' .
                $n .
                ' · ' .
                $minimum .
                ' 经验起</legend>';
            foreach (
                [
                    'name' => [wp_xp_core_t('名称'), 40],
                    'icon' => [wp_xp_core_t('图标字符'), 8],
                    'description' => [wp_xp_core_t('简短说明'), 160],
                ]
                as $key => [$label, $max]
            ) {
                echo '<label>' .
                    $label .
                    '<input name="growth[levels][' .
                    $n .
                    '][' .
                    $key .
                    ']" maxlength="' .
                    $max .
                    '" value="' .
                    esc_attr($profile[$key]) .
                    '"></label>';
            }
            echo '<label>' .
                esc_html(wp_xp_core_t('颜色')) .
                '<input type="color" name="growth[levels][' .
                $n .
                '][color]" value="' .
                esc_attr($profile['color']) .
                '"></label></fieldset>';
        }
        echo '</div></section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('签到成长奖励')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '可在每日签到之外设置阶段奖励。默认关闭；启用或修改规则后，从下一次新签到开始累计，不补发旧签到。',
                ),
            ) .
            '</p><input type="hidden" name="growth[streak][enabled]" value="0"><label class="xp-toggle"><input name="growth[streak][enabled]" type="checkbox" value="1"' .
            checked($settings['streak']['enabled'], true, false) .
            '>' .
            esc_html(wp_xp_core_t('启用阶段奖励')) .
            '</label><label class="xp-select-label">' .
            esc_html(wp_xp_core_t('累计方式')) .
            '<select name="growth[streak][mode]"><option value="consecutive"' .
            selected($settings['streak']['mode'], 'consecutive', false) .
            '>' .
            esc_html(wp_xp_core_t('连续签到')) .
            '</option><option value="cumulative"' .
            selected($settings['streak']['mode'], 'cumulative', false) .
            '>' .
            esc_html(wp_xp_core_t('累计签到')) .
            '</option></select></label><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '连续签到断开后重新累计，不扣除已获得经验。每档奖励在这一版规则下只领取一次。',
                ),
            ) .
            '</p><div id="xp-streak-rows">';
        foreach ($settings['streak']['rewards'] as $index => $reward) {
            self::reward_row($index, $reward);
        }
        echo '</div><template id="xp-streak-template">';
        self::reward_row('__INDEX__', ['id' => '__ID__', 'days' => 7, 'xp' => 5]);
        echo '</template><input type="hidden" name="growth[streak][rewards]" value="" disabled><button type="button" class="button" data-xp-add-streak>' .
            esc_html(wp_xp_core_t('添加签到阶段')) .
            '</button><p class="xp-growth-message" role="status"></p></section><div class="xp-save"><p>' .
            esc_html(wp_xp_core_t('等级资料只影响展示；阶段奖励用于新签到。')) .
            '</p><button class="button button-primary">' .
            esc_html(wp_xp_core_t('保存成长设置')) .
            '</button></div></form></div>';
    }
    private static function reward_row($index, array $reward): void
    {
        echo '<div class="xp-streak-row"><input type="hidden" name="growth[streak][rewards][' .
            $index .
            '][id]" value="' .
            esc_attr($reward['id']) .
            '"><label>' .
            esc_html(wp_xp_core_t('签到天数')) .
            '<input name="growth[streak][rewards][' .
            $index .
            '][days]" type="number" min="1" max="365" required value="' .
            (int) $reward['days'] .
            '"></label><label>' .
            esc_html(wp_xp_core_t('阶段经验')) .
            '<input name="growth[streak][rewards][' .
            $index .
            '][xp]" type="number" min="0" max="1000000" required value="' .
            (int) $reward['xp'] .
            '"></label><button type="button" class="button" data-xp-remove-row>' .
            esc_html(wp_xp_core_t('移除')) .
            '</button></div>';
    }
    public static function notice($result): void
    {
        if ($result === null) {
            return;
        }
        echo '<div class="notice ' .
            (is_wp_error($result) ? 'notice-error' : 'notice-success') .
            '" role="status"><p>' .
            esc_html(
                is_wp_error($result) ? $result->get_error_message() : wp_xp_core_t('已保存。'),
            ) .
            '</p></div>';
    }
    public static function routes(): void
    {
        foreach (
            array_unique(
                array_merge(
                    [reader_experience_config('rest_namespace')],
                    reader_experience_config('rest_aliases'),
                ),
            )
            as $namespace
        ) {
            foreach (
                [
                    'growth',
                    'history',
                    'tasks',
                    'claim',
                    'submit',
                    'notifications',
                    'notification-settings',
                    'notification-sources',
                    'notification-source-list',
                    'notification-source-read',
                    'participation',
                    'participation-settings',
                ]
                as $action
            ) {
                register_rest_route($namespace, '/' . $action, [
                    'methods' => 'POST',
                    'permission_callback' => [Reader_Experience::class, 'permission'],
                    'callback' => fn($request) => self::request($request, $action),
                ]);
            }
        }
    }
    public static function request($request, string $action)
    {
        try {
            if (!$request->is_json_content_type() || strlen($request->get_body()) > 4000) {
                return new WP_Error('input', wp_xp_core_t('请求格式无效。'), ['status' => 400]);
            }
            $data = $request->get_json_params();
            if (!is_array($data)) {
                return new WP_Error('input', wp_xp_core_t('请求格式无效。'), ['status' => 400]);
            }
            $allowed = match ($action) {
                'growth', 'tasks', 'participation', 'notification-sources' => [],
                'notification-source-list' => ['source', 'cursor', 'limit'],
                'notification-source-read' => ['source', 'id'],
                'history' => ['cursor', 'kind', 'from', 'to', 'limit'],
                'notifications' => ['cursor', 'limit'],
                'notification-settings' => ['revision', 'preferences', 'ids', 'through'],
                'participation-settings' => ['revision', 'participating'],
                'claim' => ['task_id'],
                'submit' => ['task_id', 'text', 'request_id'],
            };
            if (array_diff(array_keys($data), $allowed)) {
                return new WP_Error('input', wp_xp_core_t('请求包含无效字段。'), ['status' => 400]);
            }
            return match ($action) {
                'notification-sources',
                'notification-source-list',
                'notification-source-read'
                    => WP_XP_Core_Notification_Sources::request(
                    $action,
                    $data,
                    $request->get_header('X-WP-Nonce'),
                ),
                'growth' => WP_XP_Core_Growth::summary(get_current_user_id()),
                'history' => WP_XP_Core_Management::personal_history($data),
                'tasks' => WP_XP_Core_Achievements::personal(),
                'participation' => WP_XP_Core_Fairness::personal(
                    $request->get_header('X-WP-Nonce'),
                ),
                'participation-settings' => WP_XP_Core_Fairness::save_personal(
                    $data,
                    $request->get_header('X-WP-Nonce'),
                ),
                'notifications' => WP_XP_Core_Notifications::listing(
                    $data,
                    $request->get_header('X-WP-Nonce'),
                ),
                'notification-settings' => WP_XP_Core_Notifications::save(
                    $data,
                    $request->get_header('X-WP-Nonce'),
                ),
                'claim' => WP_XP_Core_Achievements::claim(
                    $data['task_id'] ?? null,
                    $request->get_header('X-WP-Nonce'),
                ),
                'submit' => WP_XP_Core_Achievements::submit(
                    $data,
                    $request->get_header('X-WP-Nonce'),
                ),
            };
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('暂时无法读取成长记录，请稍后重试。'), [
                'status' => 503,
            ]);
        }
    }
    public static function panel(): string
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true);
        $html =
            '<link rel="stylesheet" href="' .
            esc_url(plugins_url('assets/' . $manifest['css'], __FILE__)) .
            '"><section class="reader-experience-panel xp-growth-panel" aria-label="' .
            esc_attr(wp_xp_core_t('经验与成长')) .
            '"><h2>' .
            esc_html(wp_xp_core_t('我的成长')) .
            '</h2>';
        if (!is_user_logged_in()) {
            return $html .
                '<p>' .
                esc_html(wp_xp_core_t('登录后查看自己的等级、签到和任务进度。')) .
                '</p><a href="' .
                esc_url(wp_login_url(reader_experience_panel_url())) .
                '">' .
                esc_html(wp_xp_core_t('登录')) .
                '</a></section>';
        }
        wp_enqueue_script(
            'wp-xp-core-growth',
            plugins_url('assets/' . $manifest['growth_js'], __FILE__),
            ['reader_experience'],
            Reader_Experience::VERSION,
            true,
        );
        if (class_exists('WP_XP_Core_I18n')) {
            WP_XP_Core_I18n::script('wp-xp-core-growth');
        }
        $s = WP_XP_Core_Growth::summary(get_current_user_id());
        $p = $s['level_profile'];
        $html .=
            '<div class="xp-growth-heading"><span class="xp-level-symbol" style="--xp-level-color:' .
            esc_attr($p['color']) .
            '">' .
            esc_html($p['icon'] ?: 'XP') .
            '</span><div><strong class="xp-level-name">' .
            esc_html($p['name']) .
            '</strong><p class="reader-experience-state">' .
            esc_html(
                sprintf(wp_xp_core_t('经验 %1$s · Level %2$s'), $s['experience'], $s['level']) .
                    ($s['next'] !== null
                        ? sprintf(
                            wp_xp_core_t(' · 距下一级 %1$s'),
                            max(0, $s['next'] - $s['experience']),
                        )
                        : wp_xp_core_t(' · 已达最高等级')),
            ) .
            '</p><p class="xp-level-description">' .
            esc_html($p['description']) .
            '</p></div></div><progress aria-label="' .
            esc_attr(wp_xp_core_t('本级成长进度')) .
            '" class="xp-level-progress" max="100" value="' .
            $s['progress_percent'] .
            '">' .
            $s['progress_percent'] .
            '%</progress><div class="xp-today"><div><h3>' .
            esc_html(wp_xp_core_t('今日参与')) .
            '</h3><ul class="xp-today-items"></ul></div><div class="xp-checkin-action"><button type="button" class="reader-experience-checkin"' .
            ($s['checked_in'] || !$s['activities']['checkin'] ? ' disabled' : '') .
            '>' .
            (!$s['activities']['checkin']
                ? wp_xp_core_t('签到奖励已关闭')
                : ($s['checked_in']
                    ? wp_xp_core_t('今日已签到')
                    : sprintf(wp_xp_core_t('每日签到 +%1$s'), $s['checkin_xp']))) .
            '</button><p class="xp-checkin-streak">' .
            esc_html(
                sprintf(
                    wp_xp_core_t('连续 %1$s 天 · 累计 %2$s 天'),
                    $s['checkins']['consecutive'],
                    $s['checkins']['cumulative'],
                ),
            ) .
            '</p></div></div><p class="reader-experience-message" role="status" aria-live="polite"></p><details><summary>' .
            esc_html(wp_xp_core_t('签到日历')) .
            '</summary><ol class="xp-checkin-calendar" aria-label="' .
            esc_attr(wp_xp_core_t('最近三十五天签到')) .
            '"></ol><p class="xp-streak-progress"></p></details><section class="xp-inbox" aria-label="' .
            esc_attr(wp_xp_core_t('站内通知')) .
            '"><h3>' .
            esc_html(wp_xp_core_t('站内通知')) .
            '</h3><p class="xp-inbox-message" role="status"></p><ul class="xp-inbox-list"></ul><button type="button" class="xp-inbox-refresh">' .
            esc_html(wp_xp_core_t('刷新通知')) .
            '</button><button type="button" class="xp-inbox-read-all">' .
            esc_html(wp_xp_core_t('全部标为已读')) .
            '</button><button type="button" class="xp-inbox-more" hidden>' .
            esc_html(wp_xp_core_t('更早的通知')) .
            '</button><details><summary>' .
            esc_html(wp_xp_core_t('通知偏好')) .
            '</summary><form class="xp-inbox-preferences"><label><input type="checkbox" name="task" checked>' .
            esc_html(wp_xp_core_t('成就授予与撤销')) .
            '</label><label><input type="checkbox" name="review" checked>' .
            esc_html(wp_xp_core_t('审核结果')) .
            '</label><label><input type="checkbox" name="level" checked>' .
            esc_html(wp_xp_core_t('等级提升')) .
            '</label><button>' .
            esc_html(wp_xp_core_t('保存通知偏好')) .
            '</button></form></details></section><section class="xp-personal-tasks" aria-label="' .
            esc_attr(wp_xp_core_t('任务与成就')) .
            '"><h3>' .
            esc_html(wp_xp_core_t('任务与成就')) .
            '</h3><div class="xp-task-list"></div><div class="xp-badges" aria-label="' .
            esc_attr(wp_xp_core_t('已获成就')) .
            '"></div><div class="xp-submissions"></div></section><section class="xp-personal-standing" aria-label="' .
            esc_attr(wp_xp_core_t('本期活动位置')) .
            '" hidden><h3>' .
            esc_html(wp_xp_core_t('我的本期位置')) .
            '</h3><div class="xp-position-switch" role="group" aria-label="' .
            esc_attr(wp_xp_core_t('统计周期')) .
            '"><button type="button" data-xp-position-period="week" aria-pressed="true">' .
            esc_html(wp_xp_core_t('本周')) .
            '</button><button type="button" data-xp-position-period="month" aria-pressed="false">' .
            esc_html(wp_xp_core_t('本月')) .
            '</button></div><form class="xp-board-participation"><label><input type="checkbox" name="participating" checked>' .
            esc_html(wp_xp_core_t('参与公开活动榜')) .
            '</label><button>' .
            esc_html(wp_xp_core_t('保存榜单偏好')) .
            '</button><p class="xp-board-preference-message" role="status"></p></form><p class="xp-personal-position" role="status">' .
            esc_html(wp_xp_core_t('正在读取本期位置…')) .
            '</p></section><details class="xp-private-history"><summary>' .
            esc_html(wp_xp_core_t('经验记录')) .
            '</summary><form class="xp-history-filter"><label>' .
            esc_html(wp_xp_core_t('来源')) .
            '<select name="kind"><option value="">' .
            esc_html(wp_xp_core_t('全部来源')) .
            '</option><option value="checkin">' .
            esc_html(wp_xp_core_t('每日签到')) .
            '</option><option value="visit">' .
            esc_html(wp_xp_core_t('有效阅读')) .
            '</option><option value="comment">' .
            esc_html(wp_xp_core_t('评论')) .
            '</option><option value="article">' .
            esc_html(wp_xp_core_t('发表文章')) .
            '</option><option value="manual_adjust">' .
            esc_html(wp_xp_core_t('人工调整')) .
            '</option><option value="streak_reward">' .
            esc_html(wp_xp_core_t('签到阶段奖励')) .
            '</option><option value="provider_revoke">' .
            esc_html(wp_xp_core_t('外部活动撤销')) .
            '</option><option value="provider_reward">' .
            esc_html(wp_xp_core_t('外部活动奖励')) .
            '</option><option value="task_complete">' .
            esc_html(wp_xp_core_t('任务完成奖励')) .
            '</option><option value="task_revoke">' .
            esc_html(wp_xp_core_t('任务奖励撤销')) .
            '</option><option value="task_grant">' .
            esc_html(wp_xp_core_t('人工授予成就')) .
            '</option><option value="task_grant_revoke">' .
            esc_html(wp_xp_core_t('撤销人工成就')) .
            '</option></select></label><label>' .
            esc_html(wp_xp_core_t('开始')) .
            '<input type="date" name="from"></label><label>' .
            esc_html(wp_xp_core_t('结束')) .
            '<input type="date" name="to"></label><button type="submit">' .
            esc_html(wp_xp_core_t('查询')) .
            '</button></form><ul class="reader-experience-history"></ul><button type="button" class="xp-history-more" hidden>' .
            esc_html(wp_xp_core_t('加载更早记录')) .
            '</button></details></section>';
        return $html;
    }
}
WP_XP_Core_Growth_UI::boot();
