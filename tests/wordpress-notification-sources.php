<?php
/** Real Companion notifications on this runner's owned, synthetic local installation. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local' ||
    !function_exists('pagenest_notifications_projection') ||
    !function_exists('pagenest_notifications_read') ||
    !function_exists('pagenest_notifications_visible')
) {
    throw new RuntimeException('Owned synthetic WordPress and real Companion required');
}
$owner_path = realpath((string) getenv('WP_XP_TEST_OWNER_FILE'));
$owner = $owner_path ? json_decode(file_get_contents($owner_path), true) : null;
if (
    !$owner_path ||
    !str_starts_with($owner_path, '/tmp/') ||
    !is_array($owner) ||
    ($owner['token'] ?? null) !== get_option('wp_xp_core_test_owner') ||
    realpath($owner['site'] ?? '') !== realpath(ABSPATH)
) {
    throw new RuntimeException('Matching owned lab marker required');
}
add_filter('pre_wp_mail', '__return_true');
$checks = [];
$diagnostic = [];
$verify = static function ($label, $condition) use (&$checks, &$diagnostic) {
    if (!$condition) {
        throw new RuntimeException($label . ': ' . wp_json_encode($diagnostic));
    }
    $checks[] = $label;
};
$code = static fn($value) => is_wp_error($value) ? (string) $value->get_error_code() : null;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$users = [];
foreach (['recipient', 'author', 'other'] as $role) {
    $user = wp_insert_user([
        'user_login' => 'fixture-source-' . $role,
        'user_email' => 'source-' . $role . '@example.invalid',
        'user_pass' => wp_generate_password(32),
        'role' => 'subscriber',
    ]);
    $verify('Fresh synthetic notification user', !is_wp_error($user));
    $users[$role] = (int) $user;
}
$before = get_option('pagenest_companion_notifications', '0');
update_option('pagenest_companion_notifications', '1', false);
$post = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Synthetic community discussion',
    'post_content' => 'Synthetic public material for the owned notification integration test.',
    'post_author' => $users['author'],
    'comment_status' => 'open',
]);
$verify('Public synthetic discussion inserted', !is_wp_error($post) && $post > 0);
$approve = static fn() => 1;
add_filter('pre_comment_approved', $approve, PHP_INT_MAX);
try {
    wp_set_current_user($users['recipient']);
    $parent = wp_new_comment(
        [
            'comment_post_ID' => $post,
            'user_id' => $users['recipient'],
            'comment_author' => 'Synthetic recipient',
            'comment_author_email' => get_userdata($users['recipient'])->user_email,
            'comment_author_IP' => '192.0.2.10',
            'comment_content' => 'Synthetic question',
            'comment_type' => 'comment',
            'comment_approved' => 1,
        ],
        true,
    );
    $diagnostic['parent_create_error'] = $code($parent);
    $verify('Parent comment created', !is_wp_error($parent) && $parent > 0);
    wp_set_current_user($users['author']);
    $reply = wp_new_comment(
        [
            'comment_post_ID' => $post,
            'comment_parent' => $parent,
            'user_id' => $users['author'],
            'comment_author' => 'Synthetic author',
            'comment_author_email' => get_userdata($users['author'])->user_email,
            'comment_author_IP' => '192.0.2.11',
            'comment_content' => 'Synthetic answer',
            'comment_type' => 'comment',
            'comment_approved' => 1,
        ],
        true,
    );
    $diagnostic['reply_create_error'] = $code($reply);
    $verify('Reply comment created', !is_wp_error($reply) && $reply > 0);
} finally {
    remove_filter('pre_comment_approved', $approve, PHP_INT_MAX);
}
$seed = get_comment_meta($reply, '_pagenest_notification_event', true);
$comment = get_comment($reply);
$parent_comment = get_comment($parent);
$diagnostic = [
    'seed_exists' => is_array($seed),
    'seed_recipient_matches' => (int) ($seed['recipient'] ?? 0) === $users['recipient'],
    'seed_author_matches' => (int) ($seed['author'] ?? -1) === (int) $comment->user_id,
    'seed_post_matches' => (int) ($seed['post'] ?? 0) === (int) $comment->comment_post_ID,
    'seed_parent_matches' => (int) ($seed['parent'] ?? 0) === (int) $parent_comment->comment_ID,
    'reply_parent_matches' => (int) $comment->comment_parent === (int) $parent_comment->comment_ID,
    'parent_post_matches' =>
        (int) $parent_comment->comment_post_ID === (int) $comment->comment_post_ID,
    'different_authors' => (int) $comment->user_id !== $users['recipient'],
    'parent_public' => pagenest_notifications_public($parent_comment),
    'reply_public' => pagenest_notifications_public($comment),
    'recipient_matches' =>
        (int) get_comment_meta($reply, '_pagenest_notification_recipient', true) ===
        $users['recipient'],
    'parent_owner_matches' => (int) $parent_comment->user_id === $users['recipient'],
    'approved' =>
        (string) $comment->comment_approved === '1' &&
        (string) $parent_comment->comment_approved === '1',
    'comment_type_valid' => in_array($comment->comment_type, ['', 'comment'], true),
    'source_visible' => pagenest_notifications_visible($comment, $seed, $users['recipient']),
    'preference_off' =>
        get_user_meta($users['recipient'], '_pagenest_notifications_off', true) === '1',
];
wp_set_current_user($users['recipient']);
$projection = pagenest_notifications_projection();
$diagnostic['projection_error'] = $code($projection);
$diagnostic['projection_count'] = is_array($projection) ? count($projection['items'] ?? []) : 0;
$original = is_array($projection)
    ? array_values(
        array_filter(
            $projection['items'] ?? [],
            static fn($item) => (int) $item['id'] === (int) $reply &&
                $item['kind'] === 'comment_reply',
        ),
    )
    : [];
$verify('Original Companion projection includes owned new reply', count($original) === 1);
$nonce = wp_create_nonce('wp_rest');
$direct = WP_XP_Core_Notification_Sources::request(
    'notification-source-list',
    ['source' => 'companion', 'limit' => 12],
    $nonce,
);
$diagnostic['direct_error'] = $code($direct);
$diagnostic['direct_count'] = is_array($direct) ? count($direct['items'] ?? []) : 0;
$verify(
    'Direct XP source dispatch includes same reply',
    is_array($direct) &&
        in_array('comment_reply:' . $reply, array_column($direct['items'], 'id'), true),
);
$request = static function ($action, array $data = []) {
    $request = new WP_REST_Request(
        'POST',
        '/' . reader_experience_config('rest_namespace') . '/' . $action,
    );
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_body($data ? wp_json_encode($data) : '{}');
    return rest_do_request($request);
};
$listing = $request('notification-source-list', ['source' => 'companion', 'limit' => 12]);
$data = $listing->get_data();
$diagnostic['route_status'] = $listing->get_status();
$diagnostic['route_count'] = is_array($data['items'] ?? null) ? count($data['items']) : 0;
$diagnostic['route_error'] = is_string($data['code'] ?? null) ? $data['code'] : null;
$items = is_array($data['items'] ?? null)
    ? array_values(
        array_filter($data['items'], static fn($item) => $item['id'] === 'comment_reply:' . $reply),
    )
    : [];
$verify(
    'Actual JSON REST source route includes owned reply',
    $listing->get_status() === 200 && count($items) === 1,
);
$verify(
    'Adapter preserves original visible link and title',
    $items[0]['title'] === $original[0]['title'] &&
        $items[0]['url'] === $original[0]['url'] &&
        !$items[0]['read'],
);
$marked = $request('notification-source-read', [
    'source' => 'companion',
    'id' => 'comment_reply:' . $reply,
]);
$diagnostic['mark_status'] = $marked->get_status();
$verify(
    'Actual REST mark delegates to original source',
    $marked->get_status() === 200 && ($marked->get_data()['saved'] ?? false) === true,
);
$after = pagenest_notifications_projection();
$read_items = is_array($after)
    ? array_values(
        array_filter($after['items'], static fn($item) => (int) $item['id'] === (int) $reply),
    )
    : [];
$verify(
    'Original projection observes updated read state',
    count($read_items) === 1 && $read_items[0]['read'] === true,
);
wp_set_current_user($users['other']);
$foreign = $request('notification-source-read', [
    'source' => 'companion',
    'id' => 'comment_reply:' . $reply,
]);
$diagnostic['foreign_status'] = $foreign->get_status();
$verify('Cross-user mark is rejected with 404', $foreign->get_status() === 404);
$foreign_list = $request('notification-source-list', ['source' => 'companion', 'limit' => 12]);
$verify(
    'Other user never receives the owned message',
    $foreign_list->get_status() === 200 &&
        !in_array(
            'comment_reply:' . $reply,
            array_column($foreign_list->get_data()['items'], 'id'),
            true,
        ),
);
wp_set_current_user($users['recipient']);
update_option('pagenest_companion_notifications', '0', false);
$available = $request('notification-sources');
$verify(
    'Disabled Companion disappears while XP source remains',
    $available->get_status() === 200 &&
        array_column($available->get_data()['sources'], 'id') === ['xp'],
);
$disabled = $request('notification-source-list', ['source' => 'companion', 'limit' => 12]);
$verify('Disabled external list refuses access', $disabled->get_status() === 404);
$xp = WP_XP_Core_Notifications::listing(['limit' => 12], wp_create_nonce('wp_rest'));
$verify('Independent XP inbox remains available', is_array($xp) && isset($xp['items']));
wp_set_current_user($admin->ID);
update_option('pagenest_companion_notifications', $before, false);
remove_filter('pre_wp_mail', '__return_true');
echo wp_json_encode(['checks' => $checks, 'count' => count($checks), 'diagnostic' => $diagnostic]);
