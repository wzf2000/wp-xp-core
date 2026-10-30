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
            wp_xp_core_t('任务与成就'),
            wp_xp_core_t('任务与成就'),
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
            throw new InvalidArgumentException(wp_xp_core_t('任务信息无效。'));
        }
        $input['steps'] = $input['steps'] ?? [];
        if (($input['type'] ?? '') === 'review') {
            $input['steps'] = [];
        }
        if (!is_array($input['steps'])) {
            throw new InvalidArgumentException(wp_xp_core_t('任务信息无效。'));
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
    /** Bounded form state preserves invalid drafts without trusting their shape or HTML. */
    public static function draft($input): array
    {
        $input = is_array($input) ? $input : [];
        $text = static fn($value, $max = 300, $multiline = false) => is_scalar($value)
            ? mb_substr(
                preg_replace(
                    $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f]/u' : '/[\x00-\x1f]/u',
                    '',
                    (string) $value,
                ) ?? '',
                0,
                $max,
            )
            : '';
        $task = [];
        foreach (
            [
                'id' => 30,
                'title' => 60,
                'description' => 300,
                'icon' => 8,
                'starts_on' => 10,
                'ends_on' => 10,
                'category' => 40,
                'series' => 60,
                'reward_xp' => 10,
                'image_id' => 10,
            ]
            as $key => $max
        ) {
            $task[$key] = $text($input[$key] ?? '', $max, $key === 'description');
        }
        $task['type'] = in_array($input['type'] ?? '', ['automatic', 'review'], true)
            ? $input['type']
            : 'automatic';
        $task['period'] = in_array($input['period'] ?? '', ['once', 'day', 'week', 'month'], true)
            ? $input['period']
            : 'once';
        $task['enabled'] = in_array($input['enabled'] ?? false, [true, 1, '1'], true);
        $task['steps'] = [];
        foreach (
            array_slice(is_array($input['steps'] ?? null) ? $input['steps'] : [], 0, 8)
            as $step
        ) {
            if (!is_array($step)) {
                continue;
            }
            $row = [
                'kind' => $text($step['kind'] ?? '', 20),
                'mode' => $text($step['mode'] ?? 'events', 20),
                'count' => $text($step['count'] ?? '', 10),
            ];
            foreach (['post_ids', 'category_ids'] as $field) {
                $raw = $step[$field] ?? [];
                $raw = is_array($raw)
                    ? array_slice($raw, 0, 100)
                    : (is_string($raw)
                        ? preg_split('/[ ,，]+/u', mb_substr($raw, 0, 2000))
                        : []);
                $row[$field] = array_values(
                    array_map(
                        static fn($value) => $text($value, 10),
                        array_filter($raw, 'is_scalar'),
                    ),
                );
            }
            $task['steps'][] = $row;
        }
        $task['prerequisites'] = [];
        foreach (
            array_slice(
                is_array($input['prerequisites'] ?? null) ? $input['prerequisites'] : [],
                0,
                8,
            )
            as $pre
        ) {
            if (is_array($pre)) {
                $task['prerequisites'][] = [
                    'id' => $text($pre['id'] ?? '', 30),
                    'scope' => $text($pre['scope'] ?? '', 20),
                ];
            }
        }
        return $task;
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(wp_xp_core_t('没有管理任务的权限。'));
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
                    $method = ($_POST['operation'] ?? '') === 'preview' ? 'preview' : 'save';
                    $result = WP_XP_Core_Achievements::$method(
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
        if (isset($_GET['copy']) && isset($all[$id])) {
            $task = WP_XP_Core_Achievements::validate(
                array_intersect_key(
                    $task,
                    array_flip([
                        'id',
                        'title',
                        'description',
                        'icon',
                        'type',
                        'period',
                        'enabled',
                        'steps',
                        'starts_on',
                        'ends_on',
                        'prerequisites',
                        'reward_xp',
                        'image_id',
                        'category',
                        'series',
                    ]),
                ),
                false,
            );
            $task['id'] = '';
            $task['enabled'] = false;
        }
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST' &&
            !in_array($_POST['operation'] ?? '', ['review', 'grant', 'revoke'], true)
        ) {
            if (is_array($result) && isset($result['id'])) {
                $task = $all[$result['id']];
            } elseif (is_array($result) && isset($result['task'])) {
                $task = $result['task'];
            } elseif (is_wp_error($result)) {
                $task = self::draft(wp_unslash($_POST['task'] ?? []));
            }
        }
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>' .
            esc_html(wp_xp_core_t('任务与成就')) .
            '</h1><p>' .
            esc_html(wp_xp_core_t('让参与有明确目标，为完成的事情留下荣誉。')) .
            '</p></div></header>';
        WP_XP_Core_Management_UI::nav('tasks');
        if (!is_array($result) || empty($result['preview'])) {
            WP_XP_Core_Growth_UI::notice($result);
        }
        if (is_array($result) && !empty($result['preview'])) {
            echo '<div class="notice notice-info"><p>' .
                esc_html(
                    $result['new_task']
                        ? wp_xp_core_t('新建任务，从保存后的活动累计。')
                        : ($result['restarts']
                            ? wp_xp_core_t('将建立新版本，并从后续活动累计。')
                            : wp_xp_core_t('保留当前版本和进度。')),
                ) .
                ' · ' .
                esc_html(wp_xp_core_t('预计版本')) .
                ' ' .
                (int) $result['version'] .
                '</p></div>';
            echo '<div class="notice notice-info"><p>' .
                esc_html(
                    wp_xp_core_t(
                        '预览通过，尚未保存。修改奖励或条件将建立新版本；新任务在保存后才累计活动，不重算历史。',
                    ),
                ) .
                '</p><strong>' .
                esc_html($task['title']) .
                '</strong><p>' .
                esc_html(WP_XP_Core_Achievements::condition_text($task)) .
                ' · ' .
                (int) $task['reward_xp'] .
                esc_html(wp_xp_core_t(' 经验')) .
                '</p></div>';
        }
        echo '<section class="xp-card"><div class="xp-section-heading"><div><h2>' .
            esc_html(wp_xp_core_t('任务列表')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '自动任务按有效活动完成，审核任务由用户提交说明。经验奖励默认关闭，可为任务单独设置。',
                ),
            ) .
            '</p></div><a class="button" href="' .
            esc_url(self::url()) .
            '">' .
            esc_html(wp_xp_core_t('新建任务')) .
            '</a></div><div class="xp-table-scroll"><table class="widefat striped"><thead><tr><th>' .
            esc_html(wp_xp_core_t('任务')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('方式')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('周期')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('状态')) .
            '</th><th>' .
            esc_html(wp_xp_core_t('操作')) .
            '</th></tr></thead><tbody>';
        foreach ($all as $row) {
            echo '<tr><td>' .
                esc_html(($row['icon'] ?: '◇') . ' ' . $row['title']) .
                '</td><td>' .
                ($row['type'] === 'automatic'
                    ? wp_xp_core_t('自动完成')
                    : wp_xp_core_t('提交审核')) .
                '</td><td>' .
                esc_html(self::period_label($row['period'])) .
                '</td><td>' .
                ($row['enabled'] ? wp_xp_core_t('已启用') : wp_xp_core_t('已停用')) .
                '</td><td><a href="' .
                esc_url(self::url(['edit' => $row['id']])) .
                '">' .
                esc_html(wp_xp_core_t('编辑')) .
                '</a> · <a href="' .
                esc_url(self::url(['edit' => $row['id'], 'copy' => 1])) .
                '">' .
                esc_html(wp_xp_core_t('复制为新任务')) .
                '</a></td></tr>';
        }
        if (!$all) {
            echo '<tr><td colspan="5">' .
                esc_html(wp_xp_core_t('还没有任务。可以先创建一个简单的签到任务。')) .
                '</td></tr>';
        }
        echo '</tbody></table></div></section><section class="xp-card"><h2>' .
            ($task['id'] ? wp_xp_core_t('编辑任务') : wp_xp_core_t('新建任务')) .
            '</h2><form method="post" class="xp-task-editor">';
        wp_nonce_field('wp_xp_core_achievements');
        echo '<input type="hidden" name="revision" value="' .
            esc_attr(WP_XP_Core_Achievements::revision()) .
            '"><input type="hidden" name="task[id]" value="' .
            esc_attr($task['id']) .
            '"><label>' .
            esc_html(wp_xp_core_t('任务名称')) .
            '<input name="task[title]" maxlength="60" required value="' .
            esc_attr($task['title']) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('任务说明')) .
            '<textarea name="task[description]" maxlength="300" rows="3">' .
            esc_html($task['description']) .
            '</textarea></label><div class="xp-task-options"><label>' .
            esc_html(wp_xp_core_t('图标字符')) .
            '<input name="task[icon]" maxlength="8" value="' .
            esc_attr($task['icon']) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('例如 ★')) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('完成方式')) .
            '<select name="task[type]" data-xp-task-type><option value="automatic"' .
            selected($task['type'], 'automatic', false) .
            '>' .
            esc_html(wp_xp_core_t('自动完成')) .
            '</option><option value="review"' .
            selected($task['type'], 'review', false) .
            '>' .
            esc_html(wp_xp_core_t('提交说明后审核')) .
            '</option></select></label><label>' .
            esc_html(wp_xp_core_t('周期')) .
            '<select name="task[period]">';
        foreach (
            [
                'once' => wp_xp_core_t('一次性'),
                'day' => wp_xp_core_t('每日'),
                'week' => wp_xp_core_t('每周'),
                'month' => wp_xp_core_t('每月'),
            ]
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
        echo '</select></label></div><fieldset><legend>' .
            esc_html(wp_xp_core_t('开放日期与前置任务')) .
            '</legend><div class="xp-task-options"><label>' .
            esc_html(wp_xp_core_t('开始日期')) .
            '<input type="date" name="task[starts_on]" value="' .
            esc_attr($task['starts_on'] ?? '') .
            '"></label><label>' .
            esc_html(wp_xp_core_t('结束日期')) .
            '<input type="date" name="task[ends_on]" value="' .
            esc_attr($task['ends_on'] ?? '') .
            '"></label></div><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '日期包含首尾，使用经验规则的时区；留空表示不限。前置任务全部完成后可领取或提交，本任务仍从本版创建后的有效活动累计。',
                ),
            ) .
            '</p>';
        echo '<details><summary>' . esc_html(wp_xp_core_t('前置任务（可选）')) . '</summary>';
        $prerequisites = $task['prerequisites'] ?? [];
        for ($i = 0; $i < 8; $i++) {
            echo '<div class="xp-task-options"><label>前置任务 ' .
                ($i + 1) .
                '<select name="task[prerequisites][' .
                $i .
                '][id]"><option value="">' .
                esc_html(wp_xp_core_t('无')) .
                '</option>';
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
            echo '</select></label><label>' .
                esc_html(wp_xp_core_t('完成范围')) .
                '<select name="task[prerequisites][' .
                $i .
                '][scope]">';
            foreach (
                [
                    'current' => wp_xp_core_t('当前版本与当前周期'),
                    'lifetime' => wp_xp_core_t('终身（含旧版本荣誉）'),
                ]
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
        echo '</details></fieldset><fieldset class="xp-task-reward-options"><legend>' .
            esc_html(wp_xp_core_t('成就展示与可选奖励')) .
            '</legend><div class="xp-task-options"><label>' .
            esc_html(wp_xp_core_t('分类')) .
            '<input name="task[category]" maxlength="40" value="' .
            esc_attr($task['category'] ?? '') .
            '"></label><label>' .
            esc_html(wp_xp_core_t('系列')) .
            '<input name="task[series]" maxlength="60" value="' .
            esc_attr($task['series'] ?? '') .
            '"></label><label>' .
            esc_html(wp_xp_core_t('完成经验')) .
            '<input type="number" min="0" max="1000000" name="task[reward_xp]" value="' .
            (int) ($task['reward_xp'] ?? 0) .
            '"></label></div><p class="xp-help">' .
            esc_html(wp_xp_core_t('0 表示只授予荣誉。修改奖励会建立新版本，已有成就不补发奖励。')) .
            '</p><div class="xp-task-options"><label>' .
            esc_html(wp_xp_core_t('媒体库徽章图片 ID')) .
            '<input type="number" min="0" name="task[image_id]" data-xp-badge-image value="' .
            (int) ($task['image_id'] ?? 0) .
            '"></label><button type="button" class="button" data-xp-pick-badge>' .
            esc_html(wp_xp_core_t('从媒体库选择图片')) .
            '</button><button type="button" class="button" data-xp-clear-badge>' .
            esc_html(wp_xp_core_t('使用图标字符')) .
            '</button></div><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '仅使用可公开展示的图片。图片删除或不再公开时回退图标字符；修改展示资料不改变旧成就快照。',
                ),
            ) .
            '</p></fieldset><input type="hidden" name="task[enabled]" value="0"><label class="xp-toggle"><input type="checkbox" name="task[enabled]" value="1"' .
            checked($task['enabled'], true, false) .
            '>' .
            esc_html(wp_xp_core_t('启用任务')) .
            '</label><fieldset class="xp-task-steps" data-xp-auto-steps><legend>' .
            esc_html(wp_xp_core_t('全部完成以下步骤')) .
            '</legend><div id="xp-task-steps">';
        foreach ($task['steps'] as $index => $step) {
            self::step($index, $step);
        }
        echo '</div><template id="xp-task-step-template">';
        self::step('__INDEX__', ['kind' => 'checkin', 'count' => 1]);
        echo '</template><button type="button" class="button" data-xp-add-step>' .
            esc_html(wp_xp_core_t('添加步骤')) .
            '</button></fieldset><p class="xp-task-review-help">' .
            esc_html(
                wp_xp_core_t(
                    '审核任务不使用自动步骤。用户可填写完成说明，管理员通过后按本版奖励授予成就；退回时需要说明原因。',
                ),
            ) .
            '</p><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '修改名称、说明、徽章图片、分类或系列会保留进度；修改条件、周期、开放日期、前置任务或启用状态会建立新版本，从之后的新活动累计。旧成就保留。每周从周一开始，每月从一日开始。',
                ),
            ) .
            '</p><button name="operation" value="preview" class="button">' .
            esc_html(wp_xp_core_t('预览任务')) .
            '</button> <button name="operation" value="save" class="button button-primary">' .
            esc_html(wp_xp_core_t('保存任务')) .
            '</button><p class="xp-task-editor-message" role="status"></p></form></section><section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('待审核说明')) .
            '</h2>';
        $cursor =
            isset($_GET['review_cursor']) && is_scalar($_GET['review_cursor'])
                ? max(0, (int) $_GET['review_cursor'])
                : 0;
        $pending = WP_XP_Core_Achievements::pending($cursor);
        if (is_wp_error($pending)) {
            WP_XP_Core_Growth_UI::notice($pending);
        } else {
            if (!$pending['items']) {
                echo '<p>' . esc_html(wp_xp_core_t('目前没有等待审核的说明。')) . '</p>';
            }
            foreach ($pending['items'] as $row) {
                echo '<article class="xp-review-item"><h3>' .
                    esc_html($row['title']) .
                    wp_xp_core_t(' · 用户 #') .
                    (int) $row['user_id'] .
                    '</h3><p class="xp-submission-text">' .
                    esc_html($row['text']) .
                    '</p><form method="post" class="xp-review-form">';
                wp_nonce_field('wp_xp_core_achievements');
                echo '<input type="hidden" name="operation" value="review"><input type="hidden" name="submission_id" value="' .
                    (int) $row['id'] .
                    '"><label>' .
                    esc_html(wp_xp_core_t('审核意见')) .
                    '<input name="feedback" maxlength="300"></label><button name="decision" value="approve" class="button button-primary">' .
                    esc_html(wp_xp_core_t('通过并授予成就')) .
                    '</button><button name="decision" value="reject" class="button">' .
                    esc_html(wp_xp_core_t('退回说明')) .
                    '</button></form></article>';
            }
        }
        if (!is_wp_error($pending) && $pending['next_cursor']) {
            echo '<a class="button" href="' .
                esc_url(self::url(['review_cursor' => $pending['next_cursor']])) .
                '">' .
                esc_html(wp_xp_core_t('更早的待审核说明')) .
                '</a>';
        }
        echo '</section>';
        self::management($all);
        echo '</div>';
    }
    private static function management(array $tasks): void
    {
        $enabled = array_filter($tasks, fn($t) => !empty($t['enabled']));
        $first = $enabled ? reset($enabled) : null;
        echo '<section class="xp-card"><h2>' .
            esc_html(wp_xp_core_t('人工授予与撤销')) .
            '</h2><p>' .
            esc_html(
                wp_xp_core_t(
                    '操作需要填写原因并检查目标用户权限。人工授予可确认自动步骤以外的完成依据，仍需满足任务日期与前置条件；其经验不进入活动榜。',
                ),
            ) .
            '</p>';
        if ($first) {
            echo '<details><summary>' .
                esc_html(wp_xp_core_t('授予当前周期成就')) .
                '</summary><form method="post" class="xp-task-editor" data-xp-grant-form>';
            wp_nonce_field('wp_xp_core_achievements');
            echo '<input type="hidden" name="operation" value="grant"><input type="hidden" name="achievement[request_id]" value="' .
                esc_attr(wp_generate_uuid4()) .
                '"><input type="hidden" name="achievement[version]" value="' .
                (int) $first['version'] .
                '"><input type="hidden" name="achievement[period]" value="' .
                esc_attr(WP_XP_Core_Achievements::period($first)['key']) .
                '"><label>' .
                esc_html(wp_xp_core_t('用户编号')) .
                '<input type="number" name="achievement[user_id]" min="1" required></label><label>' .
                esc_html(wp_xp_core_t('任务')) .
                '<select name="achievement[task_id]">';
            foreach ($enabled as $task) {
                echo '<option value="' .
                    esc_attr($task['id']) .
                    '" data-version="' .
                    (int) $task['version'] .
                    '" data-period="' .
                    esc_attr(WP_XP_Core_Achievements::period($task)['key']) .
                    '">' .
                    esc_html(
                        $task['title'] .
                            wp_xp_core_t('（经验 +') .
                            (int) ($task['reward_xp'] ?? 0) .
                            '）',
                    ) .
                    '</option>';
            }
            echo '</select></label><label>' .
                esc_html(wp_xp_core_t('授予原因')) .
                '<textarea name="achievement[reason]" maxlength="300" required></textarea></label><button class="button button-primary">' .
                esc_html(wp_xp_core_t('授予成就')) .
                '</button></form></details>';
        }
        echo '<details><summary>' .
            esc_html(wp_xp_core_t('撤销已获成就')) .
            '</summary><form method="post" class="xp-task-editor">';
        wp_nonce_field('wp_xp_core_achievements');
        echo '<input type="hidden" name="operation" value="revoke"><input type="hidden" name="achievement[request_id]" value="' .
            esc_attr(wp_generate_uuid4()) .
            '"><label>' .
            esc_html(wp_xp_core_t('原授予事件编号')) .
            '<input type="number" name="achievement[event_id]" min="1" required></label><label>' .
            esc_html(wp_xp_core_t('撤销原因')) .
            '<textarea name="achievement[reason]" maxlength="300" required></textarea></label><p class="xp-help">' .
            esc_html(
                wp_xp_core_t(
                    '在事件账本中查找“完成任务”或“人工授予成就”的事件编号。撤销会扣回原经验，余额不足时整项拒绝；同一版本与周期不能再次授予。',
                ),
            ) .
            '</p><button class="button">' .
            esc_html(wp_xp_core_t('撤销成就并扣回原经验')) .
            '</button></form></details></section>';
    }
    public static function period_label(string $period): string
    {
        return [
            'once' => wp_xp_core_t('一次性'),
            'day' => wp_xp_core_t('每日'),
            'week' => wp_xp_core_t('每周'),
            'month' => wp_xp_core_t('每月'),
        ][$period] ?? $period;
    }
    private static function step($index, array $step): void
    {
        echo '<div class="xp-task-step"><label>' .
            esc_html(wp_xp_core_t('参与活动')) .
            '<select name="task[steps][' .
            $index .
            '][kind]" data-xp-step-kind>';
        foreach (
            [
                'checkin' => wp_xp_core_t('每日签到'),
                'visit' => wp_xp_core_t('有效阅读'),
                'comment' => wp_xp_core_t('有效评论'),
                'article' => wp_xp_core_t('发表文章'),
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
        echo '</select></label><label>' .
            esc_html(wp_xp_core_t('次数')) .
            '<input type="number" min="1" max="1000" required name="task[steps][' .
            $index .
            '][count]" value="' .
            (int) $step['count'] .
            '"></label><label>' .
            esc_html(wp_xp_core_t('计数方式')) .
            '<select name="task[steps][' .
            $index .
            '][mode]" data-xp-step-mode>';
        foreach (
            [
                'events' => wp_xp_core_t('活动次数'),
                'objects' => wp_xp_core_t('不同内容'),
                'days' => wp_xp_core_t('不同自然日'),
            ]
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
        echo '</select></label><details data-xp-step-scope><summary>' .
            esc_html(wp_xp_core_t('限定内容（可选）')) .
            '</summary><label>' .
            esc_html(wp_xp_core_t('文章／页面 ID')) .
            '<input name="task[steps][' .
            $index .
            '][post_ids]" value="' .
            esc_attr(implode(',', $step['post_ids'] ?? [])) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('留空不限，逗号分隔')) .
            '"></label><label>' .
            esc_html(wp_xp_core_t('分类 ID')) .
            '<input name="task[steps][' .
            $index .
            '][category_ids]" value="' .
            esc_attr(implode(',', $step['category_ids'] ?? [])) .
            '" placeholder="' .
            esc_attr(wp_xp_core_t('与内容 ID 任意命中')) .
            '"></label></details><button type="button" class="button" data-xp-remove-row>' .
            esc_html(wp_xp_core_t('移除')) .
            '</button></div>';
    }
}
WP_XP_Core_Achievements_UI::boot();
