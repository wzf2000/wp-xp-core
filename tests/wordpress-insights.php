<?php
/** Read-only insights against independent synthetic users and events on the guarded test database. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic WordPress database required.');
}
$checks = [];
$verify = static function (string $label, $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$error = static fn($value, string $code) => is_wp_error($value) &&
    $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
$verify(
    'Insights service and isolated administrator exist',
    $admin && class_exists('WP_XP_Core_Insights'),
);
global $wpdb;
$table = Reader_Experience::table();
$users = [];
foreach (['alpha', 'beta', 'gamma', 'delta', 'deleted', 'empty'] as $name) {
    $login = 'fixture-insights-' . $name;
    $verify('Insights users never reuse existing subjects', !get_user_by('login', $login));
    $uid = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32),
        'user_email' => $login . '@example.invalid',
        'display_name' => 'Insights ' . $name,
        'role' => 'subscriber',
    ]);
    $verify('Independent insights user created', !is_wp_error($uid));
    $users[$name] = (int) $uid;
}
extract($users, EXTR_SKIP);
$sequence = 0;
// These fixture-only inserts do not invoke reward hooks or change balance projections.
$event = static function (
    int $uid,
    string $kind,
    int $xp,
    string $day = '2032-03-10',
    string $key = '',
    int $object = 0,
    $detail = [],
) use ($wpdb, $table, &$sequence): int {
    $sequence++;
    $result = $wpdb->insert($table, [
        'event_key' => $key ?: 'insights-fixture:' . $sequence,
        'user_id' => $uid,
        'kind' => $kind,
        'object_id' => $object,
        'local_day' => $day,
        'xp' => $xp,
        'created_at' => 1,
        'detail' => is_array($detail) ? wp_json_encode($detail) : $detail,
    ]);
    if (!$result) {
        throw new RuntimeException('Insights synthetic event insert failed');
    }
    return (int) $wpdb->insert_id;
};
$clock = static fn($date, $format) => $format === 'Y-m-d' ? '2032-03-10' : $date;
$original_tasks = get_option(WP_XP_Core_Achievements::OPTION, null);
add_filter('wp_date', $clock, 10, 2);
try {
    wp_set_current_user($admin->ID);
    // Old balance is deliberately much larger than activity in either current period.
    Reader_Experience::transaction(static function () use ($alpha): void {
        Reader_Experience::record(
            'insights-old-balance',
            $alpha,
            'manual_adjust',
            0,
            5000,
            '2031-01-01',
        );
    });
    $event($alpha, 'checkin', 2, '2032-03-01');
    $event($alpha, 'checkin', 10, '2032-03-08');
    $event($alpha, 'comment_adjust', -2);
    $event($alpha, 'streak_reward', 2);
    $event($beta, 'article', 10);
    $event($beta, 'visit', 80, '2032-03-07');
    $event($beta, 'checkin', 1000, '2032-03-11');
    $event($gamma, 'checkin', 4);
    $event($gamma, 'checkin', 200, '2032-02-29');
    $event($gamma, 'manual_adjust', 900);
    $event($gamma, 'manual_reversal', -1);
    $event($gamma, 'management_retry', 999);
    $event($gamma, 'migration_init', 5000);
    $event($gamma, 'task_complete', 0);
    $event($gamma, 'view', 0);
    $event($delta, 'comment', 3);
    $event($delta, 'comment_adjust', -3);
    $event($deleted, 'article', 50);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($deleted);
    $week = WP_XP_Core_Insights::leaderboard(['period' => 'week', 'limit' => 2]);
    $verify(
        'Week starts Monday in the experience timezone and stops today',
        $week['from'] === '2032-03-08' &&
            $week['to'] === '2032-03-10' &&
            $week['timezone'] === WP_XP_Core_Settings::rules()['timezone'],
    );
    $verify(
        'Current balances and manual historical changes never become activity growth',
        $week['total'] === 3 &&
            $week['rows'][0]['xp'] === 10 &&
            (int) get_user_meta($alpha, Reader_Experience::balance_meta(), true) === 5000,
    );
    $verify(
        'Equal activity XP shares competition rank and stable user order',
        $week['rows'][0]['display_name'] === 'Insights alpha' &&
            $week['rows'][1]['display_name'] === 'Insights beta' &&
            array_column($week['rows'], 'rank') === [1, 1] &&
            $week['has_more'],
    );
    $page = WP_XP_Core_Insights::leaderboard(['offset' => 1, 'limit' => 2]);
    $verify(
        'Pagination preserves ties crossing a page boundary and subsequent rank gaps',
        array_column($page['rows'], 'rank') === [1, 3] && !$page['has_more'],
    );
    $verify(
        'Deleted and zero-net users have no position',
        WP_XP_Core_Insights::rank($deleted) === null && WP_XP_Core_Insights::rank($delta) === null,
    );
    $month = WP_XP_Core_Insights::leaderboard(['period' => 'month']);
    $verify(
        'Month includes its first day and excludes preceding month and future events',
        $month['from'] === '2032-03-01' && array_column($month['rows'], 'xp') === [90, 12, 4],
    );
    $verify(
        'Only public display fields leave public standings',
        array_keys($week['rows'][0]) === ['display_name', 'author_url', 'xp', 'rank'],
    );
    $verify(
        'Unknown fields and invalid pagination are rejected',
        $error(WP_XP_Core_Insights::leaderboard(['user_id' => $beta]), 'input') &&
            $error(WP_XP_Core_Insights::leaderboard(['limit' => 51]), 'input') &&
            $error(WP_XP_Core_Insights::leaderboard(['offset' => -1]), 'input') &&
            $error(WP_XP_Core_Insights::leaderboard(['period' => 'year']), 'input'),
    );
    wp_set_current_user(0);
    $verify(
        'Anonymous personal position requires authentication',
        $error(WP_XP_Core_Insights::mine([], ''), 'login_required'),
    );
    wp_set_current_user($gamma);
    $verify(
        'Personal position requires a valid REST nonce',
        $error(WP_XP_Core_Insights::mine([], 'invalid'), 'nonce'),
    );
    $verify(
        'Personal endpoint rejects attempts to specify another user',
        $error(
            WP_XP_Core_Insights::mine(['user_id' => $beta], wp_create_nonce('wp_rest')),
            'input',
        ),
    );
    $mine = WP_XP_Core_Insights::mine([], wp_create_nonce('wp_rest'));
    $verify(
        'Personal position always belongs to the authenticated user',
        $mine['position']['display_name'] === 'Insights gamma' && $mine['position']['rank'] === 3,
    );
    // BEGIN POSITION REST REGRESSION
    $rest_position = static function (
        string $body = '{}',
        string $nonce = '',
        string $content_type = 'application/json',
    ) {
        $request = new WP_REST_Request(
            'POST',
            '/' . reader_experience_config('rest_namespace') . '/position',
        );
        $request->set_header('Content-Type', $content_type);
        $request->set_header('X-WP-Nonce', $nonce);
        $request->set_body($body);
        return rest_do_request($request);
    };
    wp_set_current_user(0);
    $verify('Anonymous position REST calls return 401', $rest_position()->get_status() === 401);
    wp_set_current_user($gamma);
    $verify(
        'Position REST rejects invalid nonces',
        $rest_position('{}', 'invalid')->get_status() === 403,
    );
    $rest_nonce = wp_create_nonce('wp_rest');
    $verify(
        'Position REST rejects attempts to choose a different user',
        $rest_position(wp_json_encode(['user_id' => $beta]), $rest_nonce)->get_status() === 400,
    );
    foreach (['week', 'month'] as $rest_period) {
        $response = $rest_position(wp_json_encode(['period' => $rest_period]), $rest_nonce);
        $verify(
            'Position REST returns only the current user for ' . $rest_period,
            $response->get_status() === 200 &&
                $response->get_data()['position'] ===
                    WP_XP_Core_Insights::rank($gamma, $rest_period),
        );
        $verify(
            'Position REST exposes only public display fields for ' . $rest_period,
            array_keys($response->get_data()['position']) === [
                'display_name',
                'author_url',
                'xp',
                'rank',
            ],
        );
    }
    $verify(
        'Position REST rejects non-JSON content types',
        $rest_position('{}', $rest_nonce, 'text/plain')->get_status() === 400,
    );
    $verify(
        'Position REST rejects scalar JSON bodies',
        $rest_position('"week"', $rest_nonce)->get_status() === 400,
    );
    $verify(
        'Position REST rejects oversized bodies',
        $rest_position(
            wp_json_encode(['period' => str_repeat('x', 1001)]),
            $rest_nonce,
        )->get_status() === 400,
    );
    // END POSITION REST REGRESSION
    $verify(
        'Both administrative services enforce manage_options themselves',
        $error(WP_XP_Core_Insights::sources(), 'forbidden') &&
            $error(WP_XP_Core_Insights::task_rates(), 'forbidden'),
    );
    wp_set_current_user($admin->ID);
    $source = WP_XP_Core_Insights::sources();
    $by_kind = array_column($source['rows'], null, 'kind');
    $verify(
        'Source analysis includes manual adjustments separately and signed compensation',
        $by_kind['manual_adjust']['net_xp'] === 900 &&
            $by_kind['manual_reversal']['negative_xp'] === -1 &&
            $by_kind['comment_adjust']['negative_xp'] === -5 &&
            $by_kind['comment_adjust']['event_count'] === 2,
    );
    $verify(
        'Source analysis excludes zero markers management receipts and historical calibration',
        !array_intersect(['management_retry', 'migration_init', 'view'], array_keys($by_kind)),
    );
    $verify(
        'Total unique users are deduplicated across kinds',
        $source['totals']['user_count'] === 5,
    );
    $event($empty, 'checkin', 0);
    $event($empty, 'visit', 0);
    $zero_sources = WP_XP_Core_Insights::sources();
    $zero_kinds = array_column($zero_sources['rows'], null, 'kind');
    $verify(
        'Zero-XP activities still count as source events and participating users',
        $zero_kinds['checkin']['event_count'] === $by_kind['checkin']['event_count'] + 1 &&
            $zero_kinds['checkin']['user_count'] === $by_kind['checkin']['user_count'] + 1 &&
            $zero_kinds['checkin']['net_xp'] === $by_kind['checkin']['net_xp'] &&
            $zero_kinds['visit']['event_count'] === 1 &&
            $zero_kinds['visit']['user_count'] === 1 &&
            $zero_kinds['visit']['positive_xp'] === 0 &&
            $zero_kinds['visit']['negative_xp'] === 0 &&
            $zero_kinds['visit']['net_xp'] === 0 &&
            $zero_sources['totals']['user_count'] === 6,
    );
    $ledger_reads = [];
    $observe_queries = static function ($sql) use ($table, &$ledger_reads) {
        if (str_starts_with(ltrim($sql), 'SELECT ') && str_contains($sql, $table)) {
            $ledger_reads[] = $sql;
        }
        return $sql;
    };
    add_filter('query', $observe_queries);
    try {
        $queries_before = $wpdb->num_queries;
        $snapshot_board = WP_XP_Core_Insights::leaderboard(['limit' => 2]);
        $verify(
            'Leaderboard rows ranks and total are obtained from one ledger SELECT',
            !is_wp_error($snapshot_board) &&
                count($ledger_reads) === 1 &&
                $wpdb->num_queries - $queries_before <= 3 &&
                $snapshot_board['rows'] === $week['rows'],
        );
        $ledger_reads = [];
        $snapshot_rank = WP_XP_Core_Insights::rank($gamma);
        $verify(
            'Personal XP and position are obtained from one ledger SELECT',
            !is_wp_error($snapshot_rank) &&
                count($ledger_reads) === 1 &&
                $snapshot_rank['xp'] === 4 &&
                $snapshot_rank['rank'] === 3,
        );
        $ledger_reads = [];
        $past_end = WP_XP_Core_Insights::leaderboard(['offset' => 100]);
        $verify(
            'A page beyond the end retains the total without a fabricated null user',
            $past_end['rows'] === [] &&
                $past_end['total'] === 3 &&
                !$past_end['has_more'] &&
                count($ledger_reads) === 1,
        );
        $event($gamma, 'streak_reward', 20);
        $ledger_reads = [];
        $changed_board = WP_XP_Core_Insights::leaderboard();
        $verify(
            'A subsequent request reflects a newly committed reward with consistent ranks',
            array_column($changed_board['rows'], 'xp') === [24, 10, 10] &&
                array_column($changed_board['rows'], 'rank') === [1, 2, 2] &&
                count($ledger_reads) === 1,
        );
        $ledger_reads = [];
        $changed_rank = WP_XP_Core_Insights::rank($alpha);
        $verify(
            'Subsequent personal position uses the updated snapshot',
            $changed_rank['xp'] === 10 && $changed_rank['rank'] === 2 && count($ledger_reads) === 1,
        );
        $event($gamma, 'comment_adjust', -20);
    } finally {
        remove_filter('query', $observe_queries);
    }
    $verify(
        'Source analysis never returns individual event detail',
        array_keys($by_kind['checkin']) === [
            'kind',
            'positive_xp',
            'negative_xp',
            'net_xp',
            'event_count',
            'user_count',
        ],
    );
    $verify(
        'Custom source ranges reject impossible future oversized and unknown input',
        $error(
            WP_XP_Core_Insights::sources([
                'period' => 'custom',
                'from' => '2032-02-30',
                'to' => '2032-03-10',
            ]),
            'input',
        ) &&
            $error(
                WP_XP_Core_Insights::sources([
                    'period' => 'custom',
                    'from' => '2031-03-09',
                    'to' => '2032-03-10',
                ]),
                'input',
            ) &&
            $error(
                WP_XP_Core_Insights::sources([
                    'period' => 'custom',
                    'from' => '2032-03-10',
                    'to' => '2032-03-11',
                ]),
                'input',
            ) &&
            $error(WP_XP_Core_Insights::sources(['detail' => true]), 'input'),
    );
    $custom = WP_XP_Core_Insights::sources([
        'period' => 'custom',
        'from' => '2031-03-11',
        'to' => '2032-03-10',
    ]);
    $verify('A custom range of exactly 366 inclusive days is accepted', !is_wp_error($custom));
    $post = wp_insert_post([
        'post_title' => 'Insights comment fixture',
        'post_status' => 'publish',
    ]);
    $approved = wp_insert_comment([
        'comment_post_ID' => $post,
        'user_id' => $alpha,
        'comment_approved' => '1',
        'comment_content' => 'Synthetic approved',
    ]);
    $pending = wp_insert_comment([
        'comment_post_ID' => $post,
        'user_id' => $beta,
        'comment_approved' => '0',
        'comment_content' => 'Synthetic pending',
    ]);
    $boundary = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . $table);
    $make_task = static fn(string $id, string $type, string $period, array $steps = []): array => [
        'id' => $id,
        'title' => 'Synthetic ' . $id,
        'description' => '',
        'icon' => '★',
        'type' => $type,
        'period' => $period,
        'enabled' => true,
        'steps' => $steps,
        'version' => 2,
        'number' => (int) substr($id, -1) + 1,
        'start_event_id' => $boundary,
        'created_at' => 1,
    ];
    $auto = $make_task('t_1111111111111111', 'automatic', 'day', [
        ['kind' => 'checkin', 'count' => 2],
    ]);
    $review = $make_task('t_2222222222222222', 'review', 'week');
    $comment = $make_task('t_3333333333333333', 'automatic', 'once', [
        ['kind' => 'comment', 'count' => 1],
    ]);
    $no_activity = $make_task('t_4444444444444444', 'automatic', 'week', [
        ['kind' => 'article', 'count' => 1],
    ]);
    $disabled = $make_task('t_5555555555555555', 'automatic', 'day', [
        ['kind' => 'checkin', 'count' => 1],
    ]);
    $disabled['enabled'] = false;
    $weekly = $make_task('t_6666666666666666', 'automatic', 'week', [
        ['kind' => 'visit', 'count' => 3],
    ]);
    $defs = [];
    foreach ([$auto, $review, $comment, $no_activity, $disabled, $weekly] as $task) {
        $defs[$task['id']] = $task;
    }
    update_option(WP_XP_Core_Achievements::OPTION, $defs, false);
    $event($alpha, 'checkin', 0);
    $event($beta, 'checkin', 0);
    $event(
        $beta,
        'task_complete',
        0,
        '2032-03-10',
        'task:' . $auto['id'] . ':2:2032-03-10:' . $beta,
    );
    $event(
        $gamma,
        'task_complete',
        0,
        '2032-03-10',
        'task:' . $auto['id'] . ':2:2032-03-10:' . $gamma,
    );
    $event($delta, 'checkin', 0, '2032-03-09');
    $event(
        $delta,
        'task_complete',
        0,
        '2032-03-10',
        'task:' . $auto['id'] . ':1:2032-03-10:' . $delta,
    );
    $event(
        $delta,
        'task_complete',
        0,
        '2032-03-10',
        'task:' . $auto['id'] . ':2:2032-03-09:' . $delta,
    );
    $event($empty, 'checkin', 0, '2032-03-11');
    $period = WP_XP_Core_Achievements::period($review)['key'];
    $submission = ['task_id' => $review['id'], 'version' => 2, 'period' => $period];
    $event($alpha, 'achievement_submit', 0, '2032-03-10', '', $review['number'], $submission);
    $event($alpha, 'achievement_submit', 0, '2032-03-10', '', $review['number'], $submission);
    $event($beta, 'achievement_submit', 0, '2032-03-10', '', $review['number'], $submission);
    $event(
        $beta,
        'task_complete',
        0,
        '2032-03-10',
        'task:' . $review['id'] . ':2:' . $period . ':' . $beta,
    );
    $event($gamma, 'achievement_submit', 0, '2032-03-10', '', $review['number'], 'not valid JSON');
    $event(
        $delta,
        'achievement_submit',
        0,
        '2032-03-10',
        '',
        $review['number'],
        array_replace($submission, ['version' => 1]),
    );
    $event(
        $delta,
        'achievement_submit',
        0,
        '2032-03-10',
        '',
        $review['number'],
        array_replace($submission, ['period' => '2032-09']),
    );
    $event($delta, 'visit', 0, '2032-03-07', '', $post);
    $event($alpha, 'visit', 0, '2032-03-08', '', $post);
    $event($alpha, 'comment', 0, '2032-03-10', '', $approved);
    $event($beta, 'comment', 0, '2032-03-10', '', $pending);
    $event($gamma, 'comment', 0, '2032-03-10', '', $approved);
    $snapshot = static fn(): string => hash(
        'sha256',
        wp_json_encode([
            $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A),
            $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
            $wpdb->get_results("SELECT * FROM {$wpdb->options} ORDER BY option_id", ARRAY_A),
        ]),
    );
    $before = $snapshot();
    $query_count = $wpdb->num_queries;
    $rates = WP_XP_Core_Insights::task_rates();
    $query_cost = $wpdb->num_queries - $query_count;
    $verify('Task aggregation handles invalid legacy detail without failing', !is_wp_error($rates));
    $by_task = array_column($rates['rows'], null, 'id');
    $verify(
        'Daily task participants include partial and earned-only users with competition version boundaries',
        $by_task[$auto['id']]['participants'] === 3 &&
            $by_task[$auto['id']]['completed'] === 2 &&
            abs($by_task[$auto['id']]['rate'] - 2 / 3) < 0.000001,
    );
    $verify(
        'Review participation counts unique current-version submissions in the current period',
        $by_task[$review['id']]['participants'] === 2 && $by_task[$review['id']]['completed'] === 1,
    );
    $verify(
        'Automatic comment participants retain approval post visibility and authorship checks',
        $by_task[$comment['id']]['participants'] === 1,
    );
    $verify(
        'Weekly tasks exclude activities before Monday',
        $by_task[$weekly['id']]['participants'] === 1,
    );
    $verify(
        'No participant denominator returns null and disabled tasks are omitted',
        $by_task[$no_activity['id']]['participants'] === 0 &&
            $by_task[$no_activity['id']]['rate'] === null &&
            !isset($by_task[$disabled['id']]),
    );
    $verify(
        'Task aggregation uses a fixed small query count rather than per-step queries',
        $query_cost <= 3,
    );
    WP_XP_Core_Insights::leaderboard();
    WP_XP_Core_Insights::rank($alpha);
    WP_XP_Core_Insights::sources();
    WP_XP_Core_Insights::mine([], wp_create_nonce('wp_rest'));
    $verify(
        'Insights reads never change events balance projections options or task rewards',
        $before === $snapshot(),
    );
    $rate_probes = [];
    foreach (['threshold', 'once', 'scope', 'date', 'boundary'] as $i => $name) {
        $task = $weekly;
        $task['id'] = 't_' . sprintf('%016x', 0xfee100 + $i);
        $task['number'] = 700 + $i;
        $task['steps'][0]['count'] = $name === 'threshold' ? 100 : 1;
        if ($name === 'once') {
            $task['period'] = 'once';
        }
        if ($name === 'scope') {
            $task['steps'][0]['post_ids'] = [999999999];
        }
        if ($name === 'date') {
            $task['period'] = 'once';
            $task['ends_on'] = '2032-03-07';
        }
        if ($name === 'boundary') {
            $task['start_event_id'] = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . $table);
        }
        $rate_probes[$name] = $task;
        $defs[$task['id']] = $task;
    }
    $scope_task = $rate_probes['scope'];
    $scope_period = WP_XP_Core_Achievements::period($scope_task)['key'];
    $event(
        $beta,
        'task_grant',
        0,
        '2032-03-10',
        'task:' . $scope_task['id'] . ':2:' . $scope_period . ':' . $beta,
    );
    $revoked_grant = $event(
        $gamma,
        'task_grant',
        0,
        '2032-03-10',
        'task:' . $scope_task['id'] . ':2:' . $scope_period . ':' . $gamma,
    );
    $event(
        $gamma,
        'task_grant_revoke',
        0,
        '2032-03-10',
        'task-revoke:' . $revoked_grant,
        $revoked_grant,
    );
    update_option(WP_XP_Core_Achievements::OPTION, $defs, false);
    WP_XP_Core_Achievements::clear_cache();
    $probe_before = $snapshot();
    // A cold WordPress option cache adds fixed metadata loads, not per-task queries.
    // Verify that cold path is read only, then measure the same warm metadata
    // baseline used by the existing fixed-query-count checks below and above.
    $probe_cold_rates = WP_XP_Core_Insights::task_rates();
    $verify(
        'Cold task-rate metadata loading remains read only',
        !is_wp_error($probe_cold_rates) && $probe_before === $snapshot(),
    );
    $probe_query_count = $wpdb->num_queries;
    $probe_rates = WP_XP_Core_Insights::task_rates();
    $probe_queries = $wpdb->num_queries - $probe_query_count;
    $probe_rows = array_column($probe_rates['rows'], null, 'id');
    $verify(
        'Task participant counts do not depend on completion thresholds',
        $probe_rows[$weekly['id']]['participants'] === 1 &&
            $probe_rows[$rate_probes['threshold']['id']]['participants'] === 1,
    );
    $verify(
        'Per-task branches retain independent period, end-date and version boundary filters',
        $probe_rows[$rate_probes['once']['id']]['participants'] === 2 &&
            $probe_rows[$rate_probes['date']['id']]['participants'] === 1 &&
            $probe_rows[$rate_probes['boundary']['id']]['participants'] === 0,
    );
    $verify(
        'Scope excludes unrelated activity while valid earned-only users participate and revoked-only users do not',
        $probe_rows[$scope_task['id']]['participants'] === 1 &&
            $probe_rows[$scope_task['id']]['completed'] === 1,
    );
    $verify(
        'Warm grouped task report retains constant query count and remains read only',
        $probe_queries <= 3 && $probe_before === $snapshot(),
    );
    $verify('Cold and warm task-rate results agree', $probe_cold_rates === $probe_rates);
    $maximum = [];
    for ($index = 0; $index < 50; $index++) {
        $task = $auto;
        $task['id'] = 't_' . sprintf('%016x', $index + 100);
        $task['number'] = $index + 100;
        $task['steps'] = [];
        for ($step = 0; $step < 8; $step++) {
            $task['steps'][] = [
                'kind' => 'checkin',
                'count' => $index + $step + 1,
                'mode' => $step % 2 ? 'days' : 'events',
            ];
        }
        if ($index % 2) {
            $task['steps'] = array_reverse($task['steps']);
        }
        $maximum[$task['id']] = $task;
    }
    update_option(WP_XP_Core_Achievements::OPTION, $maximum, false);
    $before_maximum = $snapshot();
    $query_count = $wpdb->num_queries;
    $ledger_reads = [];
    add_filter('query', $observe_queries);
    try {
        $maximum_rates = WP_XP_Core_Insights::task_rates();
    } finally {
        remove_filter('query', $observe_queries);
    }
    $verify(
        'Fifty eight-step tasks still use a fixed small number of queries',
        !is_wp_error($maximum_rates) &&
            count($maximum_rates['rows']) === 50 &&
            $wpdb->num_queries - $query_count <= 3,
    );
    $verify(
        'Equivalent task eligibility shares one distinct-user ledger scan despite thresholds modes and step order',
        count($ledger_reads) === 1 &&
            substr_count($ledger_reads[0], 'SELECT DISTINCT e.user_id FROM ') === 1 &&
            substr_count(
                $ledger_reads[0],
                "WHERE e.kind IN ('task_complete','task_grant','achievement_submit')",
            ) === 1 &&
            array_unique(array_column($maximum_rates['rows'], 'participants')) === [2] &&
            array_unique(array_column($maximum_rates['rows'], 'completed')) === [0],
    );
    $verify('Maximum-size task aggregation is also read only', $before_maximum === $snapshot());
    $fail_query = static fn($sql) => str_contains($sql, 'GROUP BY kind WITH ROLLUP')
        ? 'SELECT xp_insights_missing_column FROM ' . $table
        : $sql;
    $previous_suppression = $wpdb->suppress_errors(true);
    add_filter('query', $fail_query);
    try {
        $verify(
            'A storage failure returns an error instead of fabricated zero source totals',
            $error(WP_XP_Core_Insights::sources(), 'storage'),
        );
    } finally {
        remove_filter('query', $fail_query);
        $wpdb->suppress_errors($previous_suppression);
    }
    $fail_activity = static fn($sql) => str_contains($sql, 'SELECT DISTINCT e.user_id FROM ')
        ? 'SELECT xp_insights_missing_column FROM ' . $table
        : $sql;
    $previous_suppression = $wpdb->suppress_errors(true);
    add_filter('query', $fail_activity);
    try {
        $verify(
            'A failed grouped participation query returns storage instead of fabricated task counts',
            $error(WP_XP_Core_Insights::task_rates(), 'storage'),
        );
    } finally {
        remove_filter('query', $fail_activity);
        $wpdb->suppress_errors($previous_suppression);
    }
    $definition_failures = 0;
    $fail_definitions = static function ($sql) use ($wpdb, &$definition_failures) {
        if (
            str_starts_with(ltrim($sql), 'SELECT ') &&
            str_contains($sql, $wpdb->options) &&
            str_contains($sql, WP_XP_Core_Achievements::OPTION)
        ) {
            $definition_failures++;
            return 'SELECT xp_insights_missing_column FROM ' . $wpdb->options;
        }
        return $sql;
    };
    WP_XP_Core_Achievements::clear_cache();
    $previous_suppression = $wpdb->suppress_errors(true);
    add_filter('query', $fail_definitions);
    try {
        $verify(
            'A failed uncached task-definition SELECT returns storage instead of an empty report',
            $error(WP_XP_Core_Insights::task_rates(), 'storage') && $definition_failures === 1,
        );
    } finally {
        remove_filter('query', $fail_definitions);
        $wpdb->suppress_errors($previous_suppression);
        WP_XP_Core_Achievements::clear_cache();
    }
} finally {
    if ($original_tasks === null) {
        delete_option(WP_XP_Core_Achievements::OPTION);
    } else {
        update_option(WP_XP_Core_Achievements::OPTION, $original_tasks, false);
    }
    remove_filter('wp_date', $clock, 10);
    wp_set_current_user($admin->ID);
}
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
