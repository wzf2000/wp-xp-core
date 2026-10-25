<?php
/** Fail closed on actual SELECT errors in the owned synthetic installation. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
global $wpdb;
$checks = [];
$verify = static function ($name, $value) use (&$checks) {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$admin = get_user_by('login', 'fixture-author');
$uid = wp_insert_user([
    'user_login' => 'fixture-storage-reader',
    'user_pass' => wp_generate_password(32),
    'user_email' => 'fixture-storage-reader@example.invalid',
    'role' => 'subscriber',
]);
$verify('Independent storage-failure reader exists', !is_wp_error($uid));
$uid = (int) $uid;
wp_set_current_user($admin->ID);
$old = WP_XP_Core_Settings::rules();
$save = static fn($rules) => WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$rules = $old;
$rules['activities']['visit'] = true;
$rules['activities']['view'] = true;
$rules['activities']['checkin'] = true;
$rules['visit_limit'] = 1;
$rules['activities']['comment'] = true;
$rules['comment_limit'] = 3;
$rules['scope'] = ['post_ids' => [], 'category_ids' => []];
$verify('Failure fixtures retain valid reward policy', $save($rules) === true);
$posts = [];
for ($i = 0; $i < 2; $i++) {
    $posts[] = (int) wp_insert_post([
        'post_title' => 'Storage fault fixture ' . $i,
        'post_status' => 'publish',
        'post_author' => $admin->ID,
    ]);
}
$table = Reader_Experience::table();
$snapshot = static function () use ($wpdb, $table, $uid) {
    return [
        $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A),
        get_user_meta($uid, Reader_Experience::balance_meta(), true),
    ];
};
$request = static function ($action, $pid = 0) {
    $body = [];
    if ($action === 'visit') {
        $issued = time() - WP_XP_Core_Settings::rules()['dwell_seconds'] - 1;
        $body = [
            'post_id' => $pid,
            'issued' => $issued,
            'signature' => hash_hmac(
                'sha256',
                get_current_user_id() . ':' . $pid . ':' . $issued,
                wp_salt('nonce'),
            ),
        ];
    }
    $r = new WP_REST_Request('POST', '/reader-experience/v1/' . $action);
    $r->set_header('Content-Type', 'application/json');
    $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $r->set_body($body ? wp_json_encode($body) : '{}');
    return rest_do_request($r);
};
$fault = static function (string $needle, callable $callback) use ($wpdb, $verify) {
    $hit = false;
    $filter = static function ($sql) use ($needle, &$hit) {
        if (!$hit && str_contains($sql, $needle)) {
            $hit = true;
            return 'SELECT * FROM synthetic_intentionally_missing_storage_table';
        }
        return $sql;
    };
    $suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $filter);
    try {
        $result = $callback();
    } finally {
        remove_filter('query', $filter);
        $wpdb->suppress_errors($suppressed);
    }
    $verify('The intended SELECT fault was exercised: ' . $needle, $hit);
    return $result;
};
try {
    wp_set_current_user($uid);
    $before = $snapshot();
    $r = $fault("WHERE event_key='checkin:", static fn() => $request('checkin'));
    $verify(
        'Deduplication read failure returns 503 without an award',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $r = $fault('SELECT kind,xp,local_day FROM ' . $table, static fn() => $request('checkin'));
    $verify(
        'Response history failure rolls back the complete check-in transaction',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $r = $fault('SELECT kind,xp,local_day FROM ' . $table, static fn() => $request('state'));
    $verify(
        'State read failure is not returned as an empty successful history',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $html = $fault(
        'SELECT kind,xp,local_day FROM ' . $table,
        static fn() => do_shortcode('[reader_experience]'),
    );
    $verify(
        'Shortcode read failure remains a local maintenance message',
        str_contains($html, '经验系统维护中') &&
            !str_contains($html, 'synthetic_intentionally_missing_storage_table') &&
            $snapshot() === $before,
    );
    $r = $fault(
        'WHERE user_id=' . $uid . " AND kind='visit'",
        static fn() => $request('visit', $posts[0]),
    );
    $verify(
        'Quota read failure cannot be interpreted as zero activity',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $r = $fault(
        'WHERE object_id=' . $posts[0] . " AND kind='view'",
        static fn() => $request('visit', $posts[0]),
    );
    $verify(
        'Milestone read failure rolls back both visit and view',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $verify(
        'A normal request succeeds after transient faults',
        $request('visit', $posts[0])->get_status() === 200,
    );
    $before = $snapshot();
    $r = $fault(
        'WHERE user_id=' . $uid . " AND kind='visit'",
        static fn() => $request('visit', $posts[1]),
    );
    $verify(
        'An exhausted quota remains enforced when its read fails',
        $r->get_status() === 503 && $snapshot() === $before,
    );
    $verify(
        'The event lock is released after failed requests',
        (int) $wpdb->get_var(
            $wpdb->prepare('SELECT IS_FREE_LOCK(%s)', reader_experience_lock('event_lock')),
        ) === 1,
    );
    $verify(
        'A legitimate absent event is still absent',
        Reader_Experience::exists('synthetic-never-created') === false,
    );
    $cid = wp_insert_comment([
        'comment_post_ID' => $posts[0],
        'user_id' => $uid,
        'comment_approved' => '1',
        'comment_content' => 'Storage failure comment',
    ]);
    Reader_Experience::comment((int) $cid);
    $verify(
        'The fixture has a real original comment receipt',
        Reader_Experience::exists('comment:' . $cid),
    );
    $wpdb->update($wpdb->comments, ['comment_approved' => '0'], ['comment_ID' => $cid]);
    clean_comment_cache($cid);
    $before = $snapshot();
    $fault(
        "WHERE event_key='comment:" . $cid . "'",
        static fn() => Reader_Experience::comment((int) $cid),
    );
    $verify(
        'Failed original-comment lookup cannot commit a partial compensation',
        $snapshot() === $before,
    );
    Reader_Experience::comment((int) $cid);
    $verify(
        'An ordinary comment retry compensates exactly once after read recovery',
        $snapshot() !== $before,
    );
} finally {
    wp_set_current_user($admin->ID);
    $verify('Failure fixtures restore the prior reward policy', $save($old) === true);
}
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
