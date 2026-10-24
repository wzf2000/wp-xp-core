<?php
/** Optional, non-monetary tasks. Definitions never replay or grant historical rewards. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Achievements
{
    const OPTION = 'wp_xp_core_achievements';
    const KINDS = ['checkin', 'visit', 'comment', 'article'];
    public static function definitions(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }
    public static function revision(): string
    {
        return WP_XP_Core_Settings::revision(get_option(self::OPTION, null));
    }
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function integer($value, int $min, int $max): int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
            strlen((string) $value) > 10 ||
            (int) $value < $min ||
            (int) $value > $max
        ) {
            throw new InvalidArgumentException('请填写有效的次数。');
        }
        return (int) $value;
    }
    private static function text($value, int $max): string
    {
        if (
            !is_string($value) ||
            mb_strlen($value) > $max ||
            preg_match('/[<>\x00-\x1f]/u', $value)
        ) {
            throw new InvalidArgumentException('任务文字无效或过长。');
        }
        return trim($value);
    }
    public static function validate(array $input): array
    {
        if (
            array_diff(array_keys($input), [
                'id',
                'title',
                'description',
                'icon',
                'type',
                'period',
                'enabled',
                'steps',
            ])
        ) {
            throw new InvalidArgumentException('任务字段无效。');
        }
        $id = $input['id'] ?? '';
        if (!is_string($id) || ($id !== '' && !preg_match('/^t_[a-f0-9]{16}$/D', $id))) {
            throw new InvalidArgumentException('任务编号无效。');
        }
        $title = self::text($input['title'] ?? '', 60);
        if ($title === '') {
            throw new InvalidArgumentException('请填写任务名称。');
        }
        if (
            !in_array($input['type'] ?? '', ['automatic', 'review'], true) ||
            !in_array($input['period'] ?? '', ['once', 'day', 'week'], true) ||
            !in_array($input['enabled'] ?? false, [true, false, 0, 1, '0', '1'], true)
        ) {
            throw new InvalidArgumentException('请选择任务类型、周期和启用状态。');
        }
        if (!is_array($input['steps'] ?? null) || count($input['steps']) > 8) {
            throw new InvalidArgumentException('每个任务最多八个步骤。');
        }
        $steps = [];
        foreach ($input['steps'] as $step) {
            if (
                !is_array($step) ||
                array_diff(array_keys($step), ['kind', 'count']) ||
                !in_array($step['kind'] ?? '', self::KINDS, true)
            ) {
                throw new InvalidArgumentException('请选择有效的任务步骤。');
            }
            $steps[] = [
                'kind' => $step['kind'],
                'count' => self::integer($step['count'] ?? null, 1, 1000),
            ];
        }
        if ($input['type'] === 'automatic' && !$steps) {
            throw new InvalidArgumentException('自动任务至少需要一个步骤。');
        }
        if ($input['type'] === 'review' && $steps) {
            throw new InvalidArgumentException('审核任务通过提交说明完成，无需自动步骤。');
        }
        return [
            'id' => $id,
            'title' => $title,
            'description' => self::text($input['description'] ?? '', 300),
            'icon' => self::text($input['icon'] ?? '', 8),
            'type' => $input['type'],
            'period' => $input['period'],
            'enabled' => (bool) $input['enabled'],
            'steps' => $steps,
        ];
    }
    public static function clear_cache(): void
    {
        foreach ([self::OPTION, 'alloptions', 'notoptions'] as $name) {
            wp_cache_delete($name, 'options');
        }
    }
    public static function save(array $input, $nonce, $revision)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', '没有管理任务的权限。', 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_achievements')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', '经验系统维护中。', 503);
        }
        try {
            $task = self::validate($input);
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        }
        try {
            return Reader_Experience::transaction(function () use ($task, $revision) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                WP_XP_Core_Management::require_options_transaction();
                self::clear_cache();
                $raw = get_option(self::OPTION, null);
                if (
                    !is_string($revision) ||
                    !hash_equals(WP_XP_Core_Settings::revision($raw), $revision)
                ) {
                    return self::error('conflict', '任务列表已更新，请刷新后再修改。', 409);
                }
                $old = self::definitions();
                $next = $old;
                $value = $task;
                $existing = $old[$task['id']] ?? null;
                if ($task['id'] !== '' && !$existing) {
                    return self::error('missing', '任务不存在。', 404);
                }
                if (!$existing && count($old) >= 50) {
                    return self::error('limit', '最多保留五十个任务，可先停用不再使用的任务。');
                }
                if (!$existing) {
                    $value['id'] = 't_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
                }
                $base = $existing
                    ? array_intersect_key(
                        $existing,
                        array_flip([
                            'id',
                            'title',
                            'description',
                            'icon',
                            'type',
                            'period',
                            'enabled',
                            'steps',
                        ]),
                    )
                    : null;
                if ($base === $value) {
                    return ['id' => $value['id'], 'unchanged' => true];
                }
                $value['number'] = $existing ? (int) $existing['number'] : count($old) + 1;
                $value['version'] = $existing ? (int) $existing['version'] + 1 : 1;
                global $wpdb;
                $boundary = $wpdb->get_var(
                    'SELECT COALESCE(MAX(id),0) FROM ' . Reader_Experience::table(),
                );
                if ($wpdb->last_error !== '' || $boundary === null) {
                    throw new RuntimeException('Task boundary unavailable');
                }
                $value['start_event_id'] = (int) $boundary;
                $value['created_at'] = time();
                $next[$value['id']] = $value;
                Reader_Experience::touch_option(self::OPTION);
                if (!update_option(self::OPTION, $next, false)) {
                    throw new RuntimeException('Task write failed');
                }
                WP_XP_Core_Management::audit('achievement_definition', $raw, $next);
                return ['id' => $value['id'], 'unchanged' => false];
            });
        } catch (Throwable $e) {
            self::clear_cache();
            return self::error('storage', '任务未能保存，请重试。', 503);
        }
    }
    public static function period(array $task): array
    {
        $today = Reader_Experience::day();
        $date = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        return match ($task['period']) {
            'day' => ['key' => $today, 'from' => $today],
            'week' => [
                'key' => $date->format('o-W'),
                'from' => $date->modify('monday this week')->format('Y-m-d'),
            ],
            default => ['key' => 'once', 'from' => '0001-01-01'],
        };
    }
    private static function key(array $task, int $uid): string
    {
        return 'task:' .
            $task['id'] .
            ':' .
            $task['version'] .
            ':' .
            self::period($task)['key'] .
            ':' .
            $uid;
    }
    private static function event(string $key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                $key,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Task event unavailable');
        }
        return $row ?: null;
    }
    public static function progress(array $task, int $uid): array
    {
        global $wpdb;
        $period = self::period($task);
        $steps = [];
        $complete = $task['type'] === 'automatic';
        foreach ($task['steps'] as $step) {
            $where = $wpdb->prepare(
                ' e.user_id=%d AND e.kind=%s AND e.id>%d AND e.local_day>=%s',
                $uid,
                $step['kind'],
                $task['start_event_id'],
                $period['from'],
            );
            $join =
                $step['kind'] === 'comment'
                    ? " INNER JOIN {$wpdb->comments} c ON c.comment_ID=e.object_id INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID "
                    : '';
            if ($step['kind'] === 'comment') {
                $where .=
                    " AND c.comment_approved='1' AND p.post_status='publish' AND c.user_id=e.user_id";
            }
            $n = (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' .
                    Reader_Experience::table() .
                    ' e ' .
                    $join .
                    ' WHERE ' .
                    $where,
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Task progress unavailable');
            }
            $done = $n >= $step['count'];
            $complete = $complete && $done;
            $steps[] = $step + ['current' => min($n, $step['count']), 'complete' => $done];
        }
        return [
            'steps' => $steps,
            'complete' => $complete,
            'earned' => self::event(self::key($task, $uid)) !== null,
            'period' => $period['key'],
        ];
    }
    private static function permission($nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', '请先登录。', 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', '经验系统维护中。', 503);
        }
        return true;
    }
    private static function active($id): ?array
    {
        if (!is_string($id)) {
            return null;
        }
        $task = self::definitions()[$id] ?? null;
        return $task && !empty($task['enabled']) ? $task : null;
    }
    private static function award(array $task, int $uid, array $evidence = []): bool
    {
        return Reader_Experience::record(
            self::key($task, $uid),
            $uid,
            'task_complete',
            0,
            0,
            Reader_Experience::day(),
            [
                'task_id' => $task['id'],
                'version' => $task['version'],
                'title' => $task['title'],
                'icon' => $task['icon'],
                'period' => self::period($task)['key'],
            ] + $evidence,
        );
    }
    public static function claim($id, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            return Reader_Experience::transaction(function () use ($id) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                $task = self::active($id);
                if (!$task || $task['type'] !== 'automatic') {
                    return self::error('missing', '任务当前不可领取。', 404);
                }
                $uid = get_current_user_id();
                $progress = self::progress($task, $uid);
                if ($progress['earned']) {
                    return ['earned' => true, 'duplicate' => true];
                }
                if (!$progress['complete']) {
                    return self::error('incomplete', '任务步骤尚未完成。', 409);
                }
                self::award($task, $uid);
                return ['earned' => true, 'duplicate' => false];
            });
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法记录任务，请重试。', 503);
        }
    }
    public static function committed(array $event): void
    {
        if (!in_array($event['kind'] ?? '', self::KINDS, true)) {
            return;
        }
        $uid = (int) $event['user_id'];
        foreach (self::definitions() as $task) {
            if (
                empty($task['enabled']) ||
                $task['type'] !== 'automatic' ||
                (int) $event['id'] <= $task['start_event_id']
            ) {
                continue;
            }
            $matches = array_filter($task['steps'], fn($step) => $step['kind'] === $event['kind']);
            if (!$matches) {
                continue;
            }
            Reader_Experience::transaction(function () use ($task, $uid) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return;
                }
                $current = self::active($task['id']);
                if (!$current || $current['version'] !== $task['version']) {
                    return;
                }
                $progress = self::progress($current, $uid);
                if (!$progress['earned'] && $progress['complete']) {
                    self::award($current, $uid);
                }
            });
        }
    }
    private static function submissions(
        int $uid = 0,
        ?array $task = null,
        bool $pending = false,
        int $cursor = 0,
        int $limit = 100,
    ): array {
        global $wpdb;
        $where = $uid ? $wpdb->prepare(' AND e.user_id=%d', $uid) : '';
        if ($task) {
            $where .= $wpdb->prepare(
                ' AND e.object_id=%d AND e.id>%d AND e.local_day>=%s',
                $task['number'],
                $task['start_event_id'],
                self::period($task)['from'],
            );
        }
        if ($pending) {
            $where .=
                ' AND NOT EXISTS (SELECT 1 FROM ' .
                Reader_Experience::table() .
                " r WHERE r.kind='achievement_review' AND r.object_id=e.id)";
        }
        if ($cursor) {
            $where .= $wpdb->prepare(' AND e.id<%d', $cursor);
        }
        $rows = $wpdb->get_results(
            'SELECT e.id,e.user_id,e.detail,e.created_at,r.detail AS review_detail FROM ' .
                Reader_Experience::table() .
                ' e LEFT JOIN ' .
                Reader_Experience::table() .
                " r ON r.event_key=CONCAT('task-review:',e.id) WHERE e.kind='achievement_submit'" .
                $where .
                ' ORDER BY e.id DESC LIMIT ' .
                (int) $limit,
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Submissions unavailable');
        }
        $out = [];
        foreach ($rows as $row) {
            $detail = json_decode($row['detail'], true);
            if (!is_array($detail)) {
                continue;
            }
            $decision = $row['review_detail'] ? json_decode($row['review_detail'], true) : null;
            $out[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'created_at' => (int) $row['created_at'],
                'task_id' => $detail['task_id'],
                'version' => (int) $detail['version'],
                'period' => $detail['period'],
                'title' => $detail['title'],
                'text' => $detail['text'],
                'status' => $decision
                    ? ($decision['decision'] === 'approve'
                        ? 'approved'
                        : 'rejected')
                    : 'pending',
                'feedback' => $decision['feedback'] ?? '',
            ];
        }
        return $out;
    }
    public static function submit(array $input, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            if (
                array_diff(array_keys($input), ['task_id', 'text', 'request_id']) ||
                !is_string($input['request_id'] ?? null) ||
                !preg_match(
                    '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',
                    $input['request_id'],
                )
            ) {
                return self::error('input', '提交信息无效。');
            }
            $text = self::text($input['text'] ?? '', 1000);
            if (mb_strlen($text) < 5) {
                return self::error('input', '请填写至少五个字的完成说明。');
            }
            return Reader_Experience::transaction(function () use ($input, $text) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                $uid = get_current_user_id();
                $key = 'task-submit:' . $uid . ':' . $input['request_id'];
                $fingerprint = hash('sha256', wp_json_encode([$input['task_id'], $text]));
                $existing = self::event($key);
                if ($existing) {
                    $detail = json_decode($existing['detail'], true);
                    return ($detail['fingerprint'] ?? '') === $fingerprint
                        ? ['submitted' => true, 'duplicate' => true]
                        : self::error('conflict', '提交编号已用于另一份说明。', 409);
                }
                $task = self::active($input['task_id']);
                if (!$task || $task['type'] !== 'review') {
                    return self::error('missing', '审核任务当前不可用。', 404);
                }
                if (self::event(self::key($task, $uid))) {
                    return self::error('earned', '本期成就已经获得。', 409);
                }
                $attempts = 0;
                foreach (self::submissions($uid, $task) as $row) {
                    if (
                        $row['task_id'] !== $task['id'] ||
                        $row['version'] !== $task['version'] ||
                        $row['period'] !== self::period($task)['key']
                    ) {
                        continue;
                    }
                    $attempts++;
                    if ($row['status'] === 'pending') {
                        return self::error('pending', '这项任务已有一份说明等待审核。', 409);
                    }
                }
                if ($attempts >= 5) {
                    return self::error('limit', '本期提交次数已达上限，请联系管理员。', 409);
                }
                Reader_Experience::record(
                    $key,
                    $uid,
                    'achievement_submit',
                    $task['number'],
                    0,
                    Reader_Experience::day(),
                    [
                        'task_id' => $task['id'],
                        'version' => $task['version'],
                        'period' => self::period($task)['key'],
                        'title' => $task['title'],
                        'text' => $text,
                        'fingerprint' => $fingerprint,
                    ],
                );
                return ['submitted' => true, 'duplicate' => false];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '说明未能提交，请重试。', 503);
        }
    }
    public static function review(array $input, $nonce)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', '没有审核成就的权限。', 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_achievements')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        if (!Reader_Experience::ready()) {
            return self::error('maintenance', '经验系统维护中。', 503);
        }
        try {
            if (
                array_diff(array_keys($input), ['submission_id', 'decision', 'feedback']) ||
                !in_array($input['decision'] ?? '', ['approve', 'reject'], true)
            ) {
                return self::error('input', '审核内容无效。');
            }
            $id = self::integer($input['submission_id'] ?? null, 1, 2147483647);
            $feedback = self::text($input['feedback'] ?? '', 300);
            if ($input['decision'] === 'reject' && $feedback === '') {
                return self::error('input', '请填写退回原因。');
            }
            return Reader_Experience::transaction(function () use ($id, $input, $feedback) {
                self::clear_cache();
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                global $wpdb;
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM ' .
                            Reader_Experience::table() .
                            " WHERE id=%d AND kind='achievement_submit'",
                        $id,
                    ),
                    ARRAY_A,
                );
                if (!$row) {
                    return self::error('missing', '提交记录不存在。', 404);
                }
                $uid = (int) $row['user_id'];
                $permission = WP_XP_Core_Management::permission($uid);
                if ($permission !== true) {
                    return $permission;
                }
                if (!get_userdata($uid)) {
                    return self::error('missing', '该用户已经不存在。', 404);
                }
                $key = 'task-review:' . $id;
                $existing = self::event($key);
                if ($existing) {
                    $d = json_decode($existing['detail'], true);
                    return $d['decision'] === $input['decision'] && $d['feedback'] === $feedback
                        ? ['reviewed' => true, 'duplicate' => true]
                        : self::error('conflict', '这份说明已被审核，请刷新页面。', 409);
                }
                $detail = json_decode($row['detail'], true);
                $task = self::active($detail['task_id']);
                if ($input['decision'] === 'approve') {
                    if (
                        !$task ||
                        $task['version'] !== (int) $detail['version'] ||
                        self::period($task)['key'] !== $detail['period']
                    ) {
                        return self::error(
                            'changed',
                            '任务已修改、停用或本期已结束，可退回说明并告知用户。',
                            409,
                        );
                    }
                    self::award($task, $uid, [
                        'submission_id' => $id,
                        'reviewer' => get_current_user_id(),
                    ]);
                }
                Reader_Experience::record(
                    $key,
                    $uid,
                    'achievement_review',
                    $id,
                    0,
                    Reader_Experience::day(),
                    [
                        'decision' => $input['decision'],
                        'feedback' => $feedback,
                        'actor' => get_current_user_id(),
                        'submission_id' => $id,
                    ],
                );
                return ['reviewed' => true, 'duplicate' => false];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '审核未能保存，请重试。', 503);
        }
    }
    public static function pending(int $cursor = 0, int $limit = 25)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', '没有查看审核记录的权限。', 403);
        }
        if ($cursor < 0 || $limit < 1 || $limit > 100) {
            return self::error('input', '审核分页无效。');
        }
        try {
            $rows = self::submissions(0, null, true, $cursor, $limit + 1);
            $more = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            return [
                'items' => $rows,
                'next_cursor' => $more ? $rows[count($rows) - 1]['id'] : null,
            ];
        } catch (Throwable $e) {
            return self::error('storage', '审核记录暂不可读。', 503);
        }
    }
    /** One ledger scan for the whole personal task list, independent of step count. */
    private static function personal_progress(array $tasks, int $uid): array
    {
        global $wpdb;
        if (!$tasks) {
            return [];
        }
        $select = [];
        $aliases = [];
        $keys = [];
        $pending = [];
        $minimum = PHP_INT_MAX;
        foreach ($tasks as $task) {
            $keys[] = self::key($task, $uid);
            $minimum = min($minimum, $task['start_event_id']);
            foreach ($task['steps'] as $i => $step) {
                $alias = 'n' . count($select);
                $condition = $wpdb->prepare(
                    'e.kind=%s AND e.id>%d AND e.local_day>=%s',
                    $step['kind'],
                    $task['start_event_id'],
                    self::period($task)['from'],
                );
                if ($step['kind'] === 'comment') {
                    $condition .=
                        " AND c.comment_approved='1' AND p.post_status='publish' AND c.user_id=e.user_id";
                }
                $select[] = 'COALESCE(SUM(' . $condition . '),0) AS ' . $alias;
                $aliases[$task['id']][$i] = $alias;
            }
            if ($task['type'] === 'review') {
                $pending[] = $wpdb->prepare(
                    '(e.object_id=%d AND e.id>%d AND e.local_day>=%s)',
                    $task['number'],
                    $task['start_event_id'],
                    self::period($task)['from'],
                );
            }
        }
        $counts = [];
        if ($select) {
            $counts = $wpdb->get_row(
                'SELECT ' .
                    implode(',', $select) .
                    ' FROM ' .
                    Reader_Experience::table() .
                    " e LEFT JOIN {$wpdb->comments} c ON e.kind='comment' AND c.comment_ID=e.object_id LEFT JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE " .
                    $wpdb->prepare('e.user_id=%d AND e.id>%d', $uid, $minimum),
                ARRAY_A,
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Task counters unavailable');
            }
        }
        $earned = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT event_key FROM ' .
                    Reader_Experience::table() .
                    ' WHERE event_key IN (' .
                    implode(',', array_fill(0, count($keys), '%s')) .
                    ')',
                ...$keys,
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Task receipts unavailable');
        }
        $pending_numbers = [];
        if ($pending) {
            $pending_numbers = $wpdb->get_col(
                'SELECT e.object_id FROM ' .
                    Reader_Experience::table() .
                    ' e WHERE ' .
                    $wpdb->prepare("e.user_id=%d AND e.kind='achievement_submit'", $uid) .
                    ' AND (' .
                    implode(' OR ', $pending) .
                    ') AND NOT EXISTS (SELECT 1 FROM ' .
                    Reader_Experience::table() .
                    " r WHERE r.event_key=CONCAT('task-review:',e.id))",
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Pending tasks unavailable');
            }
        }
        $out = [];
        foreach ($tasks as $task) {
            $steps = [];
            $complete = $task['type'] === 'automatic';
            foreach ($task['steps'] as $i => $step) {
                $n = (int) ($counts[$aliases[$task['id']][$i]] ?? 0);
                $done = $n >= $step['count'];
                $complete = $complete && $done;
                $steps[] = $step + ['current' => min($n, $step['count']), 'complete' => $done];
            }
            $out[$task['id']] = [
                'steps' => $steps,
                'complete' => $complete,
                'earned' => in_array(self::key($task, $uid), $earned, true),
                'period' => self::period($task)['key'],
                'pending_submission' => in_array(
                    (string) $task['number'],
                    array_map('strval', $pending_numbers),
                    true,
                ),
            ];
        }
        return $out;
    }
    public static function personal(): array
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return ['tasks' => [], 'badges' => [], 'submissions' => []];
        }
        $uid = get_current_user_id();
        $tasks = [];
        $definitions = array_filter(self::definitions(), fn($task) => !empty($task['enabled']));
        $progress = self::personal_progress($definitions, $uid);
        foreach ($definitions as $task) {
            if (!$task['enabled']) {
                continue;
            }
            $tasks[] =
                [
                    'id' => $task['id'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'icon' => $task['icon'],
                    'period' => $task['period'],
                    'period_key' => self::period($task)['key'],
                    'version' => $task['version'],
                    'type' => $task['type'],
                ] + $progress[$task['id']];
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT detail,local_day FROM ' .
                    Reader_Experience::table() .
                    " WHERE user_id=%d AND kind='task_complete' ORDER BY id DESC LIMIT 50",
                $uid,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Achievements unavailable');
        }
        $badges = [];
        foreach ($rows as $row) {
            $d = json_decode($row['detail'], true);
            if (is_array($d)) {
                $badges[] = [
                    'title' => $d['title'],
                    'icon' => $d['icon'],
                    'day' => $row['local_day'],
                ];
            }
        }
        return [
            'tasks' => $tasks,
            'badges' => $badges,
            'submissions' => self::submissions($uid),
            'pending' => self::submissions($uid, null, true),
        ];
    }
}
add_action('wp_xp_core_event_committed', [WP_XP_Core_Achievements::class, 'committed'], 20);
