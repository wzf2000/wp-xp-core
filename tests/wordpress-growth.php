<?php
/** Growth and achievements on a dedicated synthetic WordPress database, with an explicit test clock. */
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
    'Isolated administrator and all growth modules are available',
    $admin &&
        class_exists('WP_XP_Core_Growth') &&
        class_exists('WP_XP_Core_Achievements') &&
        class_exists('WP_XP_Core_Growth_UI') &&
        class_exists('WP_XP_Core_Achievements_UI'),
);
wp_set_current_user($admin->ID);
$users = [];
foreach (['fixture-growth-reader', 'fixture-growth-other'] as $login) {
    $verify('Growth fixture never reuses an existing subject', !get_user_by('login', $login));
    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32),
        'user_email' => $login . '@example.invalid',
        'role' => 'subscriber',
    ]);
    $verify('Synthetic growth subject created', !is_wp_error($id));
    $users[] = (int) $id;
}
[$reader, $other] = $users;
global $wpdb;
$table = Reader_Experience::table();
$balance = static function (int $uid): int {
    wp_cache_delete($uid, 'user_meta');
    return (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
};
$count = static function (string $kind, int $uid) use ($wpdb, $table): int {
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE kind=%s AND user_id=%d',
            $kind,
            $uid,
        ),
    );
};
$rest = static function (string $action, array $data = [], $nonce = null) {
    $request = new WP_REST_Request(
        'POST',
        '/' . reader_experience_config('rest_namespace') . '/' . $action,
    );
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', $nonce ?? wp_create_nonce('wp_rest'));
    $request->set_body(wp_json_encode((object) $data));
    return rest_do_request($request);
};
$save_growth = static fn(array $input) => WP_XP_Core_Growth::save(
    $input,
    wp_create_nonce('wp_xp_core_growth'),
    WP_XP_Core_Growth::revision(),
);
$save_task = static fn(array $input) => WP_XP_Core_Achievements::save(
    $input,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$task_input = static fn(array $definition): array => array_intersect_key(
    $definition,
    array_flip(['id', 'title', 'description', 'icon', 'type', 'period', 'enabled', 'steps']),
);
$new_task = static fn(string $title, string $type, array $steps = []): array => [
    'id' => '',
    'title' => $title,
    'description' => 'Synthetic future activities only',
    'icon' => '★',
    'type' => $type,
    'period' => 'once',
    'enabled' => true,
    'steps' => $steps,
];
$definitions = WP_XP_Core_Achievements::definitions();
$verify('No tasks are created by default', $definitions === []);
$verify(
    'Check-in stage rewards are disabled by default',
    !WP_XP_Core_Growth::settings()['streak']['enabled'],
);
$growth = [
    'levels' => [
        1 => [
            'name' => '启程',
            'icon' => '芽',
            'color' => '#3856b0',
            'description' => 'Synthetic level profile',
        ],
    ],
    'streak' => ['enabled' => false, 'mode' => 'cumulative', 'rewards' => []],
];
$revision = WP_XP_Core_Growth::revision();
$verify(
    'Growth settings reject an invalid nonce',
    $error(WP_XP_Core_Growth::save($growth, 'invalid', $revision), 'nonce'),
);
wp_set_current_user($reader);
$verify('Subscribers cannot save growth settings', $error($save_growth($growth), 'forbidden'));
wp_set_current_user($admin->ID);
$old_growth = get_option(WP_XP_Core_Growth::OPTION, null);
$verify(
    'Administrator can save level metadata',
    $save_growth($growth) === true &&
        WP_XP_Core_Growth::level_profile(1)['name'] === '启程' &&
        WP_XP_Core_Growth::level_profile(1)['icon'] === '芽' &&
        WP_XP_Core_Growth::level_profile(1)['color'] === '#3856b0',
);
$verify(
    'Growth settings reject a stale revision',
    $error(
        WP_XP_Core_Growth::save($growth, wp_create_nonce('wp_xp_core_growth'), $revision),
        'conflict',
    ),
);
$audit = WP_XP_Core_Management::audit_history();
$verify(
    'Growth setting changes have before after and actor audit evidence',
    !is_wp_error($audit) &&
        $audit[0]['action'] === 'growth_settings' &&
        $audit[0]['before'] === $old_growth &&
        $audit[0]['after'] === get_option(WP_XP_Core_Growth::OPTION, null) &&
        $audit[0]['actor'] === (int) $admin->ID,
);

// A WordPress date filter advances only the synthetic local day; no wall-clock waits or old-row edits.
$day = Reader_Experience::day();
$clock = static fn($date, $format) => $format === 'Y-m-d' ? $GLOBALS['xp_growth_test_day'] : $date;
$GLOBALS['xp_growth_test_day'] = $day;
add_filter('wp_date', $clock, 10, 2);
$advance = static function (): void {
    $GLOBALS['xp_growth_test_day'] = (new DateTimeImmutable(
        $GLOBALS['xp_growth_test_day'],
        new DateTimeZone('UTC'),
    ))
        ->modify('+1 day')
        ->format('Y-m-d');
};
wp_set_current_user($reader);
$before = $balance($reader);
$verify(
    'Default check-in grants only its ordinary activity amount',
    $rest('checkin')->get_status() === 200 &&
        $balance($reader) === $before + WP_XP_Core_Settings::rules()['checkin_xp'] &&
        $count('streak_reward', $reader) === 0,
);
wp_set_current_user($admin->ID);
$growth['streak']['enabled'] = true;
$growth['streak']['rewards'] = [['id' => 'synthetic_first', 'days' => 1, 'xp' => 11]];
$before = $balance($reader);
$verify('Stage rewards can be explicitly enabled', $save_growth($growth) === true);
$verify(
    'Enabling stage rewards excludes previous check-ins',
    WP_XP_Core_Growth::streak_progress($reader)['days'] === 0 &&
        $balance($reader) === $before &&
        $count('streak_reward', $reader) === 0,
);
wp_set_current_user($reader);
$verify(
    'Repeated old-day check-in does not trigger a new stage award',
    $rest('checkin')->get_status() === 200 &&
        $balance($reader) === $before &&
        $count('streak_reward', $reader) === 0,
);
$advance();
$verify(
    'A new check-in earns the enabled future-only stage',
    $rest('checkin')->get_status() === 200 &&
        $balance($reader) === $before + WP_XP_Core_Settings::rules()['checkin_xp'] + 11 &&
        $count('streak_reward', $reader) === 1,
);
$after_stage = $balance($reader);
$verify(
    'Repeated new-day check-in does not repeat the stage reward',
    $rest('checkin')->get_status() === 200 &&
        $balance($reader) === $after_stage &&
        $count('streak_reward', $reader) === 1,
);

wp_set_current_user($admin->ID);
$automatic = $new_task('Synthetic two-step task', 'automatic', [
    ['kind' => 'checkin', 'count' => 1],
    ['kind' => 'visit', 'count' => 2],
]);
$created = $save_task($automatic);
$verify('Administrator can create a multi-step automatic task', !is_wp_error($created));
$automatic_id = $created['id'];
$automatic = WP_XP_Core_Achievements::definitions()[$automatic_id];
$progress = WP_XP_Core_Achievements::progress($automatic, $reader);
$verify(
    'New automatic task excludes historical check-ins and visits',
    !$progress['complete'] &&
        $progress['steps'][0]['current'] === 0 &&
        $progress['steps'][1]['current'] === 0,
);
$pages = [];
foreach (['One', 'Two'] as $title) {
    $id = wp_insert_post(
        [
            'post_title' => 'Synthetic task reading ' . $title,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_author' => $admin->ID,
        ],
        true,
    );
    $verify('Public synthetic reading page created', !is_wp_error($id));
    $pages[] = (int) $id;
}
$visit = static function (int $pid) use ($rest) {
    $issued = time() - WP_XP_Core_Settings::rules()['dwell_seconds'] - 1;
    $uid = get_current_user_id();
    return $rest('visit', [
        'post_id' => $pid,
        'issued' => $issued,
        'signature' => hash_hmac('sha256', "$uid:$pid:$issued", wp_salt('nonce')),
    ]);
};
wp_set_current_user($reader);
$verify(
    'Unfinished task cannot be claimed',
    $error(WP_XP_Core_Achievements::claim($automatic_id, wp_create_nonce('wp_rest')), 'incomplete'),
);
$advance();
$before_task = $balance($reader);
$verify(
    'New check-in and reading are accepted for the automatic task',
    $rest('checkin')->get_status() === 200 && $visit($pages[0])->get_status() === 200,
);
$progress = WP_XP_Core_Achievements::progress($automatic, $reader);
$verify(
    'One completed step does not grant the whole task',
    !$progress['complete'] &&
        !$progress['earned'] &&
        $progress['steps'][0]['current'] === 1 &&
        $progress['steps'][1]['current'] === 1,
);
$verify(
    'Final new activity grants one automatic honor',
    $visit($pages[1])->get_status() === 200 &&
        WP_XP_Core_Achievements::progress($automatic, $reader)['earned'] &&
        $count('task_complete', $reader) === 1,
);
$verify(
    'Automatic honor adds no experience',
    $balance($reader) ===
        $before_task +
            WP_XP_Core_Settings::rules()['checkin_xp'] +
            2 * WP_XP_Core_Settings::rules()['visit_xp'],
);
$repeat = WP_XP_Core_Achievements::claim($automatic_id, wp_create_nonce('wp_rest'));
$verify(
    'Repeated claim returns the already earned honor',
    !is_wp_error($repeat) && $repeat['duplicate'] && $count('task_complete', $reader) === 1,
);
$verify(
    'Task completion events always carry zero experience',
    (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COALESCE(SUM(ABS(xp)),0) FROM ' .
                $table .
                " WHERE user_id=%d AND kind='task_complete'",
            $reader,
        ),
    ) === 0,
);
wp_set_current_user($admin->ID);
$edited = $task_input($automatic);
$edited['steps'][0]['count']++;
$verify('Changing a task creates a new definition version', !is_wp_error($save_task($edited)));
$new_version = WP_XP_Core_Achievements::definitions()[$automatic_id];
$progress = WP_XP_Core_Achievements::progress($new_version, $reader);
$verify(
    'New task version starts with future activity and keeps the old honor',
    $new_version['version'] === $automatic['version'] + 1 &&
        !$progress['earned'] &&
        !$progress['complete'] &&
        $progress['steps'][0]['current'] === 0 &&
        $progress['steps'][1]['current'] === 0 &&
        $count('task_complete', $reader) === 1,
);

$created = $save_task($new_task('Synthetic reviewed honor', 'review'));
$verify('Administrator can create an honor requiring review', !is_wp_error($created));
$review_task_id = $created['id'];
$submission = [
    'task_id' => $review_task_id,
    'text' => 'Synthetic private completed work for the reviewer.',
    'request_id' => wp_generate_uuid4(),
];
wp_set_current_user($reader);
$verify(
    'Submitting an honor requires a valid user nonce',
    $error(WP_XP_Core_Achievements::submit($submission, 'invalid'), 'nonce'),
);
$verify(
    'Submit cannot nominate another owner',
    $error(
        WP_XP_Core_Achievements::submit(
            $submission + ['user_id' => $other],
            wp_create_nonce('wp_rest'),
        ),
        'input',
    ),
);
$submitted = WP_XP_Core_Achievements::submit($submission, wp_create_nonce('wp_rest'));
$verify(
    'Reader can submit a private completion explanation',
    !is_wp_error($submitted) && !$submitted['duplicate'],
);
$repeat = WP_XP_Core_Achievements::submit($submission, wp_create_nonce('wp_rest'));
$verify(
    'Exact repeated submission is idempotent',
    !is_wp_error($repeat) && $repeat['duplicate'] && $count('achievement_submit', $reader) === 1,
);
$changed = array_replace($submission, [
    'text' => 'A different private completed work explanation.',
]);
$verify(
    'Changed text cannot reuse a submission token',
    $error(WP_XP_Core_Achievements::submit($changed, wp_create_nonce('wp_rest')), 'conflict'),
);
$changed['request_id'] = wp_generate_uuid4();
$verify(
    'Only one explanation can remain pending for a task',
    $error(WP_XP_Core_Achievements::submit($changed, wp_create_nonce('wp_rest')), 'pending'),
);
$row = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT * FROM ' . $table . ' WHERE event_key=%s',
        'task-submit:' . $reader . ':' . $submission['request_id'],
    ),
    ARRAY_A,
);
$verify(
    'Submission ownership comes from the authenticated reader',
    $row && (int) $row['user_id'] === $reader,
);
$submission_id = (int) $row['id'];
$decision = [
    'submission_id' => $submission_id,
    'decision' => 'approve',
    'feedback' => 'Synthetic reviewed work',
];
$verify(
    'Subscriber cannot approve an honor',
    $error(
        WP_XP_Core_Achievements::review($decision, wp_create_nonce('wp_xp_core_achievements')),
        'forbidden',
    ),
);
$verify(
    'Subscriber cannot inspect the administrative pending queue',
    $error(WP_XP_Core_Achievements::pending(), 'forbidden'),
);
$own = WP_XP_Core_Achievements::personal();
$verify(
    'Reader sees only their private submission',
    count($own['submissions']) === 1 && $own['submissions'][0]['user_id'] === $reader,
);
wp_set_current_user($other);
$private = WP_XP_Core_Achievements::personal();
$verify(
    'Another reader cannot see private text or pending explanations',
    $private['submissions'] === [] &&
        $private['pending'] === [] &&
        !str_contains(wp_json_encode($private), $submission['text']),
);
$verify(
    'Growth REST refuses a request targeting another account',
    $rest('growth', ['user_id' => $reader])->get_status() === 400,
);
$verify(
    'History REST refuses a request targeting another account',
    $rest('history', ['user_id' => $reader])->get_status() === 400,
);
wp_set_current_user($admin->ID);
$pending = WP_XP_Core_Achievements::pending();
$verify(
    'Administrator can find the pending synthetic explanation',
    !is_wp_error($pending) && in_array($submission_id, array_column($pending['items'], 'id'), true),
);
$verify(
    'Administrator review requires its own nonce',
    $error(WP_XP_Core_Achievements::review($decision, 'invalid'), 'nonce'),
);
$before_review = $balance($reader);
$approved = WP_XP_Core_Achievements::review($decision, wp_create_nonce('wp_xp_core_achievements'));
$verify(
    'Approving a submission grants one zero-XP honor',
    !is_wp_error($approved) &&
        !$approved['duplicate'] &&
        $balance($reader) === $before_review &&
        $count('task_complete', $reader) === 2 &&
        $count('achievement_review', $reader) === 1,
);
$approved = WP_XP_Core_Achievements::review($decision, wp_create_nonce('wp_xp_core_achievements'));
$verify(
    'Repeated approval neither adds a review nor another honor',
    !is_wp_error($approved) &&
        $approved['duplicate'] &&
        $count('task_complete', $reader) === 2 &&
        $count('achievement_review', $reader) === 1,
);
$verify(
    'A different decision cannot overwrite an earlier review',
    $error(
        WP_XP_Core_Achievements::review(
            array_replace($decision, [
                'decision' => 'reject',
                'feedback' => 'Conflicting reviewer decision',
            ]),
            wp_create_nonce('wp_xp_core_achievements'),
        ),
        'conflict',
    ),
);

// A still-enabled task with a new version and a disabled task must both permit rejection only.
$created = $save_task($new_task('Synthetic stale review', 'review'));
$verify('Synthetic task for stale review created', !is_wp_error($created));
$stale_id = $created['id'];
$submit_pending = static function (string $id, string $text) use (
    $reader,
    $admin,
    $wpdb,
    $table,
): int {
    wp_set_current_user($reader);
    $input = ['task_id' => $id, 'text' => $text, 'request_id' => wp_generate_uuid4()];
    $result = WP_XP_Core_Achievements::submit($input, wp_create_nonce('wp_rest'));
    if (is_wp_error($result)) {
        throw new RuntimeException(
            'Synthetic pending submission failed: ' . $result->get_error_code(),
        );
    }
    $event_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE event_key=%s',
            'task-submit:' . $reader . ':' . $input['request_id'],
        ),
    );
    wp_set_current_user($admin->ID);
    return $event_id;
};
$stale_submission = $submit_pending(
    $stale_id,
    'Synthetic explanation under the first task version.',
);
$task = $task_input(WP_XP_Core_Achievements::definitions()[$stale_id]);
$task['period'] = 'month';
$verify(
    'Review task can advance while old explanation remains pending',
    !is_wp_error($save_task($task)),
);
$stale_review = ['submission_id' => $stale_submission, 'decision' => 'approve', 'feedback' => ''];
$verify(
    'Old task version cannot be approved',
    $error(
        WP_XP_Core_Achievements::review($stale_review, wp_create_nonce('wp_xp_core_achievements')),
        'changed',
    ),
);
$stale_review['decision'] = 'reject';
$stale_review['feedback'] = 'Please submit against the current task version.';
$verify(
    'Old task version can still be rejected with an explanation',
    !is_wp_error(
        WP_XP_Core_Achievements::review($stale_review, wp_create_nonce('wp_xp_core_achievements')),
    ),
);
$disabled_submission = $submit_pending(
    $stale_id,
    'Synthetic explanation before this task is disabled.',
);
$task = $task_input(WP_XP_Core_Achievements::definitions()[$stale_id]);
$task['enabled'] = false;
$verify(
    'Review task may be disabled with an existing pending explanation',
    !is_wp_error($save_task($task)),
);
$disabled_review = [
    'submission_id' => $disabled_submission,
    'decision' => 'approve',
    'feedback' => '',
];
$verify(
    'Disabled task cannot be approved',
    $error(
        WP_XP_Core_Achievements::review(
            $disabled_review,
            wp_create_nonce('wp_xp_core_achievements'),
        ),
        'changed',
    ),
);
$disabled_review['decision'] = 'reject';
$verify(
    'Rejection requires a reason',
    $error(
        WP_XP_Core_Achievements::review(
            $disabled_review,
            wp_create_nonce('wp_xp_core_achievements'),
        ),
        'input',
    ),
);
$disabled_review['feedback'] = 'The synthetic task is no longer accepting submissions.';
$verify(
    'Disabled task can still be rejected',
    !is_wp_error(
        WP_XP_Core_Achievements::review(
            $disabled_review,
            wp_create_nonce('wp_xp_core_achievements'),
        ),
    ),
);

$snapshot = static function () use ($wpdb, $table, $reader, $other): array {
    return [
        'ledger' => $wpdb->get_row(
            'SELECT COUNT(*) AS entries,COALESCE(MAX(id),0) AS last_id,COALESCE(SUM(xp),0) AS total FROM ' .
                $table,
            ARRAY_A,
        ),
        'options' => $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s,%s) ORDER BY option_name",
                WP_XP_Core_Settings::OPTION,
                WP_XP_Core_Growth::OPTION,
                WP_XP_Core_Achievements::OPTION,
                WP_XP_Core_Management::AUDIT_OPTION,
            ),
            ARRAY_A,
        ),
        'balances' => $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id IN (%d,%d) ORDER BY user_id,umeta_id",
                Reader_Experience::balance_meta(),
                $reader,
                $other,
            ),
            ARRAY_A,
        ),
    ];
};
$before_read = $snapshot();
wp_set_current_user($reader);
WP_XP_Core_Growth::summary($reader);
WP_XP_Core_Achievements::personal();
$verify(
    'Private growth and task reads work through authenticated REST',
    $rest('growth')->get_status() === 200 &&
        $rest('tasks')->get_status() === 200 &&
        $rest('history', ['limit' => 5])->get_status() === 200,
);
wp_set_current_user($admin->ID);
WP_XP_Core_Growth::settings();
WP_XP_Core_Achievements::definitions();
WP_XP_Core_Achievements::pending();
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
WP_XP_Core_Growth_UI::settings_page();
$html = ob_get_clean();
$verify(
    'Growth configuration screen renders the actual validated controls',
    str_contains($html, 'growth[levels][1][name]') &&
        str_contains($html, 'growth[streak][enabled]'),
);
ob_start();
WP_XP_Core_Achievements_UI::page();
$html = ob_get_clean();
$verify(
    'Task configuration screen renders definitions and review workflow',
    str_contains($html, 'task[steps][0][kind]') && str_contains($html, '待审核说明'),
);
$verify(
    'Reading summaries definitions histories and settings pages does not write domain state',
    $before_read === $snapshot(),
);
wp_set_current_user(0);
$verify(
    'Anonymous growth reads are denied by REST',
    $rest('growth', [], 'invalid')->get_status() === 401,
);
$anonymous = WP_XP_Core_Achievements::personal();
$verify(
    'Anonymous task reads contain no personal state',
    $anonymous['tasks'] === [] && $anonymous['badges'] === [] && $anonymous['submissions'] === [],
);

// Fail only the derived reward INSERT. The originating check-in must remain retryable on this day.
wp_set_current_user($admin->ID);
$verify(
    'Atomic reward fixture never reuses an existing subject',
    !get_user_by('login', 'fixture-growth-atomic'),
);
$atomic_uid = wp_insert_user([
    'user_login' => 'fixture-growth-atomic',
    'user_pass' => wp_generate_password(32),
    'user_email' => 'fixture-growth-atomic@example.invalid',
    'role' => 'subscriber',
]);
$verify('Synthetic atomic reward subject created', !is_wp_error($atomic_uid));
$atomic_uid = (int) $atomic_uid;
$advance();
wp_set_current_user($atomic_uid);
$atomic_before = $balance($atomic_uid);
$seen_atomic = [];
$atomic_listener = static function (array $event) use ($atomic_uid, &$seen_atomic): void {
    if ((int) $event['user_id'] === $atomic_uid) {
        $seen_atomic[] = $event['kind'];
    }
};
add_action('wp_xp_core_event_committed', $atomic_listener, 100);
$streak_faults = 0;
$fail_streak = static function (string $sql) use ($table, &$streak_faults): string {
    if (
        str_starts_with(ltrim($sql), 'INSERT INTO ' . $table . '(') &&
        str_contains($sql, "'streak_reward'")
    ) {
        $streak_faults++;
        return 'INSERT INTO ' . $table . ' (xp_growth_missing_column) VALUES (1)';
    }
    return $sql;
};
$was_suppressed = $wpdb->suppress_errors(true);
add_filter('query', $fail_streak);
try {
    $failed_checkin = $rest('checkin');
} finally {
    remove_filter('query', $fail_streak);
    $wpdb->suppress_errors($was_suppressed);
}
$verify(
    'Derived reward INSERT failure rejects the whole check-in',
    $streak_faults === 1 && $failed_checkin->get_status() === 503,
);
$verify(
    'Failed derived reward rolls back both ledger rows and the balance',
    $count('checkin', $atomic_uid) === 0 &&
        $count('streak_reward', $atomic_uid) === 0 &&
        $balance($atomic_uid) === $atomic_before &&
        !Reader_Experience::exists('checkin:' . $atomic_uid . ':' . Reader_Experience::day()),
);
$verify('Rolled-back check-in and derived reward notify no observers', $seen_atomic === []);
$verify(
    'Retry on the same day commits both ordinary and extra XP exactly once',
    $rest('checkin')->get_status() === 200 &&
        $count('checkin', $atomic_uid) === 1 &&
        $count('streak_reward', $atomic_uid) === 1 &&
        $balance($atomic_uid) === $atomic_before + WP_XP_Core_Settings::rules()['checkin_xp'] + 11,
);
$atomic_after = $balance($atomic_uid);
$verify(
    'Committed retry reports both events after the successful transaction',
    count($seen_atomic) === 2 &&
        in_array('checkin', $seen_atomic, true) &&
        in_array('streak_reward', $seen_atomic, true),
);
$verify(
    'Another same-day retry repeats neither reward nor notification',
    $rest('checkin')->get_status() === 200 &&
        $count('checkin', $atomic_uid) === 1 &&
        $count('streak_reward', $atomic_uid) === 1 &&
        $balance($atomic_uid) === $atomic_after &&
        count($seen_atomic) === 2,
);
remove_action('wp_xp_core_event_committed', $atomic_listener, 100);

wp_set_current_user($admin->ID);
$domain_configuration = static fn(): array => $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s) ORDER BY option_name",
        WP_XP_Core_Growth::OPTION,
        WP_XP_Core_Achievements::OPTION,
        WP_XP_Core_Management::AUDIT_OPTION,
    ),
    ARRAY_A,
);
$boundary_faults = 0;
$fail_boundary = static function (string $sql) use ($table, &$boundary_faults): string {
    if ($sql === 'SELECT COALESCE(MAX(id),0) FROM ' . $table) {
        $boundary_faults++;
        return 'SELECT xp_growth_missing_boundary_column FROM ' . $table . ' LIMIT 1';
    }
    return $sql;
};
$boundary_growth = $growth;
$boundary_growth['streak']['rewards'][0]['xp'] = 12;
$configuration_before = $domain_configuration();
$was_suppressed = $wpdb->suppress_errors(true);
add_filter('query', $fail_boundary);
try {
    $failed_growth_save = $save_growth($boundary_growth);
} finally {
    remove_filter('query', $fail_boundary);
    $wpdb->suppress_errors($was_suppressed);
}
$verify(
    'Growth configuration rejects a failed event-boundary query',
    $boundary_faults === 1 && $error($failed_growth_save, 'storage'),
);
$verify(
    'Failed growth boundary advances neither configuration nor audit',
    $configuration_before === $domain_configuration(),
);
$boundary_faults = 0;
$was_suppressed = $wpdb->suppress_errors(true);
add_filter('query', $fail_boundary);
try {
    $failed_task_save = $save_task(
        $new_task('Synthetic failed task boundary', 'automatic', [
            ['kind' => 'visit', 'count' => 1],
        ]),
    );
} finally {
    remove_filter('query', $fail_boundary);
    $wpdb->suppress_errors($was_suppressed);
}
$verify(
    'Task configuration rejects a failed event-boundary query',
    $boundary_faults === 1 && $error($failed_task_save, 'storage'),
);
$verify(
    'Failed task boundary advances neither definitions nor audit',
    $configuration_before === $domain_configuration(),
);

// Simulate maintenance beginning while a request waits for the event lock, without altering live state.
$live_option = $wpdb->prepare('%s', reader_experience_config('live_option'));
$locked = false;
$locked_ready_reads = 0;
$ready_after_lock = static function (string $sql) use (
    $wpdb,
    $live_option,
    &$locked,
    &$locked_ready_reads,
): string {
    if (str_starts_with(ltrim($sql), 'SELECT GET_LOCK(')) {
        $locked = true;
    }
    if (
        $locked &&
        str_contains($sql, 'SELECT option_value FROM ' . $wpdb->options) &&
        str_contains($sql, 'WHERE option_name=' . $live_option)
    ) {
        $locked_ready_reads++;
        return "SELECT '0' AS option_value";
    }
    return $sql;
};
$configuration_before = $domain_configuration();
add_filter('query', $ready_after_lock);
try {
    $unready_growth_save = $save_growth($boundary_growth);
} finally {
    remove_filter('query', $ready_after_lock);
}
$verify(
    'Growth save rechecks readiness after taking the event lock',
    $locked_ready_reads > 0 && $error($unready_growth_save, 'maintenance'),
);
$verify(
    'Maintenance beginning under the lock cannot update growth or audit',
    $configuration_before === $domain_configuration(),
);
$locked = false;
$locked_ready_reads = 0;
add_filter('query', $ready_after_lock);
try {
    $unready_task_save = $save_task($new_task('Synthetic task during maintenance', 'review'));
} finally {
    remove_filter('query', $ready_after_lock);
}
$verify(
    'Task save rechecks readiness after taking the event lock',
    $locked_ready_reads > 0 && $error($unready_task_save, 'maintenance'),
);
$verify(
    'Maintenance beginning under the lock cannot update tasks or audit',
    $configuration_before === $domain_configuration(),
);

// Recreate a stale local options cache after a newer configuration has already committed.
$stale_growth = get_option(WP_XP_Core_Growth::OPTION, null);
$fresh_growth = $growth;
$fresh_growth['levels'][1]['description'] = 'Synthetic newest persisted level profile';
$verify(
    'Fresh growth configuration for stale-cache regression saves',
    $save_growth($fresh_growth) === true,
);
$configuration_before = $domain_configuration();
wp_cache_set(WP_XP_Core_Growth::OPTION, $stale_growth, 'options');
$verify(
    'Growth stale-cache fixture actually serves the old value',
    get_option(WP_XP_Core_Growth::OPTION, null) === $stale_growth,
);
$fresh_growth['levels'][1]['description'] = 'Synthetic stale overwrite attempt';
$stale_result = WP_XP_Core_Growth::save(
    $fresh_growth,
    wp_create_nonce('wp_xp_core_growth'),
    WP_XP_Core_Settings::revision($stale_growth),
);
$verify(
    'Growth CAS reloads storage before comparing a stale cached revision',
    $error($stale_result, 'conflict') &&
        $configuration_before === $domain_configuration() &&
        WP_XP_Core_Growth::level_profile(1)['description'] ===
            'Synthetic newest persisted level profile',
);
$stale_tasks = get_option(WP_XP_Core_Achievements::OPTION, null);
$fresh_task = $task_input($stale_tasks[$review_task_id]);
$fresh_task['description'] = 'Synthetic newest persisted task description';
$verify(
    'Fresh task definition for stale-cache regression saves',
    !is_wp_error($save_task($fresh_task)),
);
$configuration_before = $domain_configuration();
wp_cache_set(WP_XP_Core_Achievements::OPTION, $stale_tasks, 'options');
$verify(
    'Task stale-cache fixture actually serves the old value',
    get_option(WP_XP_Core_Achievements::OPTION, null) === $stale_tasks,
);
$fresh_task['description'] = 'Synthetic stale task overwrite attempt';
$stale_result = WP_XP_Core_Achievements::save(
    $fresh_task,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Settings::revision($stale_tasks),
);
$verify(
    'Task CAS reloads storage before comparing a stale cached revision',
    $error($stale_result, 'conflict') &&
        $configuration_before === $domain_configuration() &&
        WP_XP_Core_Achievements::definitions()[$review_task_id]['description'] ===
            'Synthetic newest persisted task description',
);
// Round six: scoped counters share the same conditions in direct and personal reads.
wp_set_current_user($admin->ID);
$category = wp_insert_term('Synthetic round six category', 'category');
$verify('Synthetic category created', !is_wp_error($category));
$scoped_post = wp_insert_post(
    [
        'post_title' => 'Synthetic scoped post',
        'post_status' => 'publish',
        'post_author' => $admin->ID,
        'post_category' => [$category['term_id']],
    ],
    true,
);
$verify('Synthetic scoped post created', !is_wp_error($scoped_post));
$round_six_ids = [];
foreach (['events', 'objects', 'days'] as $mode) {
    $input = $new_task('Synthetic scoped ' . $mode, 'automatic', [
        [
            'kind' => 'visit',
            'count' => 5,
            'mode' => $mode,
            'post_ids' => [$pages[0]],
            'category_ids' => [$category['term_id']],
        ],
    ]);
    $input['period'] = 'month';
    $input['starts_on'] = '2031-02-01';
    $input['ends_on'] = '2031-02-28';
    $saved = $save_task($input);
    $verify('Scoped counter task saves', !is_wp_error($saved));
    $round_six_ids[$mode] = $saved['id'];
}
$GLOBALS['xp_growth_test_day'] = '2031-02-01';
$record_visit = static function (int $pid) use ($reader): void {
    Reader_Experience::transaction(
        static fn() => Reader_Experience::record(
            'synthetic-round-six:' . wp_generate_uuid4(),
            $reader,
            'visit',
            $pid,
            0,
            Reader_Experience::day(),
        ),
    );
};
$record_visit($pages[0]);
$record_visit($pages[0]);
$record_visit($pages[1]); // Outside both lists.
$GLOBALS['xp_growth_test_day'] = '2031-02-28';
$record_visit($scoped_post);
wp_set_current_user($reader);
$personal_six = array_column(WP_XP_Core_Achievements::personal()['tasks'], null, 'id');
foreach (['events' => 3, 'objects' => 2, 'days' => 2] as $mode => $expected) {
    $definition = WP_XP_Core_Achievements::definitions()[$round_six_ids[$mode]];
    $progress = WP_XP_Core_Achievements::progress($definition, $reader);
    $verify(
        'Scoped ' . $mode . ' counts use inclusive dates, OR scope and personal parity',
        $progress['steps'][0]['current'] === $expected &&
            $personal_six[$definition['id']]['steps'][0]['current'] === $expected &&
            $progress['period'] === '2031-02',
    );
}
wp_update_post(['ID' => $scoped_post, 'post_password' => 'synthetic-only']);
$verify(
    'Password protected content stops contributing',
    WP_XP_Core_Achievements::progress($definition, $reader)['steps'][0]['current'] === 1,
);
wp_update_post(['ID' => $scoped_post, 'post_password' => '', 'post_status' => 'private']);
$verify(
    'Private content stops contributing',
    WP_XP_Core_Achievements::progress($definition, $reader)['steps'][0]['current'] === 1,
);
$GLOBALS['xp_growth_test_day'] = '2031-03-01';
$verify(
    'Task expires after inclusive end date',
    !WP_XP_Core_Achievements::unlocked($definition, $reader),
);
$verify(
    'Expired automatic task cannot be claimed',
    $error(
        WP_XP_Core_Achievements::claim($definition['id'], wp_create_nonce('wp_rest')),
        'incomplete',
    ),
);
$GLOBALS['xp_growth_test_day'] = '2031-01-31';
$verify(
    'Task does not open before start date',
    !WP_XP_Core_Achievements::unlocked($definition, $reader),
);
$GLOBALS['xp_growth_test_day'] = '2031-02-01';
wp_set_current_user($admin->ID);
$editable =
    $task_input($definition) +
    array_intersect_key($definition, array_flip(['starts_on', 'ends_on', 'prerequisites']));
$editable['title'] = 'Synthetic renamed task';
$editable['description'] = 'Synthetic clearer instructions';
$editable['icon'] = '◇';
$verify('Display-only edit saves', !is_wp_error($save_task($editable)));
$renamed = WP_XP_Core_Achievements::definitions()[$definition['id']];
$verify(
    'Display-only edit preserves version and event boundary',
    $renamed['version'] === $definition['version'] &&
        $renamed['start_event_id'] === $definition['start_event_id'],
);
$editable['ends_on'] = '2031-03-01';
$verify('Condition edit saves', !is_wp_error($save_task($editable)));
$verify(
    'Date edit creates a new version',
    WP_XP_Core_Achievements::definitions()[$definition['id']]['version'] ===
        $definition['version'] + 1,
);
$dependent_ids = [];
foreach (['current', 'lifetime'] as $scope) {
    $input = $new_task('Synthetic prerequisite ' . $scope, 'review');
    $input['prerequisites'] = [['id' => $automatic_id, 'scope' => $scope]];
    $result = $save_task($input);
    $verify('Prerequisite definition saves', !is_wp_error($result));
    $dependent_ids[$scope] = $result['id'];
    $verify(
        'Prerequisite ' . $scope . ' has explicit version semantics',
        WP_XP_Core_Achievements::unlocked(
            WP_XP_Core_Achievements::definitions()[$result['id']],
            $reader,
        ) ===
            ($scope === 'lifetime'),
    );
}
$cycle = $task_input(WP_XP_Core_Achievements::definitions()[$automatic_id]);
$cycle['prerequisites'] = [['id' => $dependent_ids['lifetime'], 'scope' => 'lifetime']];
$verify('Cross-task prerequisite cycle is rejected', $error($save_task($cycle), 'input'));
$cycle['prerequisites'] = [['id' => 't_0000000000000000', 'scope' => 'lifetime']];
$verify('Unknown prerequisite is rejected', $error($save_task($cycle), 'input'));
foreach (
    [
        ['kind' => 'checkin', 'count' => 1, 'mode' => 'objects'],
        ['kind' => 'visit', 'count' => 1, 'post_ids' => ['1oops']],
        ['kind' => 'visit', 'count' => 1, 'mode' => 'invalid'],
    ]
    as $invalid_step
) {
    $verify(
        'Invalid step fields are rejected',
        $error(
            $save_task($new_task('Synthetic invalid step', 'automatic', [$invalid_step])),
            'input',
        ),
    );
}
$invalid = $new_task('Synthetic invalid date', 'review');
$invalid['starts_on'] = '2031-02-29';
$verify('Invalid calendar date is rejected', $error($save_task($invalid), 'input'));
wp_set_current_user($reader);
$verify(
    'Locked prerequisite review cannot be submitted',
    $error(
        WP_XP_Core_Achievements::submit(
            [
                'task_id' => $dependent_ids['current'],
                'text' => 'Synthetic valid explanation',
                'request_id' => wp_generate_uuid4(),
            ],
            wp_create_nonce('wp_rest'),
        ),
        'locked',
    ),
);
wp_set_current_user($admin->ID);
$dated_review = $new_task('Synthetic date-bound review', 'review');
$dated_review['starts_on'] = '2031-02-01';
$dated_review['ends_on'] = '2031-02-01';
$saved_review = $save_task($dated_review);
$verify('Date-bound review definition saves', !is_wp_error($saved_review));
wp_set_current_user($reader);
$review_request = [
    'task_id' => $saved_review['id'],
    'text' => 'Synthetic review on boundary day',
    'request_id' => wp_generate_uuid4(),
];
$verify(
    'Review submission allowed on both date boundaries',
    !is_wp_error(WP_XP_Core_Achievements::submit($review_request, wp_create_nonce('wp_rest'))),
);
$submitted = $wpdb->get_var(
    $wpdb->prepare(
        'SELECT id FROM ' . $table . ' WHERE event_key=%s',
        'task-submit:' . $reader . ':' . $review_request['request_id'],
    ),
);
$GLOBALS['xp_growth_test_day'] = '2031-02-02';
wp_set_current_user($admin->ID);
$verify(
    'Expired review cannot be approved',
    $error(
        WP_XP_Core_Achievements::review(
            ['submission_id' => (int) $submitted, 'decision' => 'approve', 'feedback' => ''],
            wp_create_nonce('wp_xp_core_achievements'),
        ),
        'changed',
    ),
);
wp_set_current_user($reader);
$review_request['request_id'] = wp_generate_uuid4();
$verify(
    'Expired review cannot accept a new explanation',
    $error(WP_XP_Core_Achievements::submit($review_request, wp_create_nonce('wp_rest')), 'locked'),
);

remove_filter('wp_date', $clock, 10);
unset($GLOBALS['xp_growth_test_day']);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
