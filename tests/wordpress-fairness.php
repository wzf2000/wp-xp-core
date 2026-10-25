<?php
/** First-reward caps, eligibility/privacy and comment compensation on the owned synthetic site. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required.');
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
    'Fairness services and synthetic administrator exist',
    $admin && class_exists('WP_XP_Core_Fairness'),
);
wp_set_current_user($admin->ID);
$make_user = static function (string $name) use ($verify): int {
    $login = 'fixture-fairness-' . $name;
    $verify('Fairness subjects are never reused', !get_user_by('login', $login));
    $uid = wp_insert_user([
        'user_login' => $login,
        'user_email' => $login . '@example.invalid',
        'user_pass' => wp_generate_password(32),
        'role' => 'subscriber',
    ]);
    $verify('Fairness subject created', !is_wp_error($uid));
    return (int) $uid;
};
$reader = $make_user('reader');
$commenter = $make_user('commenter');
$other = $make_user('other');
global $wpdb;
$table = Reader_Experience::table();
$original_rules = WP_XP_Core_Settings::rules();
$save_rules = static fn(array $rules) => WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$balance = static function (int $uid): int {
    wp_cache_delete($uid, 'user_meta');
    return (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
};
$record = static function (int $uid, string $kind, int $xp, string $key = '') use (
    $wpdb,
    $table,
): array {
    $key = $key ?: 'synthetic-fairness:' . wp_generate_uuid4();
    Reader_Experience::transaction(
        static fn() => Reader_Experience::record(
            $key,
            $uid,
            $kind,
            0,
            $xp,
            Reader_Experience::day(),
        ),
    );
    return $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . $table . ' WHERE event_key=%s', $key),
        ARRAY_A,
    );
};
$GLOBALS['xp_fairness_day'] = '2034-04-03';
$clock = static fn($date, $format) => $format === 'Y-m-d' ? $GLOBALS['xp_fairness_day'] : $date;
add_filter('wp_date', $clock, 10, 2);
$verify(
    'Activity caps are disabled by default',
    $original_rules['activity_caps'] === ['day' => 0, 'week' => 0],
);
$old_event = $record($reader, 'visit', 3);
$rules = $original_rules;
$rules['activity_caps'] = ['day' => 5, 'week' => 8];
$verify('Administrator enables bounded gross-first-reward caps', !is_wp_error($save_rules($rules)));
$partial = $record($reader, 'article', 4);
$detail = json_decode($partial['detail'], true);
$verify(
    'Enabling caps counts existing first rewards and truncates to the remaining amount',
    (int) $partial['xp'] === 2 &&
        $balance($reader) === 5 &&
        $detail['activity_cap']['requested_xp'] === 4 &&
        $detail['activity_cap']['daily_gross_before'] === 3 &&
        $detail['activity_cap']['truncated'],
);
$record($reader, 'manual_adjust', -3);
$zero = $record($reader, 'task_complete', 4);
$verify(
    'Negative compensation does not restore cap capacity and leaves a zero-XP event',
    (int) $zero['xp'] === 0 && $balance($reader) === 2,
);
$verify(
    'Existing events retain their original uncapped metadata',
    !isset(json_decode($old_event['detail'], true)['activity_cap']),
);
$GLOBALS['xp_fairness_day'] = '2034-04-04';
$weekly = $record($reader, 'milestone_view', 4);
$verify(
    'Daily and weekly remaining caps use the smaller allowance',
    (int) $weekly['xp'] === 3 && $balance($reader) === 5,
);
$restore = $record($reader, 'comment_adjust', 2);
$verify(
    'Positive restoration is compensation and is not capped or charged again',
    (int) $restore['xp'] === 2 &&
        !isset(json_decode($restore['detail'], true)['activity_cap']) &&
        (int) $record($reader, 'visit', 1)['xp'] === 0,
);
$manual = $record($reader, 'task_grant', 2);
$verify('Administrative task XP is outside native activity caps', (int) $manual['xp'] === 2);
$GLOBALS['xp_fairness_day'] = '2034-04-10';
$verify(
    'A new local week opens a new cap allowance',
    (int) $record($reader, 'visit', 2)['xp'] === 2,
);
foreach (WP_XP_Core_Fairness::FIRST_REWARD_KINDS as $kind) {
    $uid = $make_user($kind);
    $rules['activity_caps'] = ['day' => 1, 'week' => 1];
    $verify('Single-source cap configuration saves', !is_wp_error($save_rules($rules)));
    $verify(
        'Central cap applies to native source ' . $kind,
        (int) $record($uid, $kind, 2)['xp'] === 1,
    );
}
// A zero-XP first event deliberately creates no balance projection; that is implicit zero.
$zero_uid = $make_user('implicit-zero');
$record($zero_uid, 'visit', 0);
$record($zero_uid, 'task_grant', 0);
$summary = WP_XP_Core_Management::user_summary($zero_uid);
$verify(
    'Missing projection with zero ledger including honorary events is consistent without read-time writes',
    $summary['consistent'] &&
        !$summary['balance_present'] &&
        !metadata_exists('user', $zero_uid, Reader_Experience::balance_meta()),
);
$positive_uid = $make_user('missing-positive');
$wpdb->insert($table, [
    'event_key' => 'synthetic-missing-positive',
    'user_id' => $positive_uid,
    'kind' => 'synthetic_balance',
    'object_id' => 0,
    'local_day' => Reader_Experience::day(),
    'xp' => 1,
    'created_at' => time(),
    'detail' => '{}',
]);
$verify(
    'A missing nonzero projection is still inconsistent',
    !WP_XP_Core_Management::user_summary($positive_uid)['consistent'],
);
$wpdb->insert($wpdb->usermeta, [
    'user_id' => $zero_uid,
    'meta_key' => Reader_Experience::balance_meta(),
    'meta_value' => '0',
]);
$wpdb->insert($wpdb->usermeta, [
    'user_id' => $zero_uid,
    'meta_key' => Reader_Experience::balance_meta(),
    'meta_value' => '0',
]);
$verify(
    'Duplicate balance rows remain a diagnostic error',
    !WP_XP_Core_Management::user_summary($zero_uid)['consistent'],
);
// Privacy applies only to public standings, not to private XP or source analytics.
wp_set_current_user($reader);
$privacy = WP_XP_Core_Fairness::personal(wp_create_nonce('wp_rest'));
$optout = WP_XP_Core_Fairness::save_personal(
    ['participating' => false, 'revision' => $privacy['revision']],
    wp_create_nonce('wp_rest'),
);
$verify(
    'Current user can opt out and gets an explicit nonparticipating status',
    !is_wp_error($optout) &&
        $optout['status'] === 'opted_out' &&
        WP_XP_Core_Insights::mine([], wp_create_nonce('wp_rest'))['participation'] === 'opted_out',
);
$verify('Opted-out account has no public position', WP_XP_Core_Insights::rank($reader) === null);
$verify(
    'Participation updates reject target-user fields',
    $error(
        WP_XP_Core_Fairness::save_personal(
            ['participating' => true, 'revision' => $optout['revision'], 'user_id' => $other],
            wp_create_nonce('wp_rest'),
        ),
        'input',
    ),
);
$verify(
    'Participation updates reject stale revisions',
    $error(
        WP_XP_Core_Fairness::save_personal(
            ['participating' => true, 'revision' => $privacy['revision']],
            wp_create_nonce('wp_rest'),
        ),
        'conflict',
    ),
);
wp_set_current_user($admin->ID);
$exclude = [
    'user_id' => $other,
    'excluded' => true,
    'revision' => WP_XP_Core_Fairness::exclusion_revision(),
];
$verify(
    'Administrator can exclude a target account from public standings',
    !is_wp_error(WP_XP_Core_Fairness::exclude($exclude, wp_create_nonce('wp_xp_core_insights'))) &&
        WP_XP_Core_Fairness::status($other) === 'excluded',
);
wp_set_current_user($other);
$verify(
    'Administratively excluded account sees a distinct participation status',
    WP_XP_Core_Fairness::personal(wp_create_nonce('wp_rest'))['status'] === 'excluded',
);
$verify(
    'Subscriber cannot exclude accounts',
    $error(
        WP_XP_Core_Fairness::exclude($exclude, wp_create_nonce('wp_xp_core_insights')),
        'forbidden',
    ),
);
wp_set_current_user($admin->ID);
// Original comment XP is consumed, then moderation needs an atomic full compensation.
$rules = $original_rules;
$rules['activity_caps'] = ['day' => 2, 'week' => 2];
$rules['activities']['comment'] = true;
$rules['comment_xp'] = 2;
$rules['comment_limit'] = 100;
$verify('Comment compensation fixture policy saves', !is_wp_error($save_rules($rules)));
$post = wp_insert_post([
    'post_title' => 'Synthetic fair comment parent',
    'post_author' => $admin->ID,
    'post_status' => 'publish',
]);
$cid = wp_insert_comment([
    'comment_post_ID' => $post,
    'user_id' => $commenter,
    'comment_approved' => '1',
    'comment_content' => 'Synthetic independent comment',
]);
Reader_Experience::comment((int) $cid);
$verify('Original qualifying comment receives its actual XP', $balance($commenter) === 2);
$adjust = static fn(int $amount) => WP_XP_Core_Management::adjust(
    [
        'user_id' => $commenter,
        'xp' => $amount,
        'reason' => 'Synthetic controlled balance',
        'request_id' => wp_generate_uuid4(),
    ],
    wp_create_nonce('wp_xp_core_manage'),
);
$verify(
    'Legitimate manual reduction may consume the comment award',
    !is_wp_error($adjust(-2)) && $balance($commenter) === 0,
);
wp_set_comment_status($cid, 'hold');
$net = static fn() => (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT SUM(xp) FROM ' .
            $table .
            " WHERE object_id=%d AND user_id=%d AND kind IN ('comment','comment_adjust')",
        $cid,
        $commenter,
    ),
);
$verify(
    'Insufficient comment reversal preserves the ledger equation instead of clamping',
    $net() === 2 &&
        $balance($commenter) === 0 &&
        WP_XP_Core_Management::user_summary($commenter)['consistent'],
);
$find_failure = static function () use ($cid): array {
    $rows = WP_XP_Core_Management::failures();
    $found = array_values(
        array_filter(
            $rows,
            static fn($row) => $row['kind'] === 'comment_adjust' &&
                (int) $row['object_id'] === (int) $cid &&
                $row['status'] === 'pending',
        ),
    );
    if (!$found) {
        throw new RuntimeException('Expected pending comment compensation');
    }
    return $found[0];
};
$failure = $find_failure();
$verify(
    'Insufficient compensation has a clear administrator receipt',
    $failure['last_error'] === '当前经验不足，原评论奖励尚未扣回。',
);
$retry = static fn(array $row, string $request) => WP_XP_Core_Management::retry(
    [
        'failure_id' => $row['id'],
        'reason' => 'Synthetic recheck original comment',
        'request_id' => $request,
    ],
    wp_create_nonce('wp_xp_core_manage'),
);
wp_set_comment_status($cid, 'approve');
$verify(
    'Restoring approval after a failed deduction does not duplicate the original award',
    $balance($commenter) === 0 && $net() === 2,
);
$verify(
    'Controlled retry resolves as noop after eligibility has recovered',
    !is_wp_error($retry($failure, wp_generate_uuid4())) &&
        $balance($commenter) === 0 &&
        $net() === 2,
);
wp_set_comment_status($cid, 'hold');
$failure = $find_failure();
$verify(
    'Retry with insufficient balance remains pending and writes no partial compensation',
    $error($retry($failure, wp_generate_uuid4()), 'insufficient_balance') &&
        $net() === 2 &&
        $balance($commenter) === 0,
);
$verify(
    'Top-up restores enough balance for full controlled retry',
    !is_wp_error($adjust(2)) && $balance($commenter) === 2,
);
// A synthetic mismatched failure owner must be rejected before reconciliation can write.
$failure_rows = get_option(WP_XP_Core_Management::FAILURE_OPTION);
$corrupt = $failure_rows;
$corrupt[$failure['id']]['user_id'] = $other;
update_option(WP_XP_Core_Management::FAILURE_OPTION, $corrupt, false);
$count_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
$verify(
    'Mismatched failure ownership cannot write compensation or resolve state',
    $error($retry($failure, wp_generate_uuid4()), 'conflict') &&
        (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === $count_before &&
        get_option(WP_XP_Core_Management::FAILURE_OPTION) === $corrupt &&
        $balance($commenter) === 2,
);
update_option(WP_XP_Core_Management::FAILURE_OPTION, $failure_rows, false);
$request = wp_generate_uuid4();
$retried = $retry($failure, $request);
$verify(
    'Full comment retry and request receipt commit once',
    !is_wp_error($retried) &&
        $balance($commenter) === 0 &&
        $net() === 0 &&
        $retry($failure, $request)['duplicate'],
);
$GLOBALS['xp_fairness_day'] = '2034-04-11';
wp_set_comment_status($cid, 'approve');
$verify(
    'Cross-day restoration uses original actual XP despite exhausted weekly cap',
    $balance($commenter) === 2 && $net() === 2 && (int) $record($commenter, 'visit', 2)['xp'] === 0,
);
wp_set_comment_status($cid, 'hold');
wp_set_comment_status($cid, 'approve');
$verify(
    'Same-day revoke and restore do not consume first-reward cap twice',
    $balance($commenter) === 2 && $net() === 2,
);
$verify(
    'Balance may be consumed again before permanent deletion',
    !is_wp_error($adjust(-2)) && $balance($commenter) === 0,
);
wp_delete_comment($cid, true);
$failure = $find_failure();
$verify(
    'Permanent deletion leaves a bounded captured compensation for controlled retry',
    !get_comment($cid) &&
        $error($retry($failure, wp_generate_uuid4()), 'insufficient_balance') &&
        $net() === 2,
);
$verify(
    'Deleted-comment retry is funded explicitly',
    !is_wp_error($adjust(2)) && $balance($commenter) === 2,
);
$faults = 0;
$fault = static function ($sql) use ($table, &$faults) {
    if (
        str_starts_with($sql, 'INSERT INTO ' . $table . '(') &&
        str_contains($sql, "'management_retry'")
    ) {
        $faults++;
        return 'SELECT * FROM wp_xp_comment_retry_missing_table';
    }
    return $sql;
};
add_filter('query', $fault);
$suppressed = $wpdb->suppress_errors(true);
try {
    $failed = $retry($failure, wp_generate_uuid4());
} finally {
    remove_filter('query', $fault);
    $wpdb->suppress_errors($suppressed);
}
$verify(
    'Receipt failure rolls back comment compensation, balance and resolution together',
    $faults === 1 &&
        $error($failed, 'storage') &&
        $balance($commenter) === 2 &&
        $net() === 2 &&
        $find_failure()['id'] === $failure['id'],
);
wp_set_current_user($commenter);
$verify(
    'Subscriber cannot retry a captured compensation',
    $error($retry($failure, wp_generate_uuid4()), 'forbidden'),
);
wp_set_current_user($admin->ID);
$deny = static function ($caps, $cap, $actor, $args) use ($commenter) {
    return $cap === 'edit_user' && (int) ($args[0] ?? 0) === $commenter ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $deny, 10, 4);
try {
    $verify(
        'Compensation retries enforce target-user permissions',
        $error($retry($failure, wp_generate_uuid4()), 'forbidden'),
    );
} finally {
    remove_filter('map_meta_cap', $deny, 10);
}
update_option(
    'wp_xp_core_test_comment_retry_concurrency',
    [
        'user_id' => $commenter,
        'comment_id' => (int) $cid,
        'failure_id' => $failure['id'],
        'request_id' => wp_generate_uuid4(),
    ],
    false,
);
$partial_commenter = $make_user('partial-comment');
$rules['activity_caps'] = ['day' => 1, 'week' => 1];
$verify('Truncated comment policy saves', !is_wp_error($save_rules($rules)));
$partial_cid = wp_insert_comment([
    'comment_post_ID' => $post,
    'user_id' => $partial_commenter,
    'comment_approved' => '1',
    'comment_content' => 'Synthetic partially capped comment',
]);
Reader_Experience::comment((int) $partial_cid);
$verify('Comment stores its actual truncated original award', $balance($partial_commenter) === 1);
wp_set_comment_status($partial_cid, 'hold');
$verify('Capped comment reversal uses actual original XP', $balance($partial_commenter) === 0);
wp_set_comment_status($partial_cid, 'approve');
$verify(
    'Capped comment restoration uses actual original XP and bypasses cap',
    $balance($partial_commenter) === 1 && (int) $record($partial_commenter, 'visit', 1)['xp'] === 0,
);
$verify(
    'Original settings can be restored without paying or reclaiming XP',
    !is_wp_error($save_rules($original_rules)),
);
// More than a page of malformed submissions must not hide a valid pending explanation.
$pending_task = [
    'id' => '',
    'title' => 'Synthetic pending beyond malformed rows',
    'description' => '',
    'icon' => '',
    'type' => 'review',
    'period' => 'once',
    'enabled' => true,
    'steps' => [],
];
$created = WP_XP_Core_Achievements::save(
    $pending_task,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$verify('Synthetic pending pagination task created', !is_wp_error($created));
wp_set_current_user($other);
$submission_uuid = wp_generate_uuid4();
$verify(
    'Valid pending explanation precedes malformed rows',
    !is_wp_error(
        WP_XP_Core_Achievements::submit(
            [
                'task_id' => $created['id'],
                'text' => 'Synthetic valid completion explanation',
                'request_id' => $submission_uuid,
            ],
            wp_create_nonce('wp_rest'),
        ),
    ),
);
$valid_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT id FROM ' . $table . ' WHERE event_key=%s',
        'task-submit:' . $other . ':' . $submission_uuid,
    ),
);
for ($i = 0; $i < 27; $i++) {
    Reader_Experience::transaction(
        static fn() => Reader_Experience::record(
            'synthetic-incomplete-page:' . $i,
            $other,
            'achievement_submit',
            0,
            0,
            Reader_Experience::day(),
            ['task_id' => $created['id'], 'version' => 1, 'period' => 'once'],
        ),
    );
}
wp_set_current_user($admin->ID);
$pending = WP_XP_Core_Achievements::pending();
$verify(
    'SQL validity filtering exposes legal pending rows beyond a page of malformed entries',
    !is_wp_error($pending) && in_array($valid_id, array_column($pending['items'], 'id'), true),
);
$trend = WP_XP_Core_Insights::trends([
    'period' => 'custom',
    'from' => '2034-04-10',
    'to' => '2034-04-11',
]);
$verify(
    'Trend comparison uses two equal consecutive windows with exact unique participants',
    !is_wp_error($trend) &&
        $trend['days'] === 2 &&
        $trend['previous']['from'] === '2034-04-08' &&
        $trend['previous']['to'] === '2034-04-09',
);
$verify(
    'Admin anomaly clues and version history remain bounded read-only services',
    !is_wp_error(WP_XP_Core_Insights::anomalies(['period' => 'month'])) &&
        !is_wp_error(WP_XP_Core_Insights::task_history(['period' => 'month'])),
);
wp_set_current_user($other);
$verify(
    'Private analytical services enforce administration permissions',
    $error(WP_XP_Core_Insights::trends(), 'forbidden') &&
        $error(WP_XP_Core_Insights::anomalies(), 'forbidden') &&
        $error(WP_XP_Core_Insights::task_history(), 'forbidden'),
);
wp_set_current_user($admin->ID);
remove_filter('wp_date', $clock, 10);
unset($GLOBALS['xp_fairness_day']);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
