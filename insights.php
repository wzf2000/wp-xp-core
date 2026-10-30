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
        'task_complete',
        'provider_reward',
        'provider_revoke',
        'task_revoke',
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
            throw new InvalidArgumentException(wp_xp_core_t('分页参数无效。'));
        }
        return (int) $value;
    }
    private static function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw new InvalidArgumentException(wp_xp_core_t('统计参数无效。'));
        }
    }
    private static function date($value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择有效日期。'));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择有效日期。'));
        }
        return $date;
    }
    private static function range(array $input, bool $custom = false): array
    {
        $period = $input['period'] ?? 'week';
        $today = Reader_Experience::day();
        $date = self::date($today);
        if (!in_array($period, $custom ? ['week', 'month', 'custom'] : ['week', 'month'], true)) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择本周或本月。'));
        }
        if ($period === 'custom') {
            $from = self::date($input['from'] ?? null);
            $to = self::date($input['to'] ?? null);
            if ($from > $to || $to > $date || $from->diff($to)->days > 365) {
                throw new InvalidArgumentException(
                    wp_xp_core_t('日期范围须为截至今日的连续 1–366 天。'),
                );
            }
        } else {
            if (array_key_exists('from', $input) || array_key_exists('to', $input)) {
                throw new InvalidArgumentException(wp_xp_core_t('仅自选时段可指定日期。'));
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
            return self::error('forbidden', wp_xp_core_t('没有查看统计的权限。'), 403);
        }
        return Reader_Experience::ready()
            ? true
            : self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
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
            ' AND NOT EXISTS (SELECT 1 FROM ' .
            $wpdb->usermeta .
            ' privacy WHERE privacy.user_id=e.user_id AND ' .
            $wpdb->prepare(
                "privacy.meta_key=%s AND privacy.meta_value='0'",
                WP_XP_Core_Fairness::meta_key(),
            ) .
            ')' .
            (WP_XP_Core_Fairness::excluded()
                ? ' AND e.user_id NOT IN (' . implode(',', WP_XP_Core_Fairness::excluded()) . ')'
                : '') .
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
            return self::error('storage', wp_xp_core_t('暂时无法读取经验榜。'), 503);
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
            if ($uid < 1 || WP_XP_Core_Fairness::status($uid) !== 'active') {
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
            return self::error('storage', wp_xp_core_t('暂时无法读取个人位置。'), 503);
        }
    }
    public static function mine(array $input, $nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', wp_xp_core_t('请先登录。'), 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        try {
            self::fields($input, ['period']);
            $range = self::range($input);
            $position = self::rank(get_current_user_id(), $range['period']);
            return is_wp_error($position)
                ? $position
                : $range + [
                        'position' => $position,
                        'participation' => WP_XP_Core_Fairness::status(get_current_user_id()),
                    ];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法读取个人位置。'), 503);
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
            $kinds = array_merge(self::ACTIVITY_KINDS, [
                'manual_adjust',
                'manual_reversal',
                'task_grant',
                'task_grant_revoke',
            ]);
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
            return self::error('storage', wp_xp_core_t('暂时无法读取来源分析。'), 503);
        }
    }
    /** Denominator is participating users plus earned users, never page impressions. */
    /** Equal consecutive windows: descriptive activity trends, never a causal attribution. */
    public static function trends(array $input = [])
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            self::fields($input, ['period', 'from', 'to']);
            $range = self::range($input, true);
            $from = new DateTimeImmutable($range['from'], new DateTimeZone('UTC'));
            $to = new DateTimeImmutable($range['to'], new DateTimeZone('UTC'));
            $days = (int) $from->diff($to)->days + 1;
            $previous = [
                'from' => $from->modify('-' . $days . ' days')->format('Y-m-d'),
                'to' => $from->modify('-1 day')->format('Y-m-d'),
            ];
            global $wpdb;
            $where =
                $wpdb->prepare('local_day BETWEEN %s AND %s', $previous['from'], $range['to']) .
                ' AND kind IN (' .
                self::quoted(self::ACTIVITY_KINDS) .
                ')';
            $split = $wpdb->prepare('CASE WHEN local_day>=%s THEN 1 ELSE 0 END', $range['from']);
            $rows = self::rows(
                'SELECT ' .
                    $split .
                    ' AS current_window,COALESCE(SUM(xp),0) AS net_xp,COUNT(*) AS events,COUNT(DISTINCT user_id) AS participants FROM ' .
                    Reader_Experience::table() .
                    ' WHERE ' .
                    $where .
                    ' GROUP BY current_window',
            );
            $windows = [
                0 => ['net_xp' => 0, 'events' => 0, 'participants' => 0],
                1 => ['net_xp' => 0, 'events' => 0, 'participants' => 0],
            ];
            foreach ($rows as $row) {
                $windows[(int) $row['current_window']] = array_map(
                    'intval',
                    array_intersect_key($row, $windows[0]),
                );
            }
            $daily = self::rows(
                'SELECT local_day,COALESCE(SUM(xp),0) AS net_xp,COUNT(*) AS events FROM ' .
                    Reader_Experience::table() .
                    ' WHERE ' .
                    $where .
                    ' GROUP BY local_day ORDER BY local_day',
            );
            foreach ($daily as &$row) {
                $row['net_xp'] = (int) $row['net_xp'];
                $row['events'] = (int) $row['events'];
            }
            unset($row);
            return [
                'current' => $range + $windows[1],
                'previous' => $previous + $windows[0],
                'days' => $days,
                'delta_net_xp' => $windows[1]['net_xp'] - $windows[0]['net_xp'],
                'daily' => $daily,
            ];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法读取期间趋势。'), 503);
        }
    }
    public static function anomalies(array $input = [])
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            self::fields($input, ['period', 'from', 'to', 'limit']);
            $limit = self::integer($input['limit'] ?? 20, 1, 50);
            unset($input['limit']);
            $range = self::range($input, true);
            global $wpdb;
            $d = "IF(JSON_VALID(detail),detail,'{}')";
            $rows = self::rows(
                'SELECT user_id,local_day,COUNT(*) AS events,SUM(CASE WHEN xp>0 THEN xp ELSE 0 END) AS positive_xp,SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(' .
                    $d .
                    ",'$.activity_cap.truncated'))='true' THEN 1 ELSE 0 END) AS capped_events FROM " .
                    Reader_Experience::table() .
                    ' WHERE kind IN (' .
                    self::quoted(WP_XP_Core_Fairness::FIRST_REWARD_KINDS) .
                    ') AND ' .
                    $wpdb->prepare('local_day BETWEEN %s AND %s', $range['from'], $range['to']) .
                    ' GROUP BY user_id,local_day HAVING events>=20 OR capped_events>0 ORDER BY events DESC,user_id ASC,local_day DESC LIMIT ' .
                    $limit,
            );
            $out = [];
            foreach ($rows as $row) {
                $uid = (int) $row['user_id'];
                if (
                    WP_XP_Core_Management::permission($uid) !== true ||
                    !($user = get_userdata($uid))
                ) {
                    continue;
                }
                $out[] = [
                    'user_id' => $uid,
                    'display_name' => $user->display_name,
                    'day' => $row['local_day'],
                    'events' => (int) $row['events'],
                    'positive_xp' => (int) $row['positive_xp'],
                    'capped_events' => (int) $row['capped_events'],
                    'reason' =>
                        (int) $row['events'] >= 20
                            ? wp_xp_core_t('同日首次活动至少二十次')
                            : wp_xp_core_t('出现活动上限截断'),
                ];
            }
            return $range + ['items' => $out];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法读取活动线索。'), 503);
        }
    }
    /** Old versions expose observed completions only; no invented participation denominator. */
    public static function task_history(array $input = [])
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            self::fields($input, ['period', 'from', 'to']);
            $range = self::range($input, true);
            global $wpdb;
            $d = "IF(JSON_VALID(e.detail),e.detail,'{}')";
            $grant = "e.kind IN ('task_complete','task_grant')";
            $timed =
                $grant .
                " AND JSON_TYPE(JSON_EXTRACT($d,'$.version_created_at'))='INTEGER' AND CAST(JSON_UNQUOTE(JSON_EXTRACT($d,'$.version_created_at')) AS UNSIGNED) BETWEEN 0 AND e.created_at";
            $rows = self::rows(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT($d,'$.task_id')) AS task_id,CAST(JSON_UNQUOTE(JSON_EXTRACT($d,'$.version')) AS UNSIGNED) AS version,MIN(JSON_UNQUOTE(JSON_EXTRACT($d,'$.title'))) AS title,SUM($grant) AS completions,SUM(e.kind IN ('task_revoke','task_grant_revoke')) AS revocations,SUM(($grant) AND (" .
                    WP_XP_Core_Achievements::valid_receipt() .
                    ")) AS active_completions,SUM($timed) AS timed_completions,AVG(CASE WHEN $timed THEN e.created_at-CAST(JSON_UNQUOTE(JSON_EXTRACT($d,'$.version_created_at')) AS UNSIGNED) END) AS mean_seconds FROM " .
                    Reader_Experience::table() .
                    " e WHERE e.kind IN ('task_complete','task_grant','task_revoke','task_grant_revoke') AND JSON_TYPE(JSON_EXTRACT($d,'$.task_id'))='STRING' AND JSON_TYPE(JSON_EXTRACT($d,'$.version'))='INTEGER' AND " .
                    $wpdb->prepare('e.local_day BETWEEN %s AND %s', $range['from'], $range['to']) .
                    ' GROUP BY task_id,version ORDER BY MAX(e.id) DESC LIMIT 101',
            );
            $more = count($rows) > 100;
            $rows = array_slice($rows, 0, 100);
            foreach ($rows as &$row) {
                foreach (
                    [
                        'version',
                        'completions',
                        'revocations',
                        'active_completions',
                        'timed_completions',
                    ]
                    as $key
                ) {
                    $row[$key] = (int) $row[$key];
                }
                $row['mean_seconds'] =
                    $row['mean_seconds'] === null ? null : (float) $row['mean_seconds'];
                $row['participants'] = null;
            }
            unset($row);
            return $range + [
                'rows' => $rows,
                'more' => $more,
                'duration_origin' => 'version_definition_created_at',
            ];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法读取任务版本统计。'), 503);
        }
    }
    public static function task_rates()
    {
        $allowed = self::available(true);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            global $wpdb;
            $tasks = [];
            $groups = [];
            $metadata = [];
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
                    'to' => $period['to'],
                    'start_event_id' => (int) $task['start_event_id'],
                ];
                // Participation needs any eligible activity, regardless of step thresholds
                // or counting modes. Share only identical complete eligibility predicates.
                $conditions = [];
                $kinds = [];
                foreach ($task['steps'] as $step) {
                    $conditions[] = WP_XP_Core_Achievements::condition($task, $step);
                    $kinds[] = $step['kind'];
                }
                $conditions = array_values(array_unique($conditions));
                sort($conditions, SORT_STRING);
                $kinds = array_values(array_unique($kinds));
                sort($kinds, SORT_STRING);
                if ($conditions) {
                    $activity =
                        'e.kind IN (' .
                        self::quoted($kinds) .
                        ') AND (' .
                        implode(' OR ', $conditions) .
                        ')';
                    $groups[$activity][] = $index;
                }
                $metadata[] = $wpdb->prepare(
                    'SELECT %d AS task_index,%s AS task_id,%s AS version,%s AS period_key,%d AS task_number,%d AS review,%d AS boundary,%s AS from_day,%s AS to_day,%s AS receipt_prefix',
                    $index,
                    $task['id'],
                    (string) $task['version'],
                    $period['key'],
                    $task['number'],
                    $task['type'] === 'review' ? 1 : 0,
                    $task['start_event_id'],
                    $period['from'],
                    $period['to'],
                    'task:' . $task['id'] . ':' . $task['version'] . ':' . $period['key'] . ':',
                );
            }
            if (!$tasks) {
                return ['timezone' => WP_XP_Core_Settings::rules()['timezone'], 'rows' => []];
            }
            $selects = [];
            foreach ($groups as $activity => $indices) {
                $mapping = array_map(
                    static fn($index) => 'SELECT ' . $index . ' AS task_index',
                    $indices,
                );
                // DISTINCT keeps the eligible-user set materialized once per group.
                // Expand only these deduplicated users to their task indices in SQL.
                $selects[] =
                    'SELECT mapping.task_index,activity.user_id,0 AS completed FROM (' .
                    'SELECT DISTINCT e.user_id FROM ' .
                    Reader_Experience::table() .
                    " e INNER JOIN {$wpdb->users} u ON u.ID=e.user_id" .
                    WP_XP_Core_Achievements::joins() .
                    ' WHERE ' .
                    $activity .
                    self::membership() .
                    ') activity CROSS JOIN (' .
                    implode(' UNION ALL ', $mapping) .
                    ') mapping';
            }
            $detail = "IF(JSON_VALID(e.detail),e.detail,'{}')";
            $earned =
                "e.kind IN ('task_complete','task_grant') AND e.event_key=CONCAT(t.receipt_prefix,e.user_id) AND " .
                WP_XP_Core_Achievements::valid_receipt();
            $submission = "t.review=1 AND e.kind='achievement_submit' AND e.object_id=t.task_number AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.task_id'))=t.task_id AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.version'))=t.version AND JSON_UNQUOTE(JSON_EXTRACT($detail,'$.period'))=t.period_key";
            // The ledger drives this join once. Only receipt/submission kinds evaluate
            // task identities; ordinary activities never fan out over all definitions.
            $selects[] =
                'SELECT t.task_index,e.user_id,MAX(CASE WHEN (' .
                $earned .
                ') THEN 1 ELSE 0 END) AS completed FROM ' .
                Reader_Experience::table() .
                ' e STRAIGHT_JOIN (' .
                implode(' UNION ALL ', $metadata) .
                ') t ON e.id>t.boundary AND e.local_day BETWEEN t.from_day AND t.to_day AND ((' .
                $earned .
                ') OR (' .
                $submission .
                '))' .
                " INNER JOIN {$wpdb->users} u ON u.ID=e.user_id" .
                " WHERE e.kind IN ('task_complete','task_grant','achievement_submit')" .
                self::membership() .
                ' GROUP BY t.task_index,e.user_id';
            $rows = self::rows(
                'SELECT task_index,COUNT(DISTINCT user_id) AS participants,' .
                    'COUNT(DISTINCT CASE WHEN completed=1 THEN user_id END) AS completed FROM (' .
                    implode(' UNION ALL ', $selects) .
                    ') participants GROUP BY task_index',
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
            return self::error('storage', wp_xp_core_t('暂时无法读取任务完成率。'), 503);
        }
    }
}
