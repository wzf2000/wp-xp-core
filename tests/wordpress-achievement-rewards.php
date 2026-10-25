<?php
/** Optional task XP and append-only honors, only on the owned synthetic database. */
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
$error = static fn($v, string $code) => is_wp_error($v) && $v->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
$verify(
    'Synthetic rewards administrator exists',
    $admin && class_exists('WP_XP_Core_Achievements'),
);
wp_set_current_user($admin->ID);
$verify(
    'Rewards fixture does not reuse a subject',
    !get_user_by('login', 'fixture-achievement-rewards'),
);
$reader = wp_insert_user([
    'user_login' => 'fixture-achievement-rewards',
    'user_pass' => wp_generate_password(32),
    'user_email' => 'achievement-rewards@example.invalid',
    'role' => 'subscriber',
]);
$verify('Synthetic rewards subject created', !is_wp_error($reader));
$reader = (int) $reader;
$clock = static fn($date, $format) => $format === 'Y-m-d' ? '2033-06-15' : $date;
add_filter('wp_date', $clock, 10, 2);
global $wpdb;
$table = Reader_Experience::table();
$balance = static function () use ($reader): int {
    wp_cache_delete($reader, 'user_meta');
    return (int) get_user_meta($reader, Reader_Experience::balance_meta(), true);
};
$save = static fn(array $task) => WP_XP_Core_Achievements::save(
    $task,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$manage = static fn(array $input) => WP_XP_Core_Achievements::manage(
    $input,
    wp_create_nonce('wp_xp_core_achievements'),
);
$input = static fn(string $title, string $type, array $steps = []): array => [
    'id' => '',
    'title' => $title,
    'description' => 'Synthetic task conditions',
    'icon' => '★',
    'type' => $type,
    'period' => 'month',
    'enabled' => true,
    'steps' => $steps,
];
$public = wp_insert_post([
    'post_title' => 'Synthetic reward reading',
    'post_status' => 'publish',
    'post_author' => $admin->ID,
]);
$upload = wp_upload_bits(
    'xp-round-seven.png',
    null,
    base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j+XcAAAAASUVORK5CYII=',
    ),
);
$verify('Synthetic image upload succeeds', !$upload['error']);
$image = wp_insert_attachment(
    [
        'post_title' => 'Synthetic achievement badge',
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
    ],
    $upload['file'],
    $public,
    true,
);
$verify(
    'Public image is available for badge selection',
    !is_wp_error($image) && WP_XP_Core_Achievements::public_image((int) $image),
);
$automatic = $input('Synthetic paid automatic honor', 'automatic', [
    ['kind' => 'visit', 'count' => 1, 'post_ids' => [$public]],
]);
$automatic['reward_xp'] = 7;
$automatic['image_id'] = (int) $image;
$automatic['category'] = 'Reading';
$automatic['series'] = 'Synthetic series';
$saved = $save($automatic);
$verify('Optional task reward and badge metadata save', !is_wp_error($saved));
$automatic_id = $saved['id'];
$automatic = WP_XP_Core_Achievements::definitions()[$automatic_id];
$visit_key = 'synthetic-task-xp:' . wp_generate_uuid4();
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        $visit_key,
        $reader,
        'visit',
        $public,
        0,
        Reader_Experience::day(),
    ),
);
$complete = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT * FROM ' . $table . ' WHERE event_key=%s',
        'task:' . $automatic_id . ':1:2033-06:' . $reader,
    ),
    ARRAY_A,
);
$verify(
    'Automatic achievement and exact XP are committed together',
    $complete && (int) $complete['xp'] === 7 && $balance() === 7,
);
wp_set_current_user($reader);
$claim = WP_XP_Core_Achievements::claim($automatic_id, wp_create_nonce('wp_rest'));
$verify(
    'Duplicate task claim does not repeat XP',
    !is_wp_error($claim) && $claim['duplicate'] && $balance() === 7,
);
$personal = WP_XP_Core_Achievements::personal();
$badge = array_values(
    array_filter(
        $personal['badges'],
        static fn($b) => $b['title'] === 'Synthetic paid automatic honor',
    ),
)[0];
$verify(
    'Badge exposes condition, award time, category, series and safe image',
    $badge['reward_xp'] === 7 &&
        $badge['earned_at'] > 0 &&
        $badge['category'] === 'Reading' &&
        $badge['series'] === 'Synthetic series' &&
        $badge['condition_text'] !== '' &&
        $badge['image_url'] !== '',
);
wp_set_current_user($admin->ID);
$edit = array_intersect_key(
    $automatic,
    $input('', 'review') +
        array_flip([
            'reward_xp',
            'image_id',
            'category',
            'series',
            'starts_on',
            'ends_on',
            'prerequisites',
        ]),
);
$edit['title'] = 'Synthetic renamed honor';
$edit['category'] = 'Changed category';
$verify(
    'Display edit preserves task progress',
    !is_wp_error($save($edit)) &&
        WP_XP_Core_Achievements::definitions()[$automatic_id]['version'] === 1,
);
wp_set_current_user($reader);
$verify(
    'Earned snapshot survives display edits',
    WP_XP_Core_Achievements::personal()['badges'][0]['title'] ===
        'Synthetic paid automatic honor' &&
        WP_XP_Core_Achievements::personal()['badges'][0]['category'] === 'Reading',
);
wp_update_post(['ID' => $public, 'post_status' => 'private']);
$verify(
    'Private parent prevents exposing image URL even to logged-in owner',
    WP_XP_Core_Achievements::personal()['badges'][0]['image_url'] === '',
);
wp_set_current_user($admin->ID);
$verify('Private badge image cannot be selected for new tasks', $error($save($edit), 'input'));
wp_update_post(['ID' => $public, 'post_status' => 'publish']);
$verify(
    'Public parent restores the safe image',
    WP_XP_Core_Achievements::image_url((int) $image) !== '',
);
$grant_task = $input('Synthetic admin grant', 'review');
$grant_task['reward_xp'] = 30;
$saved_grant = $save($grant_task);
$verify('Administrative grant task saves', !is_wp_error($saved_grant));
$grant = [
    'operation' => 'grant',
    'request_id' => wp_generate_uuid4(),
    'reason' => 'Synthetic independently verified completion',
    'user_id' => $reader,
    'task_id' => $saved_grant['id'],
    'version' => 1,
    'period' => '2033-06',
];
wp_set_current_user($reader);
$verify('Subscribers cannot administratively grant honors', $error($manage($grant), 'forbidden'));
wp_set_current_user($admin->ID);
$verify(
    'Administrative grants require a valid nonce',
    $error(WP_XP_Core_Achievements::manage($grant, 'invalid'), 'nonce'),
);
$invalid_grant = $grant;
$invalid_grant['version'] = 2;
$verify(
    'Administrative grants reject unknown task versions',
    $error($manage($invalid_grant), 'changed'),
);
$invalid_grant = $grant;
$invalid_grant['period'] = '2033-05';
$verify('Administrative grants reject stale periods', $error($manage($invalid_grant), 'changed'));
$invalid_grant = $grant;
$invalid_grant['reason'] = '';
$verify('Administrative grants require a reason', $error($manage($invalid_grant), 'input'));
$invalid_grant = $grant;
$invalid_grant['reward_xp'] = 1000;
$verify('Caller cannot override the task reward', $error($manage($invalid_grant), 'input'));
$deny_target = static function ($caps, $cap, $actor, $args) use ($reader) {
    return $cap === 'edit_user' && (int) ($args[0] ?? 0) === $reader ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $deny_target, 10, 4);
try {
    $verify('Task grants enforce target-user permissions', $error($manage($grant), 'forbidden'));
} finally {
    remove_filter('map_meta_cap', $deny_target, 10);
}
$granted = $manage($grant);
$verify(
    'Administrative grant uses its own kind and exact configured XP',
    !is_wp_error($granted) &&
        $balance() === 37 &&
        $wpdb->get_var(
            $wpdb->prepare('SELECT kind FROM ' . $table . ' WHERE id=%d', $granted['event_id']),
        ) === 'task_grant',
);
$verify('Identical grant request is idempotent', $manage($grant)['duplicate'] && $balance() === 37);
$changed_request = $grant;
$changed_request['reason'] = 'Synthetic different request payload';
$verify(
    'Request UUID cannot be reused with different content',
    $error($manage($changed_request), 'conflict'),
);
$new_request = $grant;
$new_request['request_id'] = wp_generate_uuid4();
$verify(
    'Different requests cannot grant the same task cycle twice',
    $manage($new_request)['duplicate'] && $balance() === 37,
);
$rank = WP_XP_Core_Insights::rank($reader, 'month');
$verify(
    'Automatic XP enters activity standings while administrative grant XP is excluded',
    !is_wp_error($rank) && $rank['xp'] === 7,
);
$sources = WP_XP_Core_Insights::sources(['period' => 'month']);
$kinds = array_column($sources['rows'], null, 'kind');
$verify(
    'Automatic and administrative XP are separate source rows',
    $kinds['task_complete']['positive_xp'] === 7 && $kinds['task_grant']['positive_xp'] === 30,
);
$dependent = $input('Synthetic prerequisite of paid task', 'review');
$dependent['prerequisites'] = [['id' => $automatic_id, 'scope' => 'lifetime']];
$saved_dependent = $save($dependent);
$verify(
    'Paid honor satisfies a lifetime prerequisite before revocation',
    !is_wp_error($saved_dependent) &&
        WP_XP_Core_Achievements::unlocked(
            WP_XP_Core_Achievements::definitions()[$saved_dependent['id']],
            $reader,
        ),
);
$downstream_grant = $grant;
$downstream_grant['task_id'] = $saved_dependent['id'];
$downstream_grant['request_id'] = wp_generate_uuid4();
$verify(
    'Downstream honor can be granted while its prerequisite is valid',
    !is_wp_error($manage($downstream_grant)),
);
$revoke = [
    'operation' => 'revoke',
    'request_id' => wp_generate_uuid4(),
    'reason' => 'Synthetic original evidence withdrawn',
    'event_id' => (int) $complete['id'],
];
$revoked = $manage($revoke);
$verify(
    'Revocation atomically deducts original XP and preserves original event',
    !is_wp_error($revoked) &&
        $balance() === 30 &&
        (int) $wpdb->get_var(
            $wpdb->prepare('SELECT xp FROM ' . $table . ' WHERE id=%d', $complete['id']),
        ) === 7,
);
$verify(
    'Revoked honor stops satisfying lifetime prerequisites',
    !WP_XP_Core_Achievements::unlocked(
        WP_XP_Core_Achievements::definitions()[$saved_dependent['id']],
        $reader,
    ),
);
$verify(
    'Revoking a prerequisite never cascades into existing downstream honors',
    WP_XP_Core_Achievements::progress(
        WP_XP_Core_Achievements::definitions()[$saved_dependent['id']],
        $reader,
    )['earned'],
);
$verify('Revocation request is idempotent', $manage($revoke)['duplicate'] && $balance() === 30);
wp_set_current_user($reader);
$progress = WP_XP_Core_Achievements::progress(
    WP_XP_Core_Achievements::definitions()[$automatic_id],
    $reader,
);
$verify(
    'Revoked honor is absent from earned progress and badge list',
    !$progress['earned'] &&
        $progress['revoked'] &&
        count(WP_XP_Core_Achievements::personal()['badges']) === 2,
);
$verify(
    'Revoked cycle cannot be reclaimed',
    $error(WP_XP_Core_Achievements::claim($automatic_id, wp_create_nonce('wp_rest')), 'revoked'),
);
wp_set_current_user($admin->ID);
$rates = array_column(WP_XP_Core_Insights::task_rates()['rows'], null, 'id');
$verify(
    'Completion rates exclude revoked honors',
    $rates[$automatic_id]['completed'] === 0 && $rates[$saved_grant['id']]['completed'] === 1,
);
$rank = WP_XP_Core_Insights::rank($reader, 'month');
$verify('Automatic reward revocation offsets activity standings', $rank === null);
$grant_revoked = $grant;
$grant_revoked['task_id'] = $automatic_id;
$grant_revoked['request_id'] = wp_generate_uuid4();
$verify('Administrator cannot regrant a revoked cycle', $error($manage($grant_revoked), 'revoked'));
// Spending XP through the existing append-only adjustment makes a later revoke unsafe.
$adjust = WP_XP_Core_Management::adjust(
    [
        'user_id' => $reader,
        'xp' => -25,
        'reason' => 'Synthetic balance reduction',
        'request_id' => wp_generate_uuid4(),
    ],
    wp_create_nonce('wp_xp_core_manage'),
);
$verify(
    'Synthetic reduction leaves insufficient balance',
    !is_wp_error($adjust) && $balance() === 5,
);
$revoke_grant = [
    'operation' => 'revoke',
    'request_id' => wp_generate_uuid4(),
    'reason' => 'Synthetic grant withdrawal',
    'event_id' => $granted['event_id'],
];
$before_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
$verify(
    'Insufficient balance rejects honor and XP revocation together',
    $error($manage($revoke_grant), 'balance') &&
        $balance() === 5 &&
        (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === $before_count,
);
wp_set_current_user($reader);
$verify(
    'Failed revocation leaves the original badge valid',
    count(WP_XP_Core_Achievements::personal()['badges']) === 2,
);
wp_set_current_user($admin->ID);
// Force a failure after the award insert, before its administrator request receipt commits.
$rollback_task = $input('Synthetic rollback grant', 'review');
$rollback_task['reward_xp'] = 9;
$rollback_saved = $save($rollback_task);
$rollback_grant = $grant;
$rollback_grant['task_id'] = $rollback_saved['id'];
$rollback_grant['request_id'] = wp_generate_uuid4();
$faults = 0;
$fail_receipt = static function ($sql) use ($table, &$faults) {
    if (
        str_starts_with(ltrim($sql), 'INSERT INTO ' . $table . '(') &&
        str_contains($sql, "'achievement_admin'")
    ) {
        $faults++;
        return 'SELECT * FROM wp_xp_round_seven_missing_table';
    }
    return $sql;
};
add_filter('query', $fail_receipt);
$old_suppress = $wpdb->suppress_errors(true);
try {
    $failed = $manage($rollback_grant);
} finally {
    remove_filter('query', $fail_receipt);
    $wpdb->suppress_errors($old_suppress);
}
$verify(
    'Failure after grant rolls back honor and balance',
    $faults === 1 &&
        $error($failed, 'storage') &&
        $balance() === 5 &&
        !WP_XP_Core_Achievements::progress(
            WP_XP_Core_Achievements::definitions()[$rollback_saved['id']],
            $reader,
        )['earned'],
);
$verify(
    'Same request retries after a rolled-back grant',
    !is_wp_error($manage($rollback_grant)) && $balance() === 14,
);
// Reward edits start a future version and never retroactively pay an existing achievement.
$edit['image_id'] = 0;
$edit['reward_xp'] = 11;
$verify(
    'Reward edit starts a new version without paying old honor',
    !is_wp_error($save($edit)) &&
        WP_XP_Core_Achievements::definitions()[$automatic_id]['version'] === 2 &&
        $balance() === 14,
);
$legacy = $input('Synthetic legacy zero honor', 'review');
$legacy_saved = $save($legacy);
$legacy_grant = $grant;
$legacy_grant['task_id'] = $legacy_saved['id'];
$legacy_grant['request_id'] = wp_generate_uuid4();
$legacy_result = $manage($legacy_grant);
$verify(
    'Task without reward_xp remains a zero-XP honor',
    !is_wp_error($legacy_result) && $balance() === 14,
);
$legacy_revoke = $revoke;
$legacy_revoke['event_id'] = $legacy_result['event_id'];
$legacy_revoke['request_id'] = wp_generate_uuid4();
$verify(
    'Zero-XP honor can be revoked without changing balance',
    !is_wp_error($manage($legacy_revoke)) && $balance() === 14,
);
$historic_key = 'task:t_7777777777777777:1:once:' . $reader;
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        $historic_key,
        $reader,
        'task_complete',
        0,
        0,
        Reader_Experience::day(),
        [
            'task_id' => 't_7777777777777777',
            'version' => 1,
            'period' => 'once',
            'title' => 'Synthetic historic zero honor',
            'icon' => '★',
        ],
    ),
);
$historic_id = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT id FROM ' . $table . ' WHERE event_key=%s', $historic_key),
);
wp_set_current_user($reader);
$historic_badges = array_column(WP_XP_Core_Achievements::personal()['badges'], null, 'title');
$verify(
    'Legacy honor snapshots without new metadata remain readable',
    isset($historic_badges['Synthetic historic zero honor']) &&
        $historic_badges['Synthetic historic zero honor']['reward_xp'] === 0 &&
        $historic_badges['Synthetic historic zero honor']['image_url'] === '',
);
wp_set_current_user($admin->ID);
$historic_revoke = $revoke;
$historic_revoke['event_id'] = $historic_id;
$historic_revoke['request_id'] = wp_generate_uuid4();
$verify(
    'Legacy zero-XP honor can be revoked without a current task definition',
    !is_wp_error($manage($historic_revoke)) && $balance() === 14,
);
$malformed_key = 'synthetic-incomplete-submission:' . wp_generate_uuid4();
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        $malformed_key,
        $reader,
        'achievement_submit',
        0,
        0,
        Reader_Experience::day(),
        ['task_id' => $legacy_saved['id'], 'version' => 1, 'period' => '2033-06'],
    ),
);
$malformed_id = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT id FROM ' . $table . ' WHERE event_key=%s', $malformed_key),
);
set_error_handler(static function ($severity, $message) {
    throw new ErrorException($message, 0, $severity);
});
try {
    $pending = WP_XP_Core_Achievements::pending();
    $malformed_review = WP_XP_Core_Achievements::review(
        ['submission_id' => $malformed_id, 'decision' => 'approve', 'feedback' => ''],
        wp_create_nonce('wp_xp_core_achievements'),
    );
    $verify(
        'Incomplete historical submissions are skipped without warnings and cannot be approved',
        !is_wp_error($pending) &&
            !in_array($malformed_id, array_column($pending['items'], 'id'), true) &&
            $error($malformed_review, 'input'),
    );
} finally {
    restore_error_handler();
}
// Overflow must fail before either the honor or the projection is written.
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'synthetic-task-overflow-up',
        $reader,
        'synthetic_balance',
        0,
        PHP_INT_MAX - 14,
        Reader_Experience::day(),
    ),
);
$overflow_grant = $grant;
$overflow_grant['request_id'] = wp_generate_uuid4();
$overflow_grant['task_id'] = $automatic_id;
$overflow_grant['version'] = 2;
$overflow_result = $manage($overflow_grant);
$verify(
    'Task reward rejects integer overflow without changing the maximum balance',
    $error($overflow_result, 'storage') &&
        $balance() === PHP_INT_MAX &&
        !WP_XP_Core_Achievements::progress(
            WP_XP_Core_Achievements::definitions()[$automatic_id],
            $reader,
        )['earned'],
);
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'synthetic-task-overflow-down',
        $reader,
        'synthetic_balance',
        0,
        -(PHP_INT_MAX - 14),
        Reader_Experience::day(),
    ),
);
$concurrent = $input('Synthetic concurrent honor', 'review');
$concurrent['reward_xp'] = 5;
$concurrent_saved = $save($concurrent);
$verify('Independent concurrency task is prepared', !is_wp_error($concurrent_saved));
update_option(
    'wp_xp_core_test_achievement_concurrency',
    [
        'task_id' => $concurrent_saved['id'],
        'user_id' => $reader,
        'before' => $balance(),
        'request_id' => wp_generate_uuid4(),
    ],
    false,
);
wp_delete_attachment((int) $image, true);
$verify(
    'Deleted image falls back without exposing a stale URL',
    WP_XP_Core_Achievements::image_url((int) $image) === '',
);
remove_filter('wp_date', $clock, 10);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
