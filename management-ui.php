<?php
/** Administrator ledger and diagnostics screens; all writes use the audited service. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Management_UI
{
    private const INTERNAL_PAGES = [
        'wp-xp-ledger',
        'wp-xp-growth',
        'wp-xp-achievements',
        'wp-xp-insights',
        'wp-xp-configuration',
    ];
    private static bool $retain_request = true;
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_menu', [self::class, 'consolidate_menu'], 99);
        add_filter('parent_file', [self::class, 'parent_file']);
        add_filter('submenu_file', [self::class, 'submenu_file']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_wp_xp_core_export', [self::class, 'export']);
    }
    public static function consolidate_menu(): void
    {
        foreach (self::INTERNAL_PAGES as $slug) {
            // Keep the registered page, callback and capability checks for existing URLs.
            $item = remove_submenu_page('options-general.php', $slug);
            if ($item !== false) {
                // WordPress normally derives this title from the visible submenu entry.
                add_action('load-settings_page_' . $slug, static function () use ($item): void {
                    $GLOBALS['title'] = $item[3];
                });
            }
        }
    }
    private static function is_plugin_page(): bool
    {
        global $plugin_page;
        return $plugin_page === 'wp-xp-core' || in_array($plugin_page, self::INTERNAL_PAGES, true);
    }
    public static function parent_file($parent_file)
    {
        return self::is_plugin_page() ? 'options-general.php' : $parent_file;
    }
    public static function submenu_file($submenu_file)
    {
        return self::is_plugin_page() ? 'wp-xp-core' : $submenu_file;
    }
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            wp_xp_core_t('经验账本'),
            wp_xp_core_t('经验账本'),
            'manage_options',
            'wp-xp-ledger',
            [self::class, 'page'],
        );
    }
    public static function assets($hook): void
    {
        if ($hook === 'settings_page_wp-xp-ledger') {
            WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
        }
    }
    public static function nav(string $active): void
    {
        echo '<nav class="xp-navigation" aria-label="' . esc_attr(wp_xp_core_t('经验系统')) . '">';
        $links = [
            'rules' => ['wp-xp-core', wp_xp_core_t('经验规则'), ''],
            'ledger' => ['wp-xp-ledger', wp_xp_core_t('事件账本'), ''],
            'diagnostics' => ['wp-xp-ledger', wp_xp_core_t('运行诊断'), 'diagnostics'],
        ];
        $links['growth'] = ['wp-xp-growth', wp_xp_core_t('成长中心'), ''];
        if (class_exists('WP_XP_Core_Achievements_UI')) {
            $links['tasks'] = ['wp-xp-achievements', wp_xp_core_t('任务与成就'), ''];
        }
        if (class_exists('WP_XP_Core_Insights_UI')) {
            $links['insights'] = ['wp-xp-insights', wp_xp_core_t('激励效果'), ''];
        }
        $links['configuration'] = ['wp-xp-configuration', wp_xp_core_t('配置工具'), ''];
        foreach ($links as $key => [$slug, $label, $view]) {
            $url = admin_url('options-general.php?page=' . $slug . ($view ? '&view=' . $view : ''));
            echo '<a href="' .
                esc_url($url) .
                '"' .
                ($active === $key ? ' aria-current="page"' : '') .
                '>' .
                esc_html($label) .
                '</a>';
        }
        echo '</nav>';
    }
    private static function value(string $name, $default = '')
    {
        return isset($_GET[$name]) && is_scalar($_GET[$name])
            ? sanitize_text_field(wp_unslash((string) $_GET[$name]))
            : $default;
    }
    private static function url(array $args): string
    {
        return add_query_arg(
            array_merge(['page' => 'wp-xp-ledger'], $args),
            admin_url('options-general.php'),
        );
    }
    private static function message($result): void
    {
        if ($result === null) {
            return;
        }
        $error = is_wp_error($result);
        echo '<div class="notice ' .
            ($error ? 'notice-error' : 'notice-success') .
            '" role="status"><p>' .
            esc_html(
                $error
                    ? $result->get_error_message()
                    : (!empty($result['duplicate'])
                        ? wp_xp_core_t('这项操作已经记录，没有再次发放。')
                        : wp_xp_core_t('操作已记录。')),
            ) .
            '</p></div>';
    }
    private static function hidden(string $action): void
    {
        wp_nonce_field('wp_xp_core_manage');
        $token =
            self::$retain_request &&
            $_SERVER['REQUEST_METHOD'] === 'POST' &&
            ($_POST['operation'] ?? '') === $action &&
            is_string($_POST['request_id'] ?? null)
                ? wp_unslash($_POST['request_id'])
                : wp_generate_uuid4();
        echo '<input type="hidden" name="operation" value="' .
            esc_attr($action) .
            '"><input type="hidden" name="request_id" value="' .
            esc_attr($token) .
            '">';
    }
    private static function table(array $items): void
    {
        echo '<div class="xp-table-scroll" tabindex="0" aria-label="' .
            esc_attr(wp_xp_core_t('经验事件表')) .
            '"><table class="widefat striped"><thead><tr><th>' .
            esc_html(wp_xp_core_t('事件')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('用户')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('来源')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('经验变化')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('日期与依据')) .
            '</th></tr></thead><tbody>';
        if (!$items) {
            echo '<tr><td colspan="5">' .
                esc_html(wp_xp_core_t('没有符合条件的事件。')) .
                '</td></tr>';
        }
        foreach ($items as $item) {
            echo '<tr><td>#' .
                (int) $item['id'] .
                '</td><td><a href="' .
                esc_url(self::url(['user_id' => (int) $item['user_id']])) .
                '">#' .
                (int) $item['user_id'] .
                '</a></td><td>' .
                esc_html(self::label((string) $item['kind'])) .
                '<br><small>' .
                esc_html((string) $item['kind']) .
                '</small></td><td class="xp-nowrap">' .
                ((int) $item['xp'] > 0 ? '+' : '') .
                (int) $item['xp'] .
                '</td><td>' .
                esc_html((string) $item['local_day']);
            if ((int) $item['object_id']) {
                echo '<br><small>关联对象 #' . (int) $item['object_id'] . '</small>';
            }
            if (!empty($item['detail'])) {
                echo '<details><summary>' .
                    esc_html(wp_xp_core_t('查看依据')) .
                    '</summary><pre class="xp-event-detail">' .
                    esc_html(
                        wp_json_encode($item['detail'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ) .
                    '</pre></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public static function label(string $kind): string
    {
        return [
            'checkin' => wp_xp_core_t('每日签到'),
            'visit' => wp_xp_core_t('有效阅读'),
            'view' => wp_xp_core_t('作者浏览计数'),
            'like' => wp_xp_core_t('点赞计数'),
            'article' => wp_xp_core_t('发表文章'),
            'comment' => wp_xp_core_t('有效评论'),
            'comment_adjust' => wp_xp_core_t('评论状态调整'),
            'milestone_view' => wp_xp_core_t('浏览里程碑'),
            'milestone_like' => wp_xp_core_t('点赞里程碑'),
            'manual_adjust' => wp_xp_core_t('人工调整'),
            'manual_reversal' => wp_xp_core_t('撤销人工调整'),
            'migration' => wp_xp_core_t('历史初始化'),
            'reconciliation' => wp_xp_core_t('历史校准'),
            'streak_reward' => wp_xp_core_t('签到阶段奖励'),
            'management_retry' => wp_xp_core_t('失败事件重试'),
            'provider_revoke' => wp_xp_core_t('外部活动撤销'),
            'provider_reward' => wp_xp_core_t('外部活动奖励'),
            'task_complete' => wp_xp_core_t('完成任务'),
            'task_revoke' => wp_xp_core_t('任务奖励撤销'),
            'task_grant' => wp_xp_core_t('人工授予成就'),
            'task_grant_revoke' => wp_xp_core_t('撤销人工成就'),
            'achievement_admin' => wp_xp_core_t('成就管理操作'),
            'achievement_submit' => wp_xp_core_t('成就申请'),
            'achievement_review' => wp_xp_core_t('成就审核'),
        ][$kind] ?? $kind;
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有查看经验管理的权限。'));
        }
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = wp_unslash($_POST);
            $action = $input['operation'] ?? '';
            $nonce = $input['_wpnonce'] ?? '';
            $result = match ($action) {
                'reward_hold' => WP_XP_Core_Reward_Controls::save(
                    array_intersect_key(
                        $input,
                        array_flip(['user_id', 'minutes', 'reason', 'revision']),
                    ),
                    $nonce,
                ),
                'measure' => null,
                'adjust' => WP_XP_Core_Management::adjust(
                    array_intersect_key(
                        $input,
                        array_flip(['request_id', 'user_id', 'xp', 'reason']),
                    ),
                    $nonce,
                ),
                'reverse' => WP_XP_Core_Management::reverse(
                    array_intersect_key($input, array_flip(['request_id', 'event_id', 'reason'])),
                    $nonce,
                ),
                'retry' => WP_XP_Core_Management::retry(
                    array_intersect_key($input, array_flip(['request_id', 'failure_id', 'reason'])),
                    $nonce,
                ),
                default => new WP_Error('operation', wp_xp_core_t('请选择有效操作。')),
            };
            self::$retain_request = is_wp_error($result);
        }
        $view = self::value('view') === 'diagnostics' ? 'diagnostics' : 'ledger';
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>' .
            ($view === 'diagnostics' ? wp_xp_core_t('运行诊断') : wp_xp_core_t('经验账本')) .
            '</h1><p>' .
            esc_html(wp_xp_core_t('查清奖励来源，保留每一次调整的依据。')) .
            '</p></div></header>';
        self::nav($view);
        self::message($result);
        if (!Reader_Experience::ready()) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html(wp_xp_core_t('经验系统尚未就绪，请先检查插件安装与配置。')) .
                '</p></div></div>';
            return;
        }
        if ($view === 'diagnostics') {
            self::diagnostics();
        } else {
            self::ledger();
        }
        echo '</div>';
    }
    private static function ledger(): void
    {
        $filters = [
            'user_id' => (int) self::value('user_id', 0),
            'kind' => self::value('kind'),
            'from' => self::value('from'),
            'to' => self::value('to'),
            'cursor' => (int) self::value('cursor', 0),
            'limit' => 25,
        ];
        echo '<section class="xp-card"><form method="get" class="xp-filter-form"><input type="hidden" name="page" value="wp-xp-ledger"><label>' .
            esc_html(wp_xp_core_t('用户编号')) .
            '<input type="number" min="1" name="user_id" value="' .
            ($filters['user_id'] ?? 0 ?: '') .
            '"></label><label>' .
            esc_html(wp_xp_core_t('来源')) .
            '<input name="kind" value="' .
            esc_attr($filters['kind']) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('例如 checkin')) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('开始日期')) .
            '<input type="date" name="from" value="' .
            esc_attr($filters['from']) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('结束日期')) .
            '<input type="date" name="to" value="' .
            esc_attr($filters['to']) .
            '"></label><button class="button button-primary">' .
            esc_html(wp_xp_core_t('查询事件')) .
            '</button><a class="button" href="' .
            esc_url(self::url([])) .
            '">' .
            esc_html(wp_xp_core_t('清除筛选')) .
            '</a></form>';
        if (!($filters['user_id'] ?? 0)) {
            unset($filters['user_id']);
        }
        $events = WP_XP_Core_Management::events($filters);
        if (is_wp_error($events)) {
            self::message($events);
            echo '</section>';
            return;
        }
        self::table($events['items']);
        $export = add_query_arg(
            array_merge($filters, [
                'action' => 'wp_xp_core_export',
                '_wpnonce' => wp_create_nonce('wp_xp_core_export'),
            ]),
            admin_url('admin-post.php'),
        );
        echo '<div class="xp-actions"><a class="button" href="' .
            esc_url($export) .
            '">' .
            esc_html(wp_xp_core_t('导出当前页 CSV')) .
            '</a>';
        if ($events['next_cursor']) {
            echo '<a class="button" href="' .
                esc_url(self::url(array_merge($filters, ['cursor' => $events['next_cursor']]))) .
                '">' .
                esc_html(wp_xp_core_t('下一页')) .
                '</a>';
        }
        echo '</div></section>';
        echo '<div class="xp-management-grid"><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('用户经验与人工调整')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('输入用户编号查看详情，或按用户名查找。')) .
            '</p><form method="get" class="xp-search-form"><input type="hidden" name="page" value="wp-xp-ledger"><label for="xp-user-search">' .
            esc_html(wp_xp_core_t('用户名／显示名称')) .
            '</label><input id="xp-user-search" name="user_search" value="' .
            esc_attr(self::value('user_search')) .
            '"><button class="button">' .
            esc_html(wp_xp_core_t('查找用户')) .
            '</button></form>';
        if (self::value('user_search') !== '') {
            $users = get_users([
                'search' => '*' . self::value('user_search') . '*',
                'search_columns' => ['user_login', 'display_name'],
                'number' => 20,
                'fields' => ['ID', 'display_name'],
            ]);
            echo '<ul>';
            foreach ($users as $user) {
                echo '<li><a href="' .
                    esc_url(self::url(['user_id' => $user->ID])) .
                    '">' .
                    esc_html($user->display_name) .
                    ' · #' .
                    (int) $user->ID .
                    '</a></li>';
            }
            if (!$users) {
                echo '<li>' . esc_html(wp_xp_core_t('未找到用户。')) . '</li>';
            }
            echo '</ul>';
        }
        if ($filters['user_id'] ?? 0) {
            $user = WP_XP_Core_Management::user_summary($filters['user_id'] ?? 0);
            if (is_wp_error($user)) {
                self::message($user);
            } else {
                echo '<dl class="xp-summary"><div><dt>' .
                    esc_html(wp_xp_core_t('用户')) .
                    '</dt><dd>' .
                    esc_html($user['display_name']) .
                    ' · #' .
                    (int) $user['user_id'] .
                    '</dd></div><div><dt>' .
                    esc_html(wp_xp_core_t('经验／等级')) .
                    '</dt><dd>' .
                    (int) $user['balance'] .
                    ' / Level ' .
                    (int) $user['level'] .
                    '</dd></div><div><dt>' .
                    esc_html(wp_xp_core_t('账本合计')) .
                    '</dt><dd>' .
                    (int) $user['ledger_total'] .
                    ' · ' .
                    ($user['consistent'] ? wp_xp_core_t('一致') : wp_xp_core_t('存在差异')) .
                    '</dd></div></dl>';
            }
        }
        echo '<form method="post" class="xp-management-form">';
        self::hidden('adjust');
        echo '<label>' .
            esc_html(wp_xp_core_t('用户编号')) .
            '<input name="user_id" type="number" min="1" required value="' .
            ($filters['user_id'] ?? 0 ?: '') .
            '"></label><label>' .
            esc_html(wp_xp_core_t('增减经验')) .
            '<input name="xp" type="number" min="-1000000" max="1000000" step="1" required placeholder="' .
            esc_attr(wp_xp_core_t('例如 10 或 -5')) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('调整原因')) .
            '<textarea name="reason" maxlength="300" required rows="3"></textarea></label><p class="xp-help">' .
            esc_html(wp_xp_core_t('正数增加，负数扣减。不会覆盖旧记录；扣减不能超过当前经验。')) .
            '</p><button class="button button-primary">' .
            esc_html(wp_xp_core_t('记录经验调整')) .
            '</button></form></section>';
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('撤销人工调整')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t('填写人工调整的事件编号，追加一条反向记录。每条调整只能撤销一次。'),
            ) .
            '</p><form method="post" class="xp-management-form">';
        self::hidden('reverse');
        echo '<label>' .
            esc_html(wp_xp_core_t('原事件编号')) .
            '<input name="event_id" type="number" min="1" required></label><label>' .
            esc_html(wp_xp_core_t('撤销原因')) .
            '<textarea name="reason" maxlength="300" required rows="3"></textarea></label><button class="button">' .
            esc_html(wp_xp_core_t('记录撤销')) .
            '</button></form></section></div>';
    }
    private static function diagnostics(): void
    {
        $adapter_failures = get_option('wp_xp_core_bbpress_failures', []);
        if (is_array($adapter_failures) && $adapter_failures) {
            echo '<section class="xp-card"><h2>' .
                esc_html(wp_xp_core_t('bbPress 活动失败记录')) .
                '</h2><p>' .
                esc_html(
                    wp_xp_core_t(
                        '最近二十项，只记录对象和错误码；请核对原事件后使用服务端撤销接口处理，不自动重放。',
                    ),
                ) .
                '</p><pre>' .
                esc_html(
                    wp_json_encode($adapter_failures, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ) .
                '</pre></section>';
        }
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('只读性能采样')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '手动读取最新五十条和五百条编号，显示查询数及本机耗时；样本不是整站或生产压测。',
                ),
            ) .
            '</p><form method="post">';
        self::hidden('measure');
        echo '<button class="button">' . esc_html(wp_xp_core_t('运行采样')) . '</button></form>';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['operation'] ?? '') === 'measure') {
            $measurement = WP_XP_Core_Reward_Controls::measure($_POST['_wpnonce'] ?? '');
            if (is_wp_error($measurement)) {
                self::message($measurement);
            } else {
                echo '<pre>' .
                    esc_html(
                        wp_json_encode($measurement, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ) .
                    '</pre>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('暂停自动经验奖励')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '最长二十四小时。有效活动和任务荣誉仍记录，期间自动经验为零，解除后不补差；人工调整和原额撤销不受影响。',
                ),
            ) .
            '</p><form method="get"><input type="hidden" name="page" value="wp-xp-ledger"><input type="hidden" name="view" value="diagnostics"><label>' .
            esc_html(wp_xp_core_t('用户编号')) .
            ' <input type="number" min="1" name="hold_user" value="' .
            esc_attr(self::value('hold_user', '')) .
            '"></label> <button class="button">' .
            esc_html(wp_xp_core_t('读取状态')) .
            '</button></form>';
        $hold_uid = (int) self::value('hold_user', 0);
        if (
            $hold_uid &&
            get_userdata($hold_uid) &&
            WP_XP_Core_Management::permission($hold_uid) === true
        ) {
            try {
                $hold = WP_XP_Core_Reward_Controls::status($hold_uid);
            } catch (Throwable $e) {
                self::message(new WP_Error('storage', wp_xp_core_t('暂时无法读取经验记录。')));
                return;
            }
            echo '<p>' .
                esc_html(
                    $hold['active']
                        ? wp_xp_core_t('自动经验奖励已暂停。')
                        : wp_xp_core_t('自动经验奖励正常。'),
                ) .
                '</p><form method="post">';
            self::hidden('reward_hold');
            echo '<input type="hidden" name="user_id" value="' .
                $hold_uid .
                '"><input type="hidden" name="revision" value="' .
                esc_attr(WP_XP_Core_Reward_Controls::revision($hold_uid)) .
                '"><label class="xp-field">' .
                esc_html(wp_xp_core_t('暂停分钟数（零表示解除）')) .
                '<input type="number" min="0" max="1440" name="minutes" value="60" required></label><label class="xp-field">' .
                esc_html(wp_xp_core_t('操作原因')) .
                '<input name="reason" maxlength="300" required></label><button class="button">' .
                esc_html(wp_xp_core_t('保存暂停状态')) .
                '</button></form>';
        }
        echo '</section>';
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('账本与余额核对')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('逐页读取现有记录并比较，不修改经验。差异需要先查清原因。')) .
            '</p>';
        $data = WP_XP_Core_Management::diagnostics((int) self::value('cursor', 0), 50);
        if (is_wp_error($data)) {
            self::message($data);
        } else {
            echo '<div class="xp-table-scroll" tabindex="0"><table class="widefat striped"><thead><tr><th>' .
                esc_html(wp_xp_core_t('用户')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('余额')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('账本合计')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('状态')) .
                '</th></tr></thead><tbody>';
            foreach ($data['items'] as $row) {
                echo '<tr><td>#' .
                    (int) $row['user_id'] .
                    (!$row['user_exists'] ? wp_xp_core_t('（账号已删除）') : '') .
                    '</td><td>' .
                    (int) $row['balance'] .
                    '</td><td>' .
                    (int) $row['ledger_total'] .
                    '</td><td>' .
                    ($row['consistent'] ? wp_xp_core_t('一致') : wp_xp_core_t('需要核对')) .
                    '</td></tr>';
            }
            if (!$data['items']) {
                echo '<tr><td colspan="4">' .
                    esc_html(wp_xp_core_t('尚无经验记录。')) .
                    '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($data['next_cursor']) {
                echo '<a class="button" href="' .
                    esc_url(
                        self::url(['view' => 'diagnostics', 'cursor' => $data['next_cursor']]),
                    ) .
                    '">' .
                    esc_html(wp_xp_core_t('继续核对')) .
                    '</a>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('未完成的奖励记录')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '只处理服务端已记录的首次奖励或评论状态补偿失败，并重新检查原事件和当前状态；不会扫描历史。评论补偿余额不足时不部分扣减，可补足后带原因重试。失败列表最多保留二百项，不是永久日志。',
                ),
            ) .
            '</p>';
        $failures = WP_XP_Core_Management::failures();
        if (is_wp_error($failures)) {
            self::message($failures);
        } else {
            $pending = array_filter($failures, fn($failure) => $failure['status'] === 'pending');
            if (!$pending) {
                echo '<p>' . esc_html(wp_xp_core_t('没有等待处理的失败记录。')) . '</p>';
            }
            foreach ($pending as $failure) {
                echo '<form method="post" class="xp-retry-form">';
                self::hidden('retry');
                echo '<input type="hidden" name="failure_id" value="' .
                    esc_attr($failure['id']) .
                    '"><p>' .
                    esc_html(self::label($failure['kind'])) .
                    wp_xp_core_t(' · 对象 #') .
                    (int) $failure['object_id'] .
                    ' · ' .
                    esc_html($failure['last_error']) .
                    '</p><label>' .
                    esc_html(wp_xp_core_t('处理原因')) .
                    '<input name="reason" maxlength="300" required></label><button class="button">' .
                    esc_html(wp_xp_core_t('重新检查并重试')) .
                    '</button></form>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('规则修改记录')) .
            '</h2>';
        $history = WP_XP_Core_Management::audit_history();
        if (is_wp_error($history)) {
            self::message($history);
        } else {
            if (!$history) {
                echo '<p>' . esc_html(wp_xp_core_t('尚无规则修改记录。')) . '</p>';
            }
            foreach ($history as $item) {
                echo '<details class="xp-rule-audit"><summary>' .
                    esc_html(wp_date('Y-m-d H:i', (int) $item['created_at'])) .
                    wp_xp_core_t(' · 操作者 #') .
                    (int) $item['actor'] .
                    '</summary><pre class="xp-event-detail">' .
                    esc_html(
                        wp_json_encode(
                            ['before' => $item['before'], 'after' => $item['after']],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                        ),
                    ) .
                    '</pre></details>';
            }
        }
        echo '</section>';
    }
    public static function export(): void
    {
        if (
            !current_user_can('manage_options') ||
            !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp_xp_core_export')
        ) {
            wp_die(wp_xp_core_t('没有导出经验记录的权限。'));
        }
        $filters = [];
        foreach (['user_id', 'kind', 'from', 'to', 'cursor', 'limit'] as $key) {
            $filters[$key] = self::value($key, $key === 'limit' ? 25 : '');
        }
        if (empty($filters['user_id'])) {
            unset($filters['user_id']);
        }
        $events = WP_XP_Core_Management::events($filters);
        if (is_wp_error($events)) {
            wp_die(esc_html($events->get_error_message()));
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="experience-events.csv"');
        $stream = fopen('php://output', 'w');
        fputcsv($stream, ['event_id', 'user_id', 'kind', 'object_id', 'xp', 'date']);
        foreach ($events['items'] as $row) {
            fputcsv($stream, [
                (int) $row['id'],
                (int) $row['user_id'],
                preg_replace('/^[=+@-]/', ' ', $row['kind']),
                (int) $row['object_id'],
                (int) $row['xp'],
                $row['local_day'],
            ]);
        }
        fclose($stream);
        exit();
    }
}
WP_XP_Core_Management_UI::boot();
