<?php
/** Private durable inbox and next goals on the explicitly isolated database. */
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
    'Notification module and administrator available',
    $admin && class_exists('WP_XP_Core_Notifications'),
);
$users = [];
foreach (['reader', 'other'] as $name) {
    $login = 'fixture-notification-' . $name;
    $verify('Inbox test never reuses subjects', !get_user_by('login', $login));
    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32),
        'user_email' => $login . '@example.invalid',
        'role' => 'subscriber',
    ]);
    $verify('Inbox synthetic subject created', !is_wp_error($id));
    $users[] = (int) $id;
}
[$reader, $other] = $users;
global $wpdb;
$table = Reader_Experience::table();
$balance = static function (int $uid): int {
    wp_cache_delete($uid, 'user_meta');
    return (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
};
$listing = static fn(array $input = []) => WP_XP_Core_Notifications::listing(
    $input,
    wp_create_nonce('wp_rest'),
);
$save_state = static fn(array $input) => WP_XP_Core_Notifications::save(
    $input,
    wp_create_nonce('wp_rest'),
);
$save_task = static fn(array $input) => WP_XP_Core_Achievements::save(
    $input,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$task_input = static fn(string $title, string $type, array $steps = []): array => [
    'id' => '',
    'title' => $title,
    'description' => 'Synthetic inbox task',
    'icon' => '★',
    'enabled' => true,
    'type' => $type,
    'period' => 'once',
    'steps' => $steps,
];
wp_set_current_user($reader);
$count_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
$meta_before = get_user_meta($reader);
$empty = $listing();
$verify(
    'Empty inbox reads create no ledger or user metadata',
    !is_wp_error($empty) &&
        $empty['items'] === [] &&
        $meta_before === get_user_meta($reader) &&
        $count_before === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table),
);
$verify(
    'Invalid nonce rejects notification reads',
    $error(WP_XP_Core_Notifications::listing([], 'invalid'), 'nonce'),
);
foreach (
    [
        ['user_id' => $other],
        ['limit' => 51],
        ['cursor' => []],
        ['cursor' => '0:task'],
        ['cursor' => '999999999999999999999999:task'],
    ]
    as $invalid
) {
    $verify(
        'Notification query rejects malformed fields and pagination',
        $error($listing($invalid), 'input'),
    );
}
wp_set_current_user($admin->ID);
$task = $task_input('Synthetic recoverable honor', 'review');
$task['reward_xp'] = 25;
$created = $save_task($task);
$verify('Recoverable notification task saved', !is_wp_error($created));
$throw_observer = static function () {
    throw new RuntimeException('Synthetic lost observer');
};
add_action('wp_xp_core_event_committed', $throw_observer, -100);
try {
    $granted = WP_XP_Core_Achievements::manage(
        [
            'operation' => 'grant',
            'request_id' => wp_generate_uuid4(),
            'reason' => 'Synthetic verified completion',
            'user_id' => $reader,
            'task_id' => $created['id'],
            'version' => 1,
            'period' => 'once',
        ],
        wp_create_nonce('wp_xp_core_achievements'),
    );
} finally {
    remove_action('wp_xp_core_event_committed', $throw_observer, -100);
}
$verify(
    'Lost event observers cannot roll back committed honor and XP',
    !is_wp_error($granted) && $balance($reader) === 25,
);
wp_set_current_user($reader);
$first = $listing(['limit' => 1]);
$second = $listing(['limit' => 1, 'cursor' => $first['next_cursor']]);
$verify(
    'Task and genuine level increase recover directly from durable events',
    $first['items'][0]['type'] === 'task' &&
        $second['items'][0]['type'] === 'level' &&
        $first['items'][0]['id'] !== $second['items'][0]['id'],
);
$verify(
    'Inbox pagination stops after the last notification from one event',
    $second['next_cursor'] === null,
);
$complete_list = $listing();
$read = $save_state([
    'revision' => $complete_list['revision'],
    'ids' => [$first['items'][0]['id']],
]);
$verify(
    'Only the selected notification becomes read',
    !is_wp_error($read) && $listing()['items'][0]['read'] && !$listing()['items'][1]['read'],
);
$verify(
    'Old revision cannot overwrite read state or preferences',
    $error(
        $save_state([
            'revision' => $complete_list['revision'],
            'preferences' => ['task' => false, 'review' => true, 'level' => true],
        ]),
        'conflict',
    ),
);
$current = $listing();
$all_read = $save_state(['revision' => $current['revision'], 'through' => $current['through']]);
$verify(
    'Mark all read uses an explicit observed boundary',
    !is_wp_error($all_read) && !array_filter($listing()['items'], static fn($m) => !$m['read']),
);
$current = $listing();
$hidden = $save_state([
    'revision' => $current['revision'],
    'preferences' => ['task' => true, 'review' => true, 'level' => false],
]);
$verify(
    'Disabling one type hides that type without deleting notification facts',
    !is_wp_error($hidden) && array_column($listing()['items'], 'type') === ['task'],
);
$current = $listing();
$verify(
    'Invalid notification settings do not change state',
    $error(
        $save_state([
            'revision' => $current['revision'],
            'preferences' => ['task' => 'false', 'review' => true, 'level' => true],
        ]),
        'input',
    ) && $listing()['revision'] === $current['revision'],
);
$verify(
    'Array notification IDs are rejected as invalid input',
    $error($save_state(['revision' => $current['revision'], 'ids' => [[]]]), 'input'),
);
$verify(
    'Future read boundaries are rejected',
    $error($save_state(['revision' => $current['revision'], 'through' => PHP_INT_MAX]), 'input'),
);
wp_set_current_user($other);
$other_state = $listing();
$verify(
    'Other user sees no notifications or preference leakage',
    $other_state['items'] === [] && $other_state['preferences']['level'],
);
$verify(
    'Other user cannot mark a foreign notification read',
    $error(
        $save_state(['revision' => $other_state['revision'], 'ids' => [$first['items'][0]['id']]]),
        'missing',
    ),
);
wp_set_current_user($reader);
$restore_preferences = $listing();
$verify(
    'Reenabling a type preserves old notification IDs and read state',
    !is_wp_error(
        $save_state([
            'revision' => $restore_preferences['revision'],
            'preferences' => ['task' => true, 'review' => true, 'level' => true],
        ]),
    ) &&
        $listing()['items'][1]['id'] === $second['items'][0]['id'] &&
        $listing()['items'][1]['read'],
);
$inbox_before = $listing();
// Ordinary zero-XP activity and level decreases are not notification spam.
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'synthetic-inbox-visit',
        $reader,
        'visit',
        0,
        0,
        Reader_Experience::day(),
    ),
);
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'synthetic-inbox-decrease',
        $reader,
        'manual_adjust',
        0,
        -1,
        Reader_Experience::day(),
    ),
);
$verify(
    'Ordinary reading and non-increases generate no notification',
    array_column($listing()['items'], 'id') === array_column($inbox_before['items'], 'id'),
);
wp_set_current_user($admin->ID);
$review_created = $save_task($task_input('Synthetic review response', 'review'));
wp_set_current_user($reader);
$submit_request = wp_generate_uuid4();
$submitted = WP_XP_Core_Achievements::submit(
    [
        'task_id' => $review_created['id'],
        'text' => 'Synthetic review explanation',
        'request_id' => $submit_request,
    ],
    wp_create_nonce('wp_rest'),
);
$verify('Synthetic review submission accepted', !is_wp_error($submitted));
$submission_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT id FROM ' . $table . ' WHERE event_key=%s',
        'task-submit:' . $reader . ':' . $submit_request,
    ),
);
wp_set_current_user($admin->ID);
$verify(
    'Review rejection commits independently of inbox reads',
    !is_wp_error(
        WP_XP_Core_Achievements::review(
            [
                'submission_id' => $submission_id,
                'decision' => 'reject',
                'feedback' => 'Synthetic feedback to current user',
            ],
            wp_create_nonce('wp_xp_core_achievements'),
        ),
    ),
);
wp_set_current_user($reader);
$messages = $listing()['items'];
$verify(
    'Private review feedback appears only in its recipient inbox',
    $messages[0]['type'] === 'review' &&
        $messages[0]['message'] === 'Synthetic feedback to current user',
);
$inbox_count = count($messages);
$before_balance = $balance($reader);
$fault = static fn($sql) => str_contains($sql, "$.notification_schema") &&
str_starts_with($sql, 'SELECT ')
    ? 'SELECT * FROM wp_xp_inbox_missing_table'
    : $sql;
add_filter('query', $fault);
$suppress = $wpdb->suppress_errors(true);
try {
    $failed_listing = $listing();
} finally {
    remove_filter('query', $fault);
    $wpdb->suppress_errors($suppress);
}
$verify(
    'Inbox storage failure changes neither committed balance nor notification facts',
    $error($failed_listing, 'storage') &&
        $balance($reader) === $before_balance &&
        count($listing()['items']) === $inbox_count,
);
wp_set_current_user($admin->ID);
$verify(
    'Existing balance safely supports a zero-XP review award',
    !is_wp_error($save_task($task_input('Synthetic extra private task', 'review'))),
);
$public = wp_insert_post([
    'post_title' => 'Synthetic visible next goal',
    'post_status' => 'publish',
    'post_author' => $admin->ID,
]);
$private = wp_insert_post([
    'post_title' => 'PRIVATE INBOX CONTENT MUST NOT LEAK',
    'post_status' => 'private',
    'post_author' => $admin->ID,
]);
$scoped = $task_input('Synthetic next target', 'automatic', [
    ['kind' => 'visit', 'count' => 3, 'mode' => 'days', 'post_ids' => [$public, $private]],
]);
$scoped['prerequisites'] = [['id' => $created['id'], 'scope' => 'lifetime']];
$scoped_created = $save_task($scoped);
$verify('Synthetic scoped next goal saves', !is_wp_error($scoped_created));
wp_set_current_user($reader);
$tasks = array_column(WP_XP_Core_Achievements::personal()['tasks'], null, 'id');
$target = $tasks[$scoped_created['id']];
$verify(
    'Next goals explain counting mode, public scope links and prerequisites',
    $target['steps'][0]['mode'] === 'days' &&
        count($target['steps'][0]['scope_links']) === 1 &&
        $target['steps'][0]['scope_links'][0]['title'] === 'Synthetic visible next goal' &&
        $target['prerequisites'][0]['complete'] &&
        !str_contains(wp_json_encode($target), 'PRIVATE INBOX CONTENT MUST NOT LEAK'),
);
// An event arriving after the observed boundary must remain unread by mark-all.
$injected = false;
$inject_notification = static function ($sql) use (&$injected, $reader) {
    if (
        !$injected &&
        str_starts_with($sql, 'SELECT ') &&
        str_contains($sql, '$.notification_schema')
    ) {
        $injected = true;
        Reader_Experience::transaction(
            static fn() => Reader_Experience::record(
                'synthetic-inbox-later-review',
                $reader,
                'achievement_review',
                0,
                0,
                Reader_Experience::day(),
                ['decision' => 'reject', 'feedback' => 'Synthetic later message'],
            ),
        );
    }
    return $sql;
};
add_filter('query', $inject_notification);
try {
    $observed = $listing();
} finally {
    remove_filter('query', $inject_notification);
}
$verify(
    'Inbox response excludes messages arriving after its observed boundary',
    $injected &&
        !in_array('Synthetic later message', array_column($observed['items'], 'message'), true),
);
$verify(
    'Mark-all-read never consumes a later unseen notification',
    !is_wp_error(
        $save_state(['revision' => $observed['revision'], 'through' => $observed['through']]),
    ) &&
        $listing()['items'][0]['message'] === 'Synthetic later message' &&
        !$listing()['items'][0]['read'],
);
// Exercise the real route permission and private-cache hook for all configured namespaces.
foreach (
    array_unique(
        array_merge(
            [reader_experience_config('rest_namespace')],
            reader_experience_config('rest_aliases'),
        ),
    )
    as $namespace
) {
    $request = new WP_REST_Request('POST', '/' . $namespace . '/notifications');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_body('{}');
    $response = rest_do_request($request);
    $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
    $verify(
        'Notification REST responses are private and never shared-cacheable',
        $response->get_status() === 200 &&
            ($response->get_headers()['Cache-Control'] ?? '') === 'private, no-store',
    );
    $request->set_body(wp_json_encode(['limit' => str_repeat('x', 4001)]));
    $verify(
        'Oversized notification requests are rejected',
        rest_do_request($request)->get_status() === 400,
    );
}
wp_set_current_user(0);
$verify(
    'Anonymous inbox reads and state writes require login',
    $error($listing(), 'login_required') &&
        $error($save_state(['revision' => $empty['revision'], 'ids' => []]), 'login_required'),
);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
