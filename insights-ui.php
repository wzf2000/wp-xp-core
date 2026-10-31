<?php
/** Read-only administrative insights, opt-in public boards and private current-user position. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Insights_UI
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_post_wp_xp_core_provider_csv', [self::class, 'export_providers']);
        if (reader_experience_configured()) {
            add_shortcode('xp_leaderboard', [self::class, 'board']);
        }
    }
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            wp_xp_core_t('激励效果'),
            wp_xp_core_t('激励效果'),
            'manage_options',
            'wp-xp-insights',
            [self::class, 'page'],
        );
    }
    public static function assets($hook): void
    {
        if ($hook === 'settings_page_wp-xp-insights') {
            WP_XP_Core_Settings::assets('settings_page_wp-xp-core');
        }
    }
    private static function value(string $name, string $default = ''): string
    {
        return isset($_GET[$name]) && is_string($_GET[$name])
            ? sanitize_text_field(wp_unslash($_GET[$name]))
            : $default;
    }
    private static function signed(int $value): string
    {
        return ($value > 0 ? '+' : '') . number_format_i18n($value);
    }
    public static function export_providers(): void
    {
        $raw = wp_unslash($_POST);
        $input = array_intersect_key($raw, array_flip(['period', 'from', 'to']));
        $csv = WP_XP_Core_Insights::provider_csv($input, $raw['_wpnonce'] ?? '');
        if (is_wp_error($csv)) {
            wp_die(esc_html($csv->get_error_message()), '', [
                'response' => (int) ($csv->get_error_data()['status'] ?? 400),
            ]);
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wp-xp-provider-summary.csv"');
        header('Cache-Control: private, no-store');
        header('Vary: Cookie', false);
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit();
    }
    private static function providers(array $input, $tasks): void
    {
        $data = WP_XP_Core_Insights::provider_sources($input, $tasks);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('扩展活动明细')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '按可信来源分别汇总原始活动、撤销与净经验。人数已在每个来源内去重，不同来源人数不能相加。零经验原因只统计截至现在未撤销的零经验原回执；正向部分截断不算零经验。',
                ),
            ) .
            '</p>';
        if (is_wp_error($data)) {
            WP_XP_Core_Growth_UI::notice($data);
            echo '</section>';
            return;
        }
        echo '<form method="post" action="' .
            esc_url(admin_url('admin-post.php')) .
            '"><input type="hidden" name="action" value="wp_xp_core_provider_csv">';
        wp_nonce_field('wp_xp_core_insights_export');
        foreach ($input as $key => $value) {
            echo '<input type="hidden" name="' .
                esc_attr($key) .
                '" value="' .
                esc_attr($value) .
                '">';
        }
        echo '<button class="button">' .
            esc_html(wp_xp_core_t('下载来源汇总 CSV')) .
            '</button></form><div class="xp-table-scroll" tabindex="0"><table class="widefat striped"><thead><tr>';
        $columns = [
            'label' => wp_xp_core_t('活动来源'),
            'activity_count' => wp_xp_core_t('原始活动'),
            'reversal_count' => wp_xp_core_t('撤销'),
            'user_count' => wp_xp_core_t('参与人数'),
            'positive_xp' => wp_xp_core_t('发放'),
            'negative_xp' => wp_xp_core_t('扣回'),
            'net_xp' => wp_xp_core_t('净经验'),
        ];
        foreach ($columns as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                echo '<td>' . esc_html((string) $row[$key]) . '</td>';
            }
            echo '</tr><tr><td colspan="7"><details><summary>' .
                esc_html(wp_xp_core_t('零经验原因与关联任务')) .
                '</summary><ul>';
            foreach (
                [
                    'zero_quota' => wp_xp_core_t('次数耗尽'),
                    'zero_hold' => wp_xp_core_t('奖励暂停'),
                    'zero_cap' => wp_xp_core_t('经验上限'),
                    'zero_rule' => wp_xp_core_t('规则为零经验'),
                    'zero_other' => wp_xp_core_t('其他或旧回执缺少原因'),
                ]
                as $key => $label
            ) {
                echo '<li>' . esc_html($label) . ': ' . (int) $row[$key] . '</li>';
            }
            echo '</ul><p>' .
                esc_html(
                    wp_xp_core_t(
                        '关联任务按各自当前版本与当前周期展示。包含其他来源或内置活动的任务是混合目标，不表示该来源单独带来完成；完成率不相加。',
                    ),
                ) .
                '</p><ul>';
            foreach ($row['tasks'] as $task) {
                echo '<li>' .
                    esc_html($task['title']) .
                    ' · v' .
                    (int) $task['version'] .
                    ' · ' .
                    esc_html($task['period_key']) .
                    ' · ' .
                    (int) $task['completed'] .
                    ' / ' .
                    (int) $task['participants'] .
                    ' · ' .
                    ($task['rate'] === null
                        ? esc_html(wp_xp_core_t('尚无参与'))
                        : esc_html(number_format_i18n($task['rate'] * 100, 1) . '%')) .
                    ($task['mixed_sources'] ? ' · ' . esc_html(wp_xp_core_t('混合目标')) : '') .
                    '</li>';
            }
            if (!$row['tasks']) {
                echo '<li>' . esc_html(wp_xp_core_t('没有当前启用的关联任务。')) . '</li>';
            }
            echo '</ul></details></td></tr>';
        }
        if (!$data['rows']) {
            echo '<tr><td colspan="7">' .
                esc_html(wp_xp_core_t('本期尚无符合条件的活动记录。')) .
                '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($data['more']) {
            echo '<p>' .
                esc_html(wp_xp_core_t('仅展示前一百项来源；请缩短日期范围查看或导出。')) .
                '</p>';
        }
        echo '</section>';
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有查看激励效果的权限。'));
        }
        $operation_result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $operation_result = WP_XP_Core_Fairness::exclude(
                is_array($_POST['exclusion'] ?? null) ? wp_unslash($_POST['exclusion']) : [],
                $_POST['_wpnonce'] ?? '',
            );
        }
        $period = self::value('period', 'week');
        $input = ['period' => $period];
        if ($period === 'custom') {
            $input += ['from' => self::value('from'), 'to' => self::value('to')];
        }
        $sources = WP_XP_Core_Insights::sources($input);
        $tasks = WP_XP_Core_Insights::task_rates();
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>' .
            esc_html(wp_xp_core_t('激励效果')) .
            '</h1><p>' .
            esc_html(wp_xp_core_t('了解参与来源，观察当前任务的完成情况。')) .
            '</p></div></header>';
        WP_XP_Core_Management_UI::nav('insights');
        WP_XP_Core_Growth_UI::notice($operation_result);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('经验来源')) .
            '</h2><form method="get" class="xp-filter-form"><input type="hidden" name="page" value="wp-xp-insights"><label>' .
            esc_html(wp_xp_core_t('统计时段')) .
            '<select name="period">';
        foreach (
            [
                'week' => wp_xp_core_t('本周'),
                'month' => wp_xp_core_t('本月'),
                'custom' => wp_xp_core_t('自选日期'),
            ]
            as $key => $label
        ) {
            echo '<option value="' .
                $key .
                '"' .
                selected($period, $key, false) .
                '>' .
                $label .
                '</option>';
        }
        echo '</select></label><label>' .
            esc_html(wp_xp_core_t('开始日期')) .
            '<input type="date" name="from" value="' .
            esc_attr(self::value('from')) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('结束日期')) .
            '<input type="date" name="to" value="' .
            esc_attr(self::value('to')) .
            '"></label><button class="button button-primary">' .
            esc_html(wp_xp_core_t('查看统计')) .
            '</button></form><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '自选日期最多 366 天，截至今日。这里包含人工调整；公开活动榜只统计活动净经验。',
                ),
            ) .
            '</p>';
        if (is_wp_error($sources)) {
            WP_XP_Core_Growth_UI::notice($sources);
        } else {
            echo '<p class="xp-insights-range">' .
                esc_html(
                    $sources['from'] .
                        wp_xp_core_t(' 至 ') .
                        $sources['to'] .
                        ' · ' .
                        $sources['timezone'],
                ) .
                '</p><dl class="xp-insight-totals">';
            foreach (
                [
                    'user_count' => wp_xp_core_t('参与人数'),
                    'positive_xp' => wp_xp_core_t('发放经验'),
                    'negative_xp' => wp_xp_core_t('扣回经验'),
                    'net_xp' => wp_xp_core_t('净经验'),
                ]
                as $key => $label
            ) {
                echo '<div><dt>' .
                    $label .
                    '</dt><dd>' .
                    esc_html(number_format_i18n($sources['totals'][$key])) .
                    '</dd></div>';
            }
            echo '</dl><div class="xp-table-scroll" tabindex="0" aria-label="' .
                esc_attr(wp_xp_core_t('经验来源统计')) .
                '"><table class="widefat striped"><thead><tr><th>' .
                esc_html(wp_xp_core_t('来源')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('事件')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('参与人数')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('发放')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('扣回')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('净经验')) .
                '</th></tr></thead><tbody>';
            foreach ($sources['rows'] as $row) {
                echo '<tr><td>' . esc_html(WP_XP_Core_Management_UI::label($row['kind'])) . '</td>';
                foreach (
                    ['event_count', 'user_count', 'positive_xp', 'negative_xp', 'net_xp']
                    as $key
                ) {
                    echo '<td class="xp-nowrap">' .
                        esc_html(number_format_i18n($row[$key])) .
                        '</td>';
                }
                echo '</tr>';
            }
            if (!$sources['rows']) {
                echo '<tr><td colspan="6">' .
                    esc_html(wp_xp_core_t('本期尚无符合条件的活动记录。')) .
                    '</td></tr>';
            }
            echo '</tbody></table></div><p class="xp-help">' .
                esc_html(
                    wp_xp_core_t(
                        '奖励为 0 的有效活动也计入事件和参与人数。总参与人数已跨来源去重，不能把每行人数相加；任务完成及其撤销计入活动来源；人工授予及其撤销单列，不进入活动榜。历史初始化、校准和管理操作回执不计入这里。',
                    ),
                ) .
                '</p>';
        }
        echo '</section>';
        self::providers($input, $tasks);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('当前任务完成率')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '每项任务按当前版本、当前周期单独统计，不随上方来源日期切换。参与者是有相关活动或提交记录的用户，包含已经获得成就的用户；这不是曝光转化率或全站注册用户完成比例。',
                ),
            ) .
            '</p>';
        if (is_wp_error($tasks)) {
            WP_XP_Core_Growth_UI::notice($tasks);
        } else {
            echo '<div class="xp-table-scroll" tabindex="0" aria-label="' .
                esc_attr(wp_xp_core_t('当前任务完成情况')) .
                '"><table class="widefat striped"><thead><tr><th>' .
                esc_html(wp_xp_core_t('任务')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('周期')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('参与人数')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('完成人数')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('完成率')) .
                '</th></tr></thead><tbody>';
            foreach ($tasks['rows'] as $row) {
                $url = add_query_arg(
                    ['page' => 'wp-xp-achievements', 'edit' => $row['id']],
                    admin_url('options-general.php'),
                );
                echo '<tr><td><a href="' .
                    esc_url($url) .
                    '">' .
                    esc_html($row['title']) .
                    '</a><br><small>' .
                    ($row['type'] === 'review'
                        ? wp_xp_core_t('提交审核')
                        : wp_xp_core_t('自动完成')) .
                    wp_xp_core_t(' · 版本 ') .
                    (int) $row['version'] .
                    '</small></td><td>' .
                    esc_html(WP_XP_Core_Achievements_UI::period_label($row['period'])) .
                    '<br><small>' .
                    ($row['period'] === 'once'
                        ? wp_xp_core_t('从本版创建起')
                        : esc_html($row['from'])) .
                    wp_xp_core_t(' 至 ') .
                    esc_html($row['to']) .
                    '</small></td><td>' .
                    (int) $row['participants'] .
                    '</td><td>' .
                    (int) $row['completed'] .
                    '</td><td class="xp-nowrap">' .
                    ($row['rate'] === null
                        ? wp_xp_core_t('尚无参与')
                        : esc_html(number_format_i18n($row['rate'] * 100, 1) . '%')) .
                    '</td></tr>';
            }
            if (!$tasks['rows']) {
                echo '<tr><td colspan="5">' .
                    esc_html(wp_xp_core_t('还没有启用的任务。可以从“任务与成就”创建一个目标。')) .
                    '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
        self::extended($input);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('放置周榜与月榜')) .
            '</h2><p>' .
            esc_html(wp_xp_core_t('在普通页面添加短代码区块，分别填入 ')) .
            '<code>[xp_leaderboard period="week"]</code>' .
            esc_html(wp_xp_core_t(' 或 ')) .
            '<code>[xp_leaderboard period="month"]</code>' .
            esc_html(wp_xp_core_t('，发布后即可公开展示。可用 ')) .
            '<code>limit="10"</code>' .
            esc_html(wp_xp_core_t(' 设置每页人数，最多 50 人。')) .
            '</p><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '插件不会自动创建或公开榜单页面。榜单按本期活动净经验排序，扣回会影响本期结果；不使用历史余额，不包含人工调分，同分并列。',
                ),
            ) .
            '</p></section></div>';
    }
    public static function board($attributes = []): string
    {
        $attributes = shortcode_atts(
            ['period' => 'week', 'limit' => '10'],
            is_array($attributes) ? $attributes : [],
            'xp_leaderboard',
        );
        $period = $attributes['period'];
        $key = $period === 'month' ? 'xp_month_page' : 'xp_week_page';
        $page = self::value($key, '1');
        if (
            !preg_match('/^[1-9][0-9]{0,5}$/D', $page) ||
            !preg_match('/^[1-9][0-9]?$/D', (string) $attributes['limit'])
        ) {
            return '<p>' . esc_html(wp_xp_core_t('榜单分页参数无效。')) . '</p>';
        }
        $limit = (int) $attributes['limit'];
        $data = WP_XP_Core_Insights::leaderboard([
            'period' => $period,
            'limit' => $limit,
            'offset' => ((int) $page - 1) * $limit,
        ]);
        if (is_wp_error($data)) {
            return '<p>' . esc_html($data->get_error_message()) . '</p>';
        }
        wp_enqueue_style(
            'reader_experience',
            plugins_url(
                'assets/' .
                    json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true)['css'],
                __FILE__,
            ),
            [],
            Reader_Experience::VERSION,
        );
        $title = $period === 'month' ? wp_xp_core_t('本月经验榜') : wp_xp_core_t('本周经验榜');
        $html =
            '<section class="reader-experience-panel xp-period-board" aria-label="' .
            $title .
            '"><h2>' .
            $title .
            '</h2><p class="xp-board-period">' .
            esc_html(
                $data['from'] . wp_xp_core_t(' 至 ') . $data['to'] . ' · ' . $data['timezone'],
            ) .
            '</p><div class="xp-board-scroll" tabindex="0" aria-label="' .
            $title .
            '"><table><thead><tr><th scope="col">' .
            esc_html(wp_xp_core_t('名次')) .
            '</th><th scope="col">' .
            esc_html(wp_xp_core_t('参与者')) .
            '</th><th scope="col">' .
            esc_html(wp_xp_core_t('本期净经验')) .
            '</th></tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            $html .=
                '<tr><td>' .
                (int) $row['rank'] .
                '</td><td><a href="' .
                esc_url($row['author_url']) .
                '">' .
                esc_html($row['display_name']) .
                '</a></td><td>' .
                esc_html(self::signed($row['xp'])) .
                '</td></tr>';
        }
        if (!$data['rows']) {
            $html .=
                '<tr><td colspan="3">' .
                esc_html(wp_xp_core_t('本页暂无符合条件的活动记录。')) .
                '</td></tr>';
        }
        $html .=
            '</tbody></table></div><nav class="xp-board-pagination" aria-label="' .
            esc_attr(wp_xp_core_t('榜单分页')) .
            '">';
        if ((int) $page > 1) {
            $html .=
                '<a rel="prev" href="' .
                esc_url(add_query_arg($key, (int) $page - 1, get_permalink())) .
                '">' .
                esc_html(wp_xp_core_t('上一页')) .
                '</a>';
        }
        $html .=
            '<span>第 ' .
            (int) $page .
            wp_xp_core_t(' 页 · ') .
            (int) $data['total'] .
            ' 位参与者</span>';
        if ($data['has_more']) {
            $html .=
                '<a rel="next" href="' .
                esc_url(add_query_arg($key, (int) $page + 1, get_permalink())) .
                '">' .
                esc_html(wp_xp_core_t('下一页')) .
                '</a>';
        }
        return $html .
            '</nav><p class="xp-board-note">' .
            esc_html(
                wp_xp_core_t(
                    '按活动净经验统计，同分并列，不含人工调分与历史初始化。结果实时更新，翻页时名次可能变化。',
                ),
            ) .
            '</p></section>';
    }
    private static function extended(array $input): void
    {
        $trends = WP_XP_Core_Insights::trends($input);
        $anomalies = WP_XP_Core_Insights::anomalies($input);
        $history = WP_XP_Core_Insights::task_history($input);
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('前一等长时段对比')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '比较当前日期范围与紧邻的前一等长时段；这是活动观察，不能证明任务带来了变化。',
                ),
            ) .
            '</p>';
        if (is_wp_error($trends)) {
            WP_XP_Core_Growth_UI::notice($trends);
        } else {
            foreach (
                ['current' => wp_xp_core_t('当前时段'), 'previous' => wp_xp_core_t('前一时段')]
                as $key => $label
            ) {
                $row = $trends[$key];
                echo '<p><strong>' .
                    $label .
                    '</strong> ' .
                    esc_html($row['from'] . wp_xp_core_t(' 至 ') . $row['to']) .
                    wp_xp_core_t(' · 净经验 ') .
                    self::signed($row['net_xp']) .
                    wp_xp_core_t(' · 活动 ') .
                    (int) $row['events'] .
                    wp_xp_core_t(' 次 · 参与 ') .
                    (int) $row['participants'] .
                    ' 人</p>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('任务版本完成记录')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '只汇总有可靠事件快照的授予与撤销，不补造旧版本参与分母。耗时从任务版本定义创建开始，包含等待和未参与的时间；旧记录没有此证据时留空。',
                ),
            ) .
            '</p>';
        if (is_wp_error($history)) {
            WP_XP_Core_Growth_UI::notice($history);
        } else {
            echo '<div class="xp-table-scroll"><table class="widefat striped"><thead><tr><th>' .
                esc_html(wp_xp_core_t('任务版本')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('授予次数')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('本期撤销次数')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('授予中仍有效')) .
                '</th><th>' .
                esc_html(wp_xp_core_t('有起点的样本／平均秒')) .
                '</th></tr></thead><tbody>';
            foreach ($history['rows'] as $row) {
                echo '<tr><td>' .
                    esc_html(($row['title'] ?: $row['task_id']) . ' v' . $row['version']) .
                    '</td><td>' .
                    (int) $row['completions'] .
                    '</td><td>' .
                    (int) $row['revocations'] .
                    '</td><td>' .
                    (int) $row['active_completions'] .
                    '</td><td>' .
                    (int) $row['timed_completions'] .
                    ' / ' .
                    ($row['mean_seconds'] === null
                        ? wp_xp_core_t('无起点证据')
                        : esc_html(number_format_i18n($row['mean_seconds'], 1))) .
                    '</td></tr>';
            }
            if (!$history['rows']) {
                echo '<tr><td colspan="5">' .
                    esc_html(wp_xp_core_t('此时段没有任务授予或撤销记录。')) .
                    '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($history['more']) {
                echo '<p>' .
                    esc_html(wp_xp_core_t('仅显示最近一百个任务版本，可缩短日期范围。')) .
                    '</p>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('待核对的活动线索')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '列出同日首次活动至少二十次或发生上限截断的账号，供人工核对；不自动处罚或排除。',
                ),
            ) .
            '</p>';
        if (is_wp_error($anomalies)) {
            WP_XP_Core_Growth_UI::notice($anomalies);
        } else {
            foreach ($anomalies['items'] as $row) {
                echo '<p>' .
                    esc_html($row['display_name']) .
                    ' (#' .
                    (int) $row['user_id'] .
                    ') · ' .
                    esc_html($row['day'] . ' · ' . $row['reason']) .
                    ' · ' .
                    (int) $row['events'] .
                    ' 次</p>';
            }
            if (!$anomalies['items']) {
                echo '<p>' . esc_html(wp_xp_core_t('本期没有符合上述条件的线索。')) . '</p>';
            }
        }
        echo '</section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('榜单账号排除')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '管理员可以排除测试账号等不应进入公开榜的用户。不会删除经验、活动或私人任务。',
                ),
            ) .
            '</p><p>当前排除编号：' .
            esc_html(implode(', ', WP_XP_Core_Fairness::excluded()) ?: wp_xp_core_t('无')) .
            '</p><form method="post" class="xp-filter-form">';
        wp_nonce_field('wp_xp_core_insights');
        echo '<input type="hidden" name="exclusion[revision]" value="' .
            esc_attr(WP_XP_Core_Fairness::exclusion_revision()) .
            '"><label>' .
            esc_html(wp_xp_core_t('用户编号')) .
            '<input type="number" min="1" name="exclusion[user_id]" required></label><label>' .
            esc_html(wp_xp_core_t('处理方式')) .
            '<select name="exclusion[excluded]"><option value="1">' .
            esc_html(wp_xp_core_t('排除公开榜单')) .
            '</option><option value="0">' .
            esc_html(wp_xp_core_t('恢复榜单资格')) .
            '</option></select></label><button class="button">' .
            esc_html(wp_xp_core_t('保存榜单排除')) .
            '</button></form></section>';
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
            register_rest_route($namespace, '/position', [
                'methods' => 'POST',
                'permission_callback' => [Reader_Experience::class, 'permission'],
                'callback' => [self::class, 'position'],
            ]);
        }
    }
    public static function position($request)
    {
        if (!$request->is_json_content_type() || strlen($request->get_body()) > 1000) {
            return new WP_Error('input', wp_xp_core_t('请求格式无效。'), ['status' => 400]);
        }
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('input', wp_xp_core_t('请求格式无效。'), ['status' => 400]);
        }
        return WP_XP_Core_Insights::mine($data, $request->get_header('X-WP-Nonce'));
    }
}
WP_XP_Core_Insights_UI::boot();
