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
        foreach ($input['steps'] as &$step) {
            if (!is_array($step)) {
                continue;
            }
            foreach (['post_ids', 'category_ids'] as $field) {
                if (is_string($step[$field] ?? null)) {
                    $step[$field] =
                        trim($step[$field]) === ''
                            ? []
                            : preg_split('/[ ,，]+/u', trim($step[$field]));
                }
            }
        }
        unset($step);
        if (isset($input['prerequisites']) && is_array($input['prerequisites'])) {
            $input['prerequisites'] = array_values(
                array_filter(
                    $input['prerequisites'],
                    fn($pre) => is_array($pre) && ($pre['id'] ?? '') !== '',
                ),
            );
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
            if (in_array($_POST['operation'] ?? '', ['grant', 'revoke'], true)) {
                $result = WP_XP_Core_Achievements::manage(
                    array_merge(
                        is_array($_POST['achievement'] ?? null)
                            ? wp_unslash($_POST['achievement'])
                            : [],
                        ['operation' => $_POST['operation']],
                    ),
                    $_POST['_wpnonce'] ?? '',
                );
            } elseif (($_POST['operation'] ?? '') === 'review') {
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
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST' &&
            !in_array($_POST['operation'] ?? '', ['review', 'grant', 'revoke'], true)
        ) {
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
        echo '<section class="xp-card"><div class="xp-section-heading"><div><h2>任务列表</h2><p>自动任务按有效活动完成，审核任务由用户提交说明。经验奖励默认关闭，可为任务单独设置。</p></div><a class="button" href="' .
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
        foreach (
            ['once' => '一次性', 'day' => '每日', 'week' => '每周', 'month' => '每月']
            as $key => $label
        ) {
            echo '<option value="' .
                $key .
                '"' .
                selected($task['period'], $key, false) .
                '>' .
                $label .
                '</option>';
        }
        echo '</select></label></div><fieldset><legend>开放日期与前置任务</legend><div class="xp-task-options"><label>开始日期<input type="date" name="task[starts_on]" value="' .
            esc_attr($task['starts_on'] ?? '') .
            '"></label><label>结束日期<input type="date" name="task[ends_on]" value="' .
            esc_attr($task['ends_on'] ?? '') .
            '"></label></div><p class="xp-help">日期包含首尾，使用经验规则的时区；留空表示不限。前置任务全部完成后可领取或提交，本任务仍从本版创建后的有效活动累计。</p>';
        echo '<details><summary>前置任务（可选）</summary>';
        $prerequisites = $task['prerequisites'] ?? [];
        for ($i = 0; $i < 8; $i++) {
            echo '<div class="xp-task-options"><label>前置任务 ' .
                ($i + 1) .
                '<select name="task[prerequisites][' .
                $i .
                '][id]"><option value="">无</option>';
            foreach ($all as $candidate) {
                if ($candidate['id'] === $task['id']) {
                    continue;
                }
                echo '<option value="' .
                    esc_attr($candidate['id']) .
                    '"' .
                    selected($prerequisites[$i]['id'] ?? '', $candidate['id'], false) .
                    '>' .
                    esc_html($candidate['title']) .
                    '</option>';
            }
            echo '</select></label><label>完成范围<select name="task[prerequisites][' .
                $i .
                '][scope]">';
            foreach (
                ['current' => '当前版本与当前周期', 'lifetime' => '终身（含旧版本荣誉）']
                as $scope => $label
            ) {
                echo '<option value="' .
                    $scope .
                    '"' .
                    selected($prerequisites[$i]['scope'] ?? 'current', $scope, false) .
                    '>' .
                    $label .
                    '</option>';
            }
            echo '</select></label></div>';
        }
        echo '</details></fieldset><fieldset class="xp-task-reward-options"><legend>成就展示与可选奖励</legend><div class="xp-task-options"><label>分类<input name="task[category]" maxlength="40" value="' .
            esc_attr($task['category'] ?? '') .
            '"></label><label>系列<input name="task[series]" maxlength="60" value="' .
            esc_attr($task['series'] ?? '') .
            '"></label><label>完成经验<input type="number" min="0" max="1000000" name="task[reward_xp]" value="' .
            (int) ($task['reward_xp'] ?? 0) .
            '"></label></div><p class="xp-help">0 表示只授予荣誉。修改奖励会建立新版本，已有成就不补发奖励。</p><div class="xp-task-options"><label>媒体库徽章图片 ID<input type="number" min="0" name="task[image_id]" data-xp-badge-image value="' .
            (int) ($task['image_id'] ?? 0) .
            '"></label><button type="button" class="button" data-xp-pick-badge>从媒体库选择图片</button><button type="button" class="button" data-xp-clear-badge>使用图标字符</button></div><p class="xp-help">仅使用可公开展示的图片。图片删除或不再公开时回退图标字符；修改展示资料不改变旧成就快照。</p></fieldset><input type="hidden" name="task[enabled]" value="0"><label class="xp-toggle"><input type="checkbox" name="task[enabled]" value="1"' .
            checked($task['enabled'], true, false) .
            '>启用任务</label><fieldset class="xp-task-steps" data-xp-auto-steps><legend>全部完成以下步骤</legend><div id="xp-task-steps">';
        foreach ($task['steps'] as $index => $step) {
            self::step($index, $step);
        }
        echo '</div><template id="xp-task-step-template">';
        self::step('__INDEX__', ['kind' => 'checkin', 'count' => 1]);
        echo '</template><button type="button" class="button" data-xp-add-step>添加步骤</button></fieldset><p class="xp-task-review-help">审核任务不使用自动步骤。用户可填写完成说明，管理员通过后按本版奖励授予成就；退回时需要说明原因。</p><p class="xp-help">修改名称、说明、徽章图片、分类或系列会保留进度；修改条件、周期、开放日期、前置任务或启用状态会建立新版本，从之后的新活动累计。旧成就保留。每周从周一开始，每月从一日开始。</p><button class="button button-primary">保存任务</button><p class="xp-task-editor-message" role="status"></p></form></section><section class="xp-card"><h2>待审核说明</h2>';
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
        echo '</section>';
        self::management($all);
        echo '</div>';
    }
    private static function management(array $tasks): void
    {
        $enabled = array_filter($tasks, fn($t) => !empty($t['enabled']));
        $first = $enabled ? reset($enabled) : null;
        echo '<section class="xp-card"><h2>人工授予与撤销</h2><p>操作需要填写原因并检查目标用户权限。人工授予可确认自动步骤以外的完成依据，仍需满足任务日期与前置条件；其经验不进入活动榜。</p>';
        if ($first) {
            echo '<details><summary>授予当前周期成就</summary><form method="post" class="xp-task-editor" data-xp-grant-form>';
            wp_nonce_field('wp_xp_core_achievements');
            echo '<input type="hidden" name="operation" value="grant"><input type="hidden" name="achievement[request_id]" value="' .
                esc_attr(wp_generate_uuid4()) .
                '"><input type="hidden" name="achievement[version]" value="' .
                (int) $first['version'] .
                '"><input type="hidden" name="achievement[period]" value="' .
                esc_attr(WP_XP_Core_Achievements::period($first)['key']) .
                '"><label>用户编号<input type="number" name="achievement[user_id]" min="1" required></label><label>任务<select name="achievement[task_id]">';
            foreach ($enabled as $task) {
                echo '<option value="' .
                    esc_attr($task['id']) .
                    '" data-version="' .
                    (int) $task['version'] .
                    '" data-period="' .
                    esc_attr(WP_XP_Core_Achievements::period($task)['key']) .
                    '">' .
                    esc_html($task['title'] . '（经验 +' . (int) ($task['reward_xp'] ?? 0) . '）') .
                    '</option>';
            }
            echo '</select></label><label>授予原因<textarea name="achievement[reason]" maxlength="300" required></textarea></label><button class="button button-primary">授予成就</button></form></details>';
        }
        echo '<details><summary>撤销已获成就</summary><form method="post" class="xp-task-editor">';
        wp_nonce_field('wp_xp_core_achievements');
        echo '<input type="hidden" name="operation" value="revoke"><input type="hidden" name="achievement[request_id]" value="' .
            esc_attr(wp_generate_uuid4()) .
            '"><label>原授予事件编号<input type="number" name="achievement[event_id]" min="1" required></label><label>撤销原因<textarea name="achievement[reason]" maxlength="300" required></textarea></label><p class="xp-help">在事件账本中查找“完成任务”或“人工授予成就”的事件编号。撤销会扣回原经验，余额不足时整项拒绝；同一版本与周期不能再次授予。</p><button class="button">撤销成就并扣回原经验</button></form></details></section>';
    }
    public static function period_label(string $period): string
    {
        return ['once' => '一次性', 'day' => '每日', 'week' => '每周', 'month' => '每月'][
            $period
        ] ?? $period;
    }
    private static function step($index, array $step): void
    {
        echo '<div class="xp-task-step"><label>参与活动<select name="task[steps][' .
            $index .
            '][kind]" data-xp-step-kind>';
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
            '"></label><label>计数方式<select name="task[steps][' .
            $index .
            '][mode]" data-xp-step-mode>';
        foreach (
            ['events' => '活动次数', 'objects' => '不同内容', 'days' => '不同自然日']
            as $mode => $label
        ) {
            echo '<option value="' .
                $mode .
                '"' .
                selected($step['mode'] ?? 'events', $mode, false) .
                '>' .
                $label .
                '</option>';
        }
        echo '</select></label><details data-xp-step-scope><summary>限定内容（可选）</summary><label>文章／页面 ID<input name="task[steps][' .
            $index .
            '][post_ids]" value="' .
            esc_attr(implode(',', $step['post_ids'] ?? [])) .
            '" placeholder="留空不限，逗号分隔"></label><label>分类 ID<input name="task[steps][' .
            $index .
            '][category_ids]" value="' .
            esc_attr(implode(',', $step['category_ids'] ?? [])) .
            '" placeholder="与内容 ID 任意命中"></label></details><button type="button" class="button" data-xp-remove-row>移除</button></div>';
    }
}
WP_XP_Core_Achievements_UI::boot();
