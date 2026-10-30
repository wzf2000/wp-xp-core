<?php
/** Pause an actual bbPress reward before commit while another process moderates its topic. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local' ||
    !function_exists('bbp_insert_topic')
) {
    throw new RuntimeException('Owned synthetic bbPress required');
}
$owner_path = realpath((string) getenv('WP_XP_TEST_OWNER_FILE'));
$owner = $owner_path ? json_decode(file_get_contents($owner_path), true) : null;
if (
    !$owner_path ||
    !str_starts_with($owner_path, '/tmp/') ||
    !is_array($owner) ||
    ($owner['token'] ?? null) !== get_option('wp_xp_core_test_owner')
) {
    throw new RuntimeException('Owned lab marker required');
}
$directory = dirname($owner_path);
$signal = $directory . '/bbpress-uncommitted.json';
$waiting = $directory . '/bbpress-moderation-waiting';
$key = 'wp_xp_core_bbpress_race_fixture';
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$mode = getenv('WP_XP_TEST_MODE');
$wait = static function ($path) {
    $until = microtime(true) + 8;
    while (!is_file($path) && microtime(true) < $until) {
        usleep(10000);
    }
    if (!is_file($path)) {
        throw new RuntimeException('Bounded bbPress race signal timed out');
    }
};
if ($mode === 'prepare') {
    if (get_option($key, false) !== false || is_file($signal) || is_file($waiting)) {
        throw new RuntimeException('Fresh race fixture required');
    }
    $uid = wp_insert_user([
        'user_login' => 'fixture-bbpress-race',
        'user_email' => 'bbpress-race@example.invalid',
        'user_pass' => wp_generate_password(32),
        'role' => 'subscriber',
    ]);
    if (is_wp_error($uid)) {
        throw new RuntimeException('Race user failed');
    }
    $forum = (int) bbp_insert_forum([
        'post_title' => 'Synthetic race forum',
        'post_status' => 'publish',
        'post_author' => $admin->ID,
    ]);
    $rules = WP_XP_Core_Settings::rules();
    $next = $rules;
    $next['activity_caps'] = ['day' => 0, 'week' => 0];
    $next['providers']['bbpress_topic'] = ['enabled' => true, 'xp' => 7];
    if (
        WP_XP_Core_Settings::save(
            $next,
            wp_create_nonce('wp_xp_core_settings'),
            WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
        ) !== true
    ) {
        throw new RuntimeException('Race policy failed');
    }
    add_option($key, ['uid' => (int) $uid, 'forum' => $forum, 'rules' => $rules], '', false);
    echo wp_json_encode(['prepared' => true]);
    return;
}
$fixture = get_option($key);
if (!is_array($fixture)) {
    throw new RuntimeException('Race fixture required');
}
global $wpdb;
// This fixture is restricted above to its own synthetic user and local database.
// Keep failure evidence structural: no usernames, titles, content, reasons or SQL text.
$diagnostic = static function (int $topic) use ($wpdb, $fixture): array {
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT kind,xp FROM ' .
                Reader_Experience::table() .
                ' WHERE user_id=%d ORDER BY id LIMIT 20',
            $fixture['uid'],
        ),
        ARRAY_A,
    );
    $rows_failed = $wpdb->last_error !== '' || !is_array($rows);
    $rows = is_array($rows)
        ? array_map(
            static fn($row) => ['kind' => (string) $row['kind'], 'xp' => (int) $row['xp']],
            $rows,
        )
        : [];
    wp_cache_delete($fixture['uid'], 'user_meta');
    $balance = (int) get_user_meta($fixture['uid'], Reader_Experience::balance_meta(), true);
    $balance_failed = $wpdb->last_error !== '';
    $status = $wpdb->get_var(
        $wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d", $topic),
    );
    $status_failed = $wpdb->last_error !== '';
    $failures = get_option('wp_xp_core_bbpress_failures', []);
    $codes = [];
    foreach (is_array($failures) ? array_slice($failures, -20) : [] as $failure) {
        if (
            is_array($failure) &&
            (int) ($failure['object_id'] ?? 0) === $topic &&
            is_string($failure['code'] ?? null)
        ) {
            $codes[] = substr($failure['code'], 0, 64);
        }
    }
    return [
        'rows' => $rows,
        'balance' => $balance,
        'topic_status' => is_string($status) ? substr($status, 0, 40) : null,
        'failure_codes' => $codes,
        'read_errors' => [
            'rows' => $rows_failed,
            'balance' => $balance_failed,
            'status' => $status_failed,
        ],
    ];
};
if ($mode === 'reward') {
    $sent = false;
    $created_topic = 0;
    $capture_topic = static function ($id) use (&$created_topic): void {
        $created_topic = (int) $id;
    };
    // Capture the official creation-hook ID immediately before XP's priority-100 callback.
    add_action('bbp_insert_topic', $capture_topic, 99, 1);
    $ledger_insert_seen = false;
    $balance_table = $wpdb->usermeta;
    $timing = [];
    $filter = static function ($sql) use (
        &$sent,
        &$created_topic,
        &$ledger_insert_seen,
        &$timing,
        $balance_table,
        $signal,
        $waiting,
        $wait,
    ) {
        if (str_starts_with($sql, 'INSERT INTO ' . Reader_Experience::table())) {
            $ledger_insert_seen = true;
        }
        // The ledger INSERT has returned successfully before record() writes its balance.
        // Pause before that metadata write so bbPress can update its own author counts.
        $balance_write =
            preg_match(
                '/^(?:INSERT INTO|UPDATE) `?' . preg_quote($balance_table, '/') . '`?\s/',
                $sql,
            ) && str_contains($sql, Reader_Experience::balance_meta());
        if (!$sent && $ledger_insert_seen && $balance_write) {
            $sent = true;
            $id = $created_topic;
            $timing['before_balance_write'] = microtime(true);
            if (
                !$id ||
                file_put_contents(
                    $signal,
                    wp_json_encode([
                        'topic' => $id,
                        'stage' => 'ledger_inserted_before_balance_write',
                        'time' => $timing['before_balance_write'],
                    ]),
                    LOCK_EX,
                ) === false
            ) {
                throw new RuntimeException('Race signal write failed');
            }
            // Never issue a nested query on the active wpdb instance from its query filter.
            $wait($waiting);
            $timing['moderation_waiting'] = json_decode(file_get_contents($waiting), true);
            $timing['resume_balance_write'] = microtime(true);
        }
        return $sql;
    };
    add_filter('query', $filter);
    try {
        $topic = bbp_insert_topic(
            [
                'post_title' => 'Synthetic uncommitted reward',
                'post_content' => 'Body',
                'post_status' => 'publish',
                'post_author' => $fixture['uid'],
                'post_parent' => $fixture['forum'],
            ],
            ['forum_id' => $fixture['forum']],
        );
    } finally {
        remove_filter('query', $filter);
        remove_action('bbp_insert_topic', $capture_topic, 99);
    }
    if (!$sent || !$topic) {
        throw new RuntimeException('Real provider receipt was not paused');
    }
    echo wp_json_encode([
        'topic' => (int) $topic,
        'timing' => $timing,
        'diagnostic' => $diagnostic((int) $topic),
    ]);
    return;
}
if ($mode === 'moderate') {
    $wait($signal);
    $data = json_decode(file_get_contents($signal), true);
    if (!is_array($data) || empty($data['topic'])) {
        throw new RuntimeException('Topic signal unavailable');
    }
    $timing = [];
    $count_table = $wpdb->usermeta;
    $filter = static function ($sql) use ($waiting, &$timing, $count_table) {
        if (
            preg_match(
                '/^(?:INSERT INTO|UPDATE) `?' . preg_quote($count_table, '/') . '`?\s/',
                $sql,
            ) &&
            str_contains($sql, '_bbp_topic_count')
        ) {
            $timing['before_author_count_write'] = microtime(true);
        }
        if (
            str_contains($sql, 'SELECT GET_LOCK(') &&
            str_contains($sql, reader_experience_lock('event_lock'))
        ) {
            $timing['before_event_lock'] = microtime(true);
            if (file_put_contents($waiting, wp_json_encode($timing), LOCK_EX) === false) {
                throw new RuntimeException('Wait signal failed');
            }
        }
        return $sql;
    };
    add_filter('query', $filter);
    try {
        bbp_spam_topic((int) $data['topic']);
    } finally {
        remove_filter('query', $filter);
    }
    echo wp_json_encode([
        'moderated' => true,
        'timing' => $timing,
        'diagnostic' => $diagnostic((int) $data['topic']),
    ]);
    return;
}
if ($mode !== 'verify') {
    throw new RuntimeException('Explicit bbPress race mode required');
}
$rows = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT kind,xp FROM ' . Reader_Experience::table() . ' WHERE user_id=%d ORDER BY id',
        $fixture['uid'],
    ),
    ARRAY_A,
);
wp_cache_delete($fixture['uid'], 'user_meta');
if (
    $wpdb->last_error !== '' ||
    array_column($rows, 'kind') !== ['provider_reward', 'provider_revoke'] ||
    array_map('intval', array_column($rows, 'xp')) !== [7, -7] ||
    (int) get_user_meta($fixture['uid'], Reader_Experience::balance_meta(), true) !== 0
) {
    throw new RuntimeException(
        'Moderation must wait for the uncommitted reward and reverse it exactly: ' .
            wp_json_encode(
                $diagnostic((int) (json_decode(file_get_contents($signal), true)['topic'] ?? 0)),
            ),
    );
}
if (
    WP_XP_Core_Settings::save(
        $fixture['rules'],
        wp_create_nonce('wp_xp_core_settings'),
        WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
    ) !== true
) {
    throw new RuntimeException('Race policy restore failed');
}
unlink($signal);
unlink($waiting);
echo wp_json_encode([
    'checks' => [
        'Real reward paused before commit',
        'Moderation waited on event lock',
        'Original recipient resolved after commit',
        'Exactly one full reversal',
        'Balance remains zero',
    ],
]);
