<?php
/** Task definitions and review workflow for administrators. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Achievements_UI
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }
    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            '任务与成就',
            '任务与成就',
            'manage_options',
            'wp-xp-achievements',
            [self::class, 'page'],
        );
    }
    private static function url(array $args = []): string
    {
        return add_query_arg(
            ['page' => 'wp-xp-achievements'] + $args,
            admin_url('options-general.php'),
        );
    }
    private static function normalize($input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('任务信息无效。');
        }
        $input['steps'] = $input['steps'] ?? [];
        if (($input['type'] ?? '') === 'review') {
            $input['steps'] = [];
        }
        return $input;
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('没有管理任务的权限。');
        }
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['operation'] ?? '') === 'review') {
                $result = WP_XP_Core_Achievements::review(
                    array_intersect_key(
                        wp_unslash($_POST),
                        array_flip(['submission_id', 'decision', 'feedback']),
                    ),
                    $_POST['_wpnonce'] ?? '',
                );
            } else {
                try {
                    $result = WP_XP_Core_Achievements::save(
                        self::normalize(wp_unslash($_POST['task'] ?? [])),
                        $_POST['_wpnonce'] ?? '',
                        $_POST['revision'] ?? '',
                    );
                } catch (InvalidArgumentException $e) {
                    $result = new WP_Error('input', $e->getMessage());
                }
            }
        }
        $all = WP_XP_Core_Achievements::definitions();
        $id = is_string($_GET['edit'] ?? null)
            ? sanitize_text_field(wp_unslash($_GET['edit']))
            : '';
        $task = $all[$id] ?? [
            'id' => '',
            'title' => '',
            'description' => '',
            'icon' => '',
            'type' => 'automatic',
            'period' => 'once',
            'enabled' => true,
            'steps' => [['kind' => 'checkin', 'count' => 1]],
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['operation'] ?? '') !== 'review') {
            if (is_array($result) && isset($result['id'])) {
                $task = $all[$result['id']];
            } elseif (is_wp_error($result)) {
                try {
                    $posted = WP_XP_Core_Achievements::validate(
                        self::normalize(wp_unslash($_POST['task'] ?? [])),
                    );
                    $task = $posted;
                } catch (InvalidArgumentException $e) {
                }
            }
        }
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>任务与成就</h1><p>让参与有明确目标，为完成的事情留下荣誉。</p></div></header>';
        WP_XP_Core_Management_UI::nav('tasks');
        WP_XP_Core_Growth_UI::notice($result);
        echo '<section class="xp-card"><div class="xp-section-heading"><div><h2>任务列表</h2><p>自动任务按有效活动完成，审核任务由用户提交说明。成就不额外发放经验。</p></div><a class="button" href="' .
            esc_url(self::url()) .
            '">新建任务</a></div><div class="xp-table-scroll"><table class="widefat striped"><thead><tr><th>任务</th><th>方式</th><th>周期</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ($all as $row) {
            echo '<tr><td>' .
                esc_html(($row['icon'] ?: '◇') . ' ' . $row['title']) .
                '</td><td>' .
                ($row['type'] === 'automatic' ? '自动完成' : '提交审核') .
                '</td><td>' .
                esc_html(self::period_label($row['period'])) .
                '</td><td>' .
                ($row['enabled'] ? '已启用' : '已停用') .
                '</td><td><a href="' .
                esc_url(self::url(['edit' => $row['id']])) .
                '">编辑</a></td></tr>';
        }
        if (!$all) {
            echo '<tr><td colspan="5">还没有任务。可以先创建一个简单的签到任务。</td></tr>';
        }
        echo '</tbody></table></div></section><section class="xp-card"><h2>' .
            ($task['id'] ? '编辑任务' : '新建任务') .
            '</h2><form method="post" class="xp-task-editor">';
        wp_nonce_field('wp_xp_core_achievements');
        echo '<input type="hidden" name="revision" value="' .
            esc_attr(WP_XP_Core_Achievements::revision()) .
            '"><input type="hidden" name="task[id]" value="' .
            esc_attr($task['id']) .
            '"><label>任务名称<input name="task[title]" maxlength="60" required value="' .
            esc_attr($task['title']) .
            '"></label><label>任务说明<textarea name="task[description]" maxlength="300" rows="3">' .
            esc_html($task['description']) .
            '</textarea></label><div class="xp-task-options"><label>图标字符<input name="task[icon]" maxlength="8" value="' .
            esc_attr($task['icon']) .
            '" placeholder="例如 ★"></label><label>完成方式<select name="task[type]" data-xp-task-type><option value="automatic"' .
            selected($task['type'], 'automatic', false) .
            '>自动完成</option><option value="review"' .
            selected($task['type'], 'review', false) .
            '>提交说明后审核</option></select></label><label>周期<select name="task[period]">';
        foreach (['once' => '一次性', 'day' => '每日', 'week' => '每周'] as $key => $label) {
            echo '<option value="' .
                $key .
                '"' .
                selected($task['period'], $key, false) .
                '>' .
                $label .
                '</option>';
        }
        echo '</select></label></div><input type="hidden" name="task[enabled]" value="0"><label class="xp-toggle"><input type="checkbox" name="task[enabled]" value="1"' .
            checked($task['enabled'], true, false) .
            '>启用任务</label><fieldset class="xp-task-steps"><legend>全部完成以下步骤</legend><div id="xp-task-steps">';
        foreach ($task['steps'] as $index => $step) {
            self::step($index, $step);
        }
        echo '</div><template id="xp-task-step-template">';
        self::step('__INDEX__', ['kind' => 'checkin', 'count' => 1]);
        echo '</template><button type="button" class="button" data-xp-add-step>添加步骤</button></fieldset><p class="xp-task-review-help">审核任务不使用自动步骤。用户可填写完成说明，管理员通过后授予成就；退回时需要说明原因。</p><p class="xp-help">新建或修改任务后，从之后的新活动开始记录本版进度；旧成就保留。每周从周一开始，沿用经验规则中的时区。停用任务不会删除历史记录。</p><button class="button button-primary">保存任务</button><p class="xp-task-editor-message" role="status"></p></form></section><section class="xp-card"><h2>待审核说明</h2>';
        $cursor =
            isset($_GET['review_cursor']) && is_scalar($_GET['review_cursor'])
                ? max(0, (int) $_GET['review_cursor'])
                : 0;
        $pending = WP_XP_Core_Achievements::pending($cursor);
        if (is_wp_error($pending)) {
            WP_XP_Core_Growth_UI::notice($pending);
        } else {
            if (!$pending['items']) {
                echo '<p>目前没有等待审核的说明。</p>';
            }
            foreach ($pending['items'] as $row) {
                echo '<article class="xp-review-item"><h3>' .
                    esc_html($row['title']) .
                    ' · 用户 #' .
                    (int) $row['user_id'] .
                    '</h3><p class="xp-submission-text">' .
                    esc_html($row['text']) .
                    '</p><form method="post" class="xp-review-form">';
                wp_nonce_field('wp_xp_core_achievements');
                echo '<input type="hidden" name="operation" value="review"><input type="hidden" name="submission_id" value="' .
                    (int) $row['id'] .
                    '"><label>审核意见<input name="feedback" maxlength="300"></label><button name="decision" value="approve" class="button button-primary">通过并授予成就</button><button name="decision" value="reject" class="button">退回说明</button></form></article>';
            }
        }
        if (!is_wp_error($pending) && $pending['next_cursor']) {
            echo '<a class="button" href="' .
                esc_url(self::url(['review_cursor' => $pending['next_cursor']])) .
                '">更早的待审核说明</a>';
        }
        echo '</section></div>';
    }
    public static function period_label(string $period): string
    {
        return ['once' => '一次性', 'day' => '每日', 'week' => '每周'][$period] ?? $period;
    }
    private static function step($index, array $step): void
    {
        echo '<div class="xp-task-step"><label>参与活动<select name="task[steps][' .
            $index .
            '][kind]">';
        foreach (
            [
                'checkin' => '每日签到',
                'visit' => '有效阅读',
                'comment' => '有效评论',
                'article' => '发表文章',
            ]
            as $kind => $name
        ) {
            echo '<option value="' .
                $kind .
                '"' .
                selected($step['kind'], $kind, false) .
                '>' .
                $name .
                '</option>';
        }
        echo '</select></label><label>次数<input type="number" min="1" max="1000" required name="task[steps][' .
            $index .
            '][count]" value="' .
            (int) $step['count'] .
            '"></label><button type="button" class="button" data-xp-remove-row>移除</button></div>';
    }
}
WP_XP_Core_Achievements_UI::boot();
