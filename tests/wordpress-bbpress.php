<?php
/** Explicit real bbPress integration; no optional skip and no production adoption. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local' ||
    !function_exists('bbp_insert_forum') ||
    !function_exists('bbp_insert_topic') ||
    !function_exists('bbp_insert_reply')
) {
    throw new RuntimeException('Owned synthetic WordPress and real bbPress required');
}
$checks = [];
$verify = static function ($label, $condition) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$uid = wp_insert_user([
    'user_login' => 'fixture-bbpress-reader',
    'user_email' => 'bbpress@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Fresh bbPress subject', !is_wp_error($uid));
$uid = (int) $uid;
$original = WP_XP_Core_Settings::rules();
$rules = $original;
$save = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$rules['providers']['bbpress_topic'] = ['enabled' => false, 'xp' => 7];
$rules['providers']['bbpress_reply'] = ['enabled' => false, 'xp' => 3];
$rules['activity_caps'] = ['day' => 0, 'week' => 0];
$verify(
    'Optional providers register regardless of file order',
    isset(
        WP_XP_Core_Providers::definitions()['bbpress_topic'],
        WP_XP_Core_Providers::definitions()['bbpress_reply'],
    ),
);
$verify('Disabled fixture rules save', !is_wp_error($save($rules)));
$forum = (int) bbp_insert_forum([
    'post_title' => 'Synthetic portable public forum',
    'post_status' => bbp_get_public_status_id(),
    'post_author' => $admin->ID,
]);
$verify('Real public forum inserted', $forum > 0 && bbp_is_forum_public($forum, true));
$topic = static fn(
    $title,
    $status = 'publish',
    $author = null,
    $in_forum = null,
    $password = '',
) => (int) bbp_insert_topic(
    [
        'post_title' => $title,
        'post_content' => 'Synthetic topic body',
        'post_author' => $author ?? $uid,
        'post_status' => $status,
        'post_parent' => $in_forum ?? $forum,
        'post_password' => $password,
    ],
    ['forum_id' => $in_forum ?? $forum],
);
$reply = static fn($tid, $status = 'publish', $author = null) => (int) bbp_insert_reply(
    [
        'post_title' => 'Synthetic portable reply',
        'post_content' => 'Synthetic reply body',
        'post_author' => $author ?? $uid,
        'post_status' => $status,
        'post_parent' => $tid,
    ],
    ['forum_id' => $forum, 'topic_id' => $tid],
);
global $wpdb;
$ledger = Reader_Experience::table();
$receipt = static fn($provider, $id) => $wpdb->get_row(
    $wpdb->prepare(
        'SELECT * FROM ' . $ledger . ' WHERE event_key=%s',
        'provider:' . $provider . ':' . hash('sha256', (string) $id),
    ),
    ARRAY_A,
);
$balance = static function () use ($uid) {
    wp_cache_delete($uid, 'user_meta');
    return (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
};
$old = $topic('Created before enabling');
$verify('Disabled provider does not reward', $old > 0 && !$receipt('bbpress_topic', $old));
$rules['providers']['bbpress_topic']['enabled'] = true;
$rules['providers']['bbpress_reply']['enabled'] = true;
$verify('Enabled fixture rules save', !is_wp_error($save($rules)));
do_action('bbp_new_topic', $old, $forum, [], $uid);
$verify('Replayed historical creation hook does not backfill', !$receipt('bbpress_topic', $old));
$public = $topic('Enabled public topic');
$event = $receipt('bbpress_topic', $public);
$verify(
    'Actual insert topic hook earns once',
    $event && (int) $event['xp'] === 7 && $balance() === 7,
);
do_action('bbp_new_topic', $public, $forum, [], $uid);
$verify('Multiple official creation hooks are idempotent', $balance() === 7);
$public_reply = $reply($public);
$verify(
    'Actual reply hook earns once',
    (int) ($receipt('bbpress_reply', $public_reply)['xp'] ?? 0) === 3 && $balance() === 10,
);
bbp_close_topic($public);
$verify('Closed topic remains public without reversal', $balance() === 10);
$closed_reply = $reply($public);
$verify(
    'Reply under closed but public topic can qualify',
    (int) ($receipt('bbpress_reply', $closed_reply)['xp'] ?? 0) === 3,
);
$pending = $topic('Pending topic', 'pending');
$verify('Pending topic earns nothing', !$receipt('bbpress_topic', $pending));
wp_update_post(['ID' => $pending, 'post_status' => 'publish']);
$verify('Later moderation approval does not backfill', !$receipt('bbpress_topic', $pending));
$password = $topic('Password topic', 'publish', null, null, 'synthetic-password');
$verify('Password topic earns nothing', !$receipt('bbpress_topic', $password));
$anonymous = $topic('Anonymous topic', 'publish', 0);
$verify('Anonymous topic earns nothing', !$receipt('bbpress_topic', $anonymous));
$private = (int) bbp_insert_forum([
    'post_title' => 'Synthetic private forum',
    'post_status' => 'publish',
    'post_author' => $admin->ID,
]);
bbp_privatize_forum($private);
$private_topic = $topic('Private forum topic', 'publish', null, $private);
$verify('Private forum excludes new topic', !$receipt('bbpress_topic', $private_topic));
$child = (int) bbp_insert_forum([
    'post_title' => 'Child of private forum',
    'post_status' => 'publish',
    'post_parent' => $private,
    'post_author' => $admin->ID,
]);
$child_topic = $topic('Private ancestor topic', 'publish', null, $child);
$verify('Private ancestor excludes new topic', !$receipt('bbpress_topic', $child_topic));
$before = $balance();
bbp_spam_reply($public_reply);
$verify('Spam transition reverses exact reply amount', $balance() === $before - 3);
bbp_unspam_reply($public_reply);
$verify('Unspam does not replay reward', $balance() === $before - 3);
$before = $balance();
wp_delete_post($closed_reply, true);
$verify('Deletion reverses while original ownership is available', $balance() === $before - 3);
$before = $balance();
bbp_privatize_forum($forum);
$verify('Parent forum change does not scan descendants', $balance() === $before);
bbp_publicize_forum($forum);
$verify('Making forum public does not backfill', $balance() === $before);
$reassigned = $topic('Author reassignment before spam');
$before = $balance();
wp_update_post(['ID' => $reassigned, 'post_author' => $admin->ID]);
bbp_spam_topic($reassigned);
$verify('Moderation after author change reverses original recipient', $balance() === $before - 7);
$reassigned_delete = $reply($public);
$before = $balance();
wp_update_post(['ID' => $reassigned_delete, 'post_author' => $admin->ID]);
wp_set_current_user(0);
wp_delete_post($reassigned_delete, true);
wp_set_current_user($admin->ID);
$verify(
    'Trusted server deletion without actor reverses original recipient after author change',
    $balance() === $before - 3,
);
$insufficient = $topic('Insufficient reversal fixture');
$amount = $balance();
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        'bbpress-fixture-manual-debit',
        $uid,
        'manual_adjust',
        0,
        -$amount,
        Reader_Experience::day(),
    ),
);
bbp_spam_topic($insufficient);
$verify('Insufficient balance does not become negative or partially reverse', $balance() === 0);
$failed = get_option('wp_xp_core_bbpress_failures', []);
$verify(
    'Failed automatic reversal remains visible in diagnostics',
    (bool) array_filter(
        $failed,
        static fn($item) => $item['object_id'] === $insufficient && $item['code'] === 'balance',
    ),
);
$verify('Original rules restored', !is_wp_error($save($original)));
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
