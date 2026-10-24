<?php
/** Read-only activity standings and administrative aggregates over the existing ledger. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Insights
{
    const ACTIVITY_KINDS = [
        'checkin',
        'visit',
        'article',
        'comment',
        'comment_adjust',
        'milestone_view',
        'milestone_like',
        'streak_reward',
    ];
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
            throw new InvalidArgumentException('分页参数无效。');
        }
        return (int) $value;
    }
    private static function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw new InvalidArgumentException('统计参数无效。');
        }
    }
    private static function date($value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('请选择有效日期。');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('请选择有效日期。');
        }
        return $date;
    }
    private static function range(array $input, bool $custom = false): array
    {
        $period = $input['period'] ?? 'week';
        $today = Reader_Experience::day();
        $date = self::date($today);
        if (!in_array($period, $custom ? ['week', 'month', 'custom'] : ['week', 'month'], true)) {
            throw new InvalidArgumentException('请选择本周或本月。');
        }
        if ($period === 'custom') {
            $from = self::date($input['from'] ?? null);
            $to = self::date($input['to'] ?? null);
            if ($from > $to || $to > $date || $from->diff($to)->days > 365) {
                throw new InvalidArgumentException('日期范围须为截至今日的连续 1–366 天。');
            }
        } else {
            if (array_key_exists('from', $input) || array_key_exists('to', $input)) {
                throw new InvalidArgumentException('仅自选时段可指定日期。');
            }
            $from = $date->modify(
                $period === 'week' ? 'monday this week' : 'first day of this month',
            );
            $to = $date;
        }
        return [
            'period' => $period,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => WP_XP_Core_Settings::rules()['timezone'],
        ];
    }
    private static function available(bool $admin = false)
    {
        if ($admin && !current_user_can('manage_options')) {
            return self::error('forbidden', '没有查看统计的权限。', 403);
        }
        return Reader_Experience::ready()
            ? true
            : self::error('maintenance', '经验系统维护中。', 503);
    }
    private static function rows(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Insights query failed');
        }
        return $rows;
    }
    private static function quoted(array $values): string
    {
        global $wpdb;
        return implode(',', array_map(static fn($value) => $wpdb->prepare('%s', $value), $values));
    }
    private static function membership(): string
    {
        if (!is_multisite()) {
            return '';
        }
        global $wpdb;
        // Network users may remain in the global users table after leaving this blog.
        return " AND u.spam=0 AND u.deleted=0 AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} member" .
            ' WHERE member.user_id=u.ID AND ' .
            $wpdb->prepare('member.meta_key=%s', $wpdb->prefix . 'capabilities') .
            " AND member.meta_value NOT IN ('','a:0:{}'))";
    }
    private static function scores(array $range, int $uid = 0): string
    {
        global $wpdb;
        return 'SELECT e.user_id,SUM(e.xp) AS xp FROM ' .
            Reader_Experience::table() .
            " e INNER JOIN {$wpdb->users} u ON u.ID=e.user_id WHERE " .
            $wpdb->prepare('e.local_day BETWEEN %s AND %s', $range['from'], $range['to']) .
            ' AND e.kind IN (' .
            self::quoted(self::ACTIVITY_KINDS) .
            ') AND e.xp<>0' .
            self::membership() .
            ($uid ? $wpdb->prepare(' AND e.user_id=%d', $uid) : '') .
            ' GROUP BY e.user_id HAVING SUM(e.xp)>0';
    }
    private static function public_row(array $row, int $rank): array
    {
        return [
            'display_name' => $row['display_name'],
            'author_url' => get_author_posts_url((int) $row['user_id'], $row['user_nicename']),
            'xp' => (int) $row['xp'],
            'rank' => $rank,
        ];
    }
    /** Offset pagination follows live totals; activity between requests may move rows. */
    public static function leaderboard(array $input = [])
    {
        $allowed = self::available();
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            self::fields($input, ['period', 'limit', 'offset']);
            $range = self::range($input);
            $limit = self::integer($input['limit'] ?? 10, 1, 50);
            $offset = self::integer($input['offset'] ?? 0, 0, 1000000);
            global $wpdb;
            $scores = self::scores($range);
            // One statement gives rows, competition ranks and totals the same InnoDB snapshot.
            $rows = self::rows(
                'SELECT page.*,summary.total,(SELECT COUNT(*)+1 FROM (' .
                    $scores .
                    ') higher WHERE higher.xp>page.xp) AS position_rank FROM (' .
                    'SELECT s.*,u.display_name,u.user_nicename FROM (' .
                    $scores .
                    ") s INNER JOIN {$wpdb->users} u ON u.ID=s.user_id ORDER BY s.xp DESC,s.user_id ASC" .
                    $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset) .
                    ') page RIGHT JOIN (SELECT COUNT(*) AS total FROM (' .
                    $scores .
                    ') counted) summary ON 1=1 ORDER BY page.xp DESC,page.user_id ASC',
            );
            $total = (int) $rows[0]['total'];
            $out = [];
            foreach ($rows as $row) {
                if ($row['user_id'] !== null) {
                    $out[] = self::public_row($row, (int) $row['position_rank']);
                }
            }
            return $range + [
                'rows' => $out,
                'total' => $total,
                'has_more' => $offset + count($out) < $total,
                'offset' => $offset,
                'limit' => $limit,
            ];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取经验榜。', 503);
        }
    }
    /** Internal explicit-user lookup. HTTP handlers must use mine() for personal position. */
    public static function rank(int $uid, string $period = 'week')
    {
        $allowed = self::available();
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            $range = self::range(['period' => $period]);
            if ($uid < 1) {
                return null;
            }
            global $wpdb;
            $rows = self::rows(
                'SELECT s.*,u.display_name,u.user_nicename,(SELECT COUNT(*)+1 FROM (' .
                    self::scores($range) .
                    ') higher WHERE higher.xp>s.xp) AS position_rank FROM (' .
                    self::scores($range, $uid) .
                    ") s INNER JOIN {$wpdb->users} u ON u.ID=s.user_id",
            );
            if (!$rows) {
                return null;
            }
            return self::public_row($rows[0], (int) $rows[0]['position_rank']);
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取个人位置。', 503);
        }
    }
    public static function mine(array $input, $nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', '请先登录。', 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        try {
            self::fields($input, ['period']);
            $range = self::range($input);
            $position = self::rank(get_current_user_id(), $range['period']);
            return is_wp_error($position) ? $position : $range + ['position' => $position];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取个人位置。', 503);
        }
    }
    public static function sources(array $input = [])
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            self::fields($input, ['period', 'from', 'to']);
            $range = self::range($input, true);
            global $wpdb;
            $kinds = array_merge(self::ACTIVITY_KINDS, ['manual_adjust', 'manual_reversal']);
            $rows = self::rows(
                'SELECT kind,SUM(CASE WHEN xp>0 THEN xp ELSE 0 END) AS positive_xp,' .
                    'SUM(CASE WHEN xp<0 THEN xp ELSE 0 END) AS negative_xp,SUM(xp) AS net_xp,' .
                    'COUNT(*) AS event_count,COUNT(DISTINCT user_id) AS user_count FROM ' .
                    Reader_Experience::table() .
                    ' WHERE kind IN (' .
                    self::quoted($kinds) .
                    ') AND ' .
                    $wpdb->prepare('local_day BETWEEN %s AND %s', $range['from'], $range['to']) .
                    ' GROUP BY kind WITH ROLLUP',
            );
            $totals = array_pop($rows) ?? [
                'kind' => null,
                'positive_xp' => 0,
                'negative_xp' => 0,
                'net_xp' => 0,
                'event_count' => 0,
                'user_count' => 0,
            ];
            unset($totals['kind']);
            foreach ($rows as &$row) {
                foreach (
                    ['positive_xp', 'negative_xp', 'net_xp', 'event_count', 'user_count']
                    as $key
                ) {
                    $row[$key] = (int) $row[$key];
                }
            }
            unset($row);
            foreach ($totals as &$value) {
                $value = (int) $value;
            }
            unset($value);
            return $range + ['rows' => $rows, 'totals' => $totals];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取来源分析。', 503);
        }
    }
    /** Denominator is participating users plus earned users, never page impressions. */
    public static function task_rates()
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            global $wpdb;
            $today = Reader_Experience::day();
            $tasks = [];
            $selects = [];
            $definitions = WP_XP_Core_Achievements::definitions();
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Task definitions unavailable');
            }
            foreach ($definitions as $task) {
                if (empty($task['enabled'])) {
                    continue;
                }
                $period = WP_XP_Core_Achievements::period($task);
                $index = count($tasks);
                $tasks[] = [
                    'id' => $task['id'],
                    'title' => $task['title'],
                    'version' => (int) $task['version'],
                    'type' => $task['type'],
                    'period' => $task['period'],
                    'period_key' => $period['key'],
                    'from' => $period['from'],
                    'to' => $today,
                    'start_event_id' => (int) $task['start_event_id'],
                ];
                $selects[] = $wpdb->prepare(
                    'SELECT %d AS task_index,%s AS task_id,%d AS version,%d AS number,' .
                        '%s AS task_type,%s AS kinds,%s AS date_from,%s AS date_to,' .
                        '%d AS boundary,%s AS period_key,%s AS completion_prefix',
                    $index,
                    $task['id'],
                    $task['version'],
                    $task['number'],
                    $task['type'],
                    implode(',', array_unique(array_column($task['steps'], 'kind'))),
                    $period['from'],
                    $today,
                    $task['start_event_id'],
                    $period['key'],
                    'task:' . $task['id'] . ':' . $task['version'] . ':' . $period['key'] . ':',
                );
            }
            if (!$tasks) {
                return ['timezone' => WP_XP_Core_Settings::rules()['timezone'], 'rows' => []];
            }
            // JSON_VALID protects legacy text. Completion uses the exact canonical event key.
            $detail = "IF(JSON_VALID(e.detail),e.detail,'{}')";
            $earned =
                "e.kind='task_complete' AND e.event_key=CONCAT(t.completion_prefix,e.user_id)";
            $activity =
                "t.task_type='automatic' AND FIND_IN_SET(e.kind,t.kinds)>0 AND " .
                "(e.kind<>'comment' OR (c.comment_approved='1' AND p.post_status='publish' AND c.user_id=e.user_id))";
            $submission =
                "t.task_type='review' AND e.kind='achievement_submit' AND e.object_id=t.number" .
                " AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.task_id'))=t.task_id" .
                " AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.version'))=CAST(t.version AS CHAR)" .
                " AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.period'))=t.period_key";
            $rows = self::rows(
                'SELECT t.task_index,COUNT(DISTINCT CASE WHEN (' .
                    $earned .
                    ') THEN e.user_id END) AS completed,' .
                    'COUNT(DISTINCT e.user_id) AS participants FROM (' .
                    implode(' UNION ALL ', $selects) .
                    ') t INNER JOIN ' .
                    Reader_Experience::table() .
                    ' e ON e.id>t.boundary AND e.local_day BETWEEN t.date_from AND t.date_to' .
                    " INNER JOIN {$wpdb->users} u ON u.ID=e.user_id" .
                    " LEFT JOIN {$wpdb->comments} c ON e.kind='comment' AND c.comment_ID=e.object_id" .
                    " LEFT JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID" .
                    ' WHERE ((' .
                    $earned .
                    ') OR (' .
                    $activity .
                    ') OR (' .
                    $submission .
                    '))' .
                    self::membership() .
                    ' GROUP BY t.task_index',
            );
            $counts = array_column($rows, null, 'task_index');
            foreach ($tasks as $index => &$task) {
                $task['participants'] = (int) ($counts[$index]['participants'] ?? 0);
                $task['completed'] = (int) ($counts[$index]['completed'] ?? 0);
                $task['rate'] = $task['participants']
                    ? $task['completed'] / $task['participants']
                    : null;
            }
            unset($task);
            return ['timezone' => WP_XP_Core_Settings::rules()['timezone'], 'rows' => $tasks];
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取任务完成率。', 503);
        }
    }
}
