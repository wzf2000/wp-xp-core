<?php
/** Opt-in synthetic 10k/50k read workload; the ledger here is fixture data, not real rewards. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_SCALE_TEST') !== '1' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Explicit isolated scale-test authorization required.');
}
$site = realpath(ABSPATH);
$owner_file = getenv('WP_XP_TEST_OWNER_FILE');
if (
    !$site ||
    !str_starts_with($site, '/tmp/') ||
    !$owner_file ||
    $owner_file !== dirname($site) . '/.wp-xp-core-test-owner' ||
    is_link($owner_file) ||
    !is_readable($owner_file) ||
    !preg_match('~^(127\.0\.0\.1:[0-9]+|localhost:/tmp/[^\x00]+)$~D', DB_HOST)
) {
    throw new RuntimeException('Owned temporary loopback fixture required.');
}
$owner = json_decode(file_get_contents($owner_file), true);
if (
    !is_array($owner) ||
    ($owner['site'] ?? '') !== $site ||
    ($owner['database'] ?? '') !== DB_NAME ||
    !is_string($owner['token'] ?? null) ||
    !hash_equals((string) get_option('wp_xp_core_test_owner', ''), $owner['token'])
) {
    throw new RuntimeException('Scale-test ownership marker mismatch.');
}
$admin = get_user_by('login', 'fixture-author');
if (!$admin || !Reader_Experience::ready()) {
    throw new RuntimeException('Prepared isolated plugin installation required.');
}
wp_set_current_user($admin->ID);
global $wpdb;
$table = Reader_Experience::table();
$users = [];
$posts = [];
$comments = [];
for ($i = 0; $i < 10; $i++) {
    $login = 'fixture-scale-' . $i;
    if (get_user_by('login', $login)) {
        throw new RuntimeException('Scale fixture must not reuse existing subjects.');
    }
    $uid = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32),
        'user_email' => $login . '@example.invalid',
        'role' => 'subscriber',
    ]);
    if (is_wp_error($uid)) {
        throw new RuntimeException('Scale user creation failed.');
    }
    $users[] = (int) $uid;
    $post = wp_insert_post([
        'post_title' => 'Synthetic scale content ' . $i,
        'post_author' => $admin->ID,
        'post_status' => 'publish',
    ]);
    if (!$post || is_wp_error($post)) {
        throw new RuntimeException('Scale content creation failed.');
    }
    $posts[] = (int) $post;
    $cid = wp_insert_comment([
        'comment_post_ID' => $post,
        'user_id' => $uid,
        'comment_approved' => '1',
        'comment_content' => 'Synthetic scale comment',
    ]);
    if (!$cid) {
        throw new RuntimeException('Scale comment creation failed.');
    }
    $comments[] = (int) $cid;
}
$old_definitions = get_option(WP_XP_Core_Achievements::OPTION, null);
$defs = [];
$ids = [];
for ($i = 0; $i < 50; $i++) {
    $ids[] = 't_' . substr(hash('sha256', 'owned-scale-task-' . $i), 0, 16);
}
for ($i = 0; $i < 50; $i++) {
    $steps = [];
    foreach (
        [
            ['visit', 'events'],
            ['visit', 'objects'],
            ['visit', 'days'],
            ['checkin', 'events'],
            ['checkin', 'days'],
            ['article', 'events'],
            ['comment', 'events'],
            ['comment', 'days'],
        ]
        as [$kind, $mode]
    ) {
        $steps[] = [
            'kind' => $kind,
            'mode' => $mode,
            'count' => 1000,
            'post_ids' => $kind === 'checkin' ? [] : $posts,
            'category_ids' => [],
        ];
    }
    $pre = [];
    for ($j = 0; $j < min($i, 8); $j++) {
        $pre[] = ['id' => $ids[$j], 'scope' => $j % 2 ? 'lifetime' : 'current'];
    }
    $defs[$ids[$i]] = [
        'id' => $ids[$i],
        'number' => $i + 1,
        'title' => 'Synthetic scale task ' . $i,
        'description' => '',
        'icon' => '',
        'type' => 'automatic',
        'period' => 'once',
        'enabled' => true,
        'steps' => $steps,
        'prerequisites' => $pre,
        'starts_on' => '',
        'ends_on' => '',
        'reward_xp' => 0,
        'image_id' => 0,
        'category' => '',
        'series' => '',
        'version' => 1,
        'start_event_id' => 0,
        'created_at' => time(),
    ];
}
$results = [];
$seeded = 0;
$today = new DateTimeImmutable(Reader_Experience::day(), new DateTimeZone('UTC'));
$kinds = ['visit', 'checkin', 'article', 'comment', 'manual_adjust'];
$measure = static function (string $name, callable $callback) use ($wpdb): array {
    $q = $wpdb->num_queries;
    $start = hrtime(true);
    $value = $callback();
    $ms = (hrtime(true) - $start) / 1000000;
    if (is_wp_error($value)) {
        throw new RuntimeException('Scale query failed: ' . $name . ':' . $value->get_error_code());
    }
    return ['queries' => $wpdb->num_queries - $q, 'elapsed_ms' => round($ms, 3)];
};
try {
    update_option(WP_XP_Core_Achievements::OPTION, $defs, false);
    WP_XP_Core_Achievements::clear_cache();
    for ($i = 0; $i < 8; $i++) {
        $wpdb->insert($table, [
            'event_key' => 'task:' . $ids[$i] . ':1:once:' . $users[0],
            'user_id' => $users[0],
            'kind' => 'task_complete',
            'object_id' => 0,
            'xp' => 0,
            'local_day' => $today->format('Y-m-d'),
            'created_at' => time(),
            'detail' => wp_json_encode([
                'task_id' => $ids[$i],
                'version' => 1,
                'period' => 'once',
                'title' => 'Synthetic scale prerequisite',
                'icon' => '',
            ]),
        ]);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Scale honor seed failed.');
        }
    }
    foreach ([10000, 50000] as $target) {
        while ($seeded < $target) {
            $tuples = [];
            for ($j = 0; $j < min(500, $target - $seeded); $j++) {
                $n = $seeded + $j;
                $index = $n % 10;
                $kind = $kinds[$n % 5];
                $object =
                    $kind === 'comment'
                        ? $comments[$index]
                        : ($kind === 'checkin'
                            ? 0
                            : $posts[$index]);
                $day = $today->modify('-' . $n % 7 . ' days')->format('Y-m-d');
                $tuples[] = $wpdb->prepare(
                    '(%s,%d,%s,%d,%s,%d,%d,%s)',
                    'synthetic-scale:' . $n,
                    $users[$index],
                    $kind,
                    $object,
                    $day,
                    1,
                    time(),
                    '{}',
                );
            }
            if (
                $wpdb->query(
                    'INSERT INTO ' .
                        $table .
                        ' (event_key,user_id,kind,object_id,local_day,xp,created_at,detail) VALUES ' .
                        implode(',', $tuples),
                ) === false
            ) {
                throw new RuntimeException('Bounded scale seed failed.');
            }
            $seeded += count($tuples);
        }
        $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        wp_set_current_user($users[0]);
        $metrics = [];
        $metrics['personal_50_tasks_8_steps'] = $measure(
            'personal',
            static fn() => WP_XP_Core_Achievements::personal(),
        );
        $metrics['progress_8_steps'] = $measure(
            'progress',
            static fn() => WP_XP_Core_Achievements::progress($defs[$ids[49]], $users[0]),
        );
        $metrics['notifications'] = $measure(
            'notifications',
            static fn() => WP_XP_Core_Notifications::listing([], wp_create_nonce('wp_rest')),
        );
        $metrics['leaderboard'] = $measure(
            'leaderboard',
            static fn() => WP_XP_Core_Insights::leaderboard(['period' => 'month']),
        );
        wp_set_current_user($admin->ID);
        $metrics['sources'] = $measure(
            'sources',
            static fn() => WP_XP_Core_Insights::sources(['period' => 'month']),
        );
        $metrics['trends'] = $measure(
            'trends',
            static fn() => WP_XP_Core_Insights::trends(['period' => 'month']),
        );
        $metrics['task_rates_50_tasks'] = $measure(
            'task_rates',
            static fn() => WP_XP_Core_Insights::task_rates(),
        );
        $metrics['task_history'] = $measure(
            'task_history',
            static fn() => WP_XP_Core_Insights::task_history(['period' => 'month']),
        );
        $metrics['anomaly_clues'] = $measure(
            'anomalies',
            static fn() => WP_XP_Core_Insights::anomalies(['period' => 'month']),
        );
        if (
            $metrics['personal_50_tasks_8_steps']['queries'] > 30 ||
            $metrics['progress_8_steps']['queries'] > 12 ||
            $before !== (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table)
        ) {
            throw new RuntimeException('Scale query bound or read-only contract failed.');
        }
        $plan = $wpdb->get_results(
            $wpdb->prepare(
                'EXPLAIN SELECT e.id FROM ' .
                    $table .
                    ' e WHERE e.user_id=%d AND e.kind=%s AND e.local_day BETWEEN %s AND %s AND e.id>0',
                $users[0],
                'visit',
                $today->modify('-6 days')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ),
            ARRAY_A,
        );
        $results[] = [
            'seeded_ledger_rows' => $target,
            'users' => 10,
            'tasks' => 50,
            'steps_per_task' => 8,
            'prerequisites_per_task_max' => 8,
            'cache' => 'same local process; WordPress and database caches may be warm',
            'metrics' => $metrics,
            'range_plan' => array_map(
                static fn($row) => array_intersect_key(
                    $row,
                    array_flip(['table', 'type', 'key', 'rows']),
                ),
                $plan,
            ),
        ];
    }
} finally {
    wp_set_current_user($admin->ID);
    if ($old_definitions === null) {
        delete_option(WP_XP_Core_Achievements::OPTION);
    } else {
        update_option(WP_XP_Core_Achievements::OPTION, $old_definitions, false);
    }
    WP_XP_Core_Achievements::clear_cache();
}
echo wp_json_encode(
    [
        'checks' => [
            'Opt-in owned 10k and 50k workloads completed',
            'Personal and per-task query counts are bounded',
            'All measured operations leave the ledger unchanged',
        ],
        'environment' =>
            'isolated synthetic loopback WordPress; timings are not production benchmarks',
        'samples' => $results,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
) . "\n";
