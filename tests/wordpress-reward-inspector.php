<?php
/** Explain immutable receipts and simulate primary rewards without writes or adapter callbacks. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic WordPress database required');
}
global $wpdb;
$checks = [];
$verify = static function ($name, $value) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$uid = wp_insert_user([
    'user_login' => 'fixture-inspector',
    'user_email' => 'inspector@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Inspector subject created', !is_wp_error($uid));
$uid = (int) $uid;
$original_rules = WP_XP_Core_Settings::rules();
$original_tasks = WP_XP_Core_Achievements::definitions();
$original_boundaries = get_option(WP_XP_Core_Providers::BOUNDARIES, null);
$created = 0;
$calls = ['authorize' => 0, 'facts' => 0, 'available' => 0, 'deliver' => 0];
$verify(
    'Inspector source registers',
    wp_xp_core_register_activity_provider('inspector_probe', [
        'label' => 'Synthetic inspection source',
        'fields' => ['score'],
        'authorize' => static function ($user) use (&$calls, $uid) {
            $calls['authorize']++;
            return $user === $uid;
        },
        'facts' => static function () use (&$calls, &$created) {
            $calls['facts']++;
            return ['score' => 20, 'created_ms' => $created];
        },
        'available' => static function () use (&$calls) {
            $calls['available']++;
            return true;
        },
    ]),
);
wp_xp_core_register_notification_channel('inspector_probe', [
    'label' => 'Synthetic inspection channel',
    'available' => static function () use (&$calls) {
        $calls['available']++;
        return true;
    },
    'deliver' => static function () use (&$calls) {
        $calls['deliver']++;
        return true;
    },
]);
$save = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$inspect = static fn($input) => WP_XP_Core_Reward_Inspector::inspect(
    $input,
    wp_create_nonce('wp_xp_core_manage'),
);
$simulation = [
    'mode' => 'simulate',
    'kind' => 'provider_reward',
    'provider_id' => 'inspector_probe',
    'user_id' => $uid,
    'object_id' => 500000001,
    'event' => 'hypothetical-new',
    'score' => 20,
];
$rules = $original_rules;
$rules['providers']['inspector_probe'] = [
    'enabled' => true,
    'xp' => 7,
    'day_limit' => 1,
    'min_score' => 10,
    'channels' => ['inspector_probe'],
];
$rules['activity_caps'] = ['day' => 5, 'week' => 8];
$table = Reader_Experience::table();
$snapshot = static fn() => [
    $wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A),
    $wpdb->get_results("SELECT * FROM {$wpdb->options} ORDER BY option_id", ARRAY_A),
    $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
];
$assert_readonly = static function ($input) use ($snapshot, $inspect, $verify, &$calls) {
    $before = $snapshot();
    $calls = array_fill_keys(array_keys($calls), 0);
    $writes = [];
    $observe = static function ($sql) use (&$writes) {
        if (
            preg_match(
                '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|START|COMMIT|ROLLBACK|SET)\b/i',
                $sql,
            )
        ) {
            $writes[] = strtok($sql, ' ');
        }
        return $sql;
    };
    add_filter('query', $observe);
    try {
        $result = $inspect($input);
    } finally {
        remove_filter('query', $observe);
    }
    $verify(
        'Inspection performs no data or transaction writes',
        !$writes && $snapshot() === $before,
    );
    $verify('Inspection calls no provider or channel callbacks', array_sum($calls) === 0);
    return $result;
};
try {
    $verify('Inspector rules save', $save($rules) === true);
    $created = WP_XP_Core_Providers::boundary('inspector_probe') + 1;
    $first = $assert_readonly($simulation);
    $verify(
        'Hypothetical new source shares the actual cap calculation',
        !is_wp_error($first) &&
            $first['requested_xp'] === 7 &&
            $first['hypothetical_xp'] === 5 &&
            $first['may_record'] === null &&
            $first['eligibility'] === 'unverified',
    );
    $recorded = wp_xp_core_record_activity('inspector_probe', 'actual-original', $uid, 500000001);
    $verify(
        'Real synthetic source matches simulated primary XP',
        !is_wp_error($recorded) &&
            (int) $wpdb->get_var(
                $wpdb->prepare("SELECT xp FROM $table WHERE id=%d", $recorded['event_id']),
            ) === 5,
    );
    $receipt_input = ['mode' => 'receipt', 'event_id' => $recorded['event_id']];
    $receipt = $assert_readonly($receipt_input);
    $verify(
        'Original receipt exposes original quota and cap facts',
        $receipt['actual_xp'] === 5 &&
            $receipt['snapshots']['provider_quota']['requested_xp'] === 7 &&
            $receipt['snapshots']['activity_cap']['actual_xp'] === 5,
    );
    $duplicate = $assert_readonly(array_replace($simulation, ['event' => 'actual-original']));
    $verify(
        'Stable duplicate identity predicts no new primary XP',
        $duplicate['hypothetical_xp'] === 0 &&
            $duplicate['existing_event_id'] === $recorded['event_id'] &&
            $duplicate['may_record'] === false,
    );
    $conflict = $assert_readonly(
        array_replace($simulation, ['event' => 'actual-original', 'object_id' => 500000002]),
    );
    $verify(
        'Conflicting stable object identity is blocked',
        $conflict['may_record'] === false &&
            in_array('blocked', array_column($conflict['checks'], 'state'), true),
    );
    $quota = $assert_readonly($simulation);
    $verify(
        'Exhausted source quota predicts a valid zero receipt',
        $quota['after_quota'] === 0 &&
            $quota['hypothetical_xp'] === 0 &&
            $quota['quota']['exhausted'] &&
            $quota['may_record'] === null,
    );
    $low_score = $assert_readonly(array_replace($simulation, ['score' => 1]));
    $verify('Hypothetical below-threshold source is blocked', $low_score['may_record'] === false);
    $unknown_score = $assert_readonly(array_replace($simulation, ['score' => '']));
    $verify(
        'Missing hypothetical score is explicitly unknown',
        in_array('unknown', array_column($unknown_score['checks'], 'state'), true) &&
            $unknown_score['eligibility'] === 'unverified',
    );
    $rules['providers']['inspector_probe']['day_limit'] = 0;
    $verify('Source can stop accepting activities', $save($rules) === true);
    $verify(
        'Zero-count source predicts no receipt',
        $assert_readonly($simulation)['may_record'] === false,
    );
    $rules['providers']['inspector_probe']['day_limit'] = 10;
    $rules['providers']['inspector_probe']['xp'] = 100;
    $rules['activity_caps'] = ['day' => 200, 'week' => 200];
    $verify('Change future rules without editing old receipt', $save($rules) === true);
    $verify(
        'Old receipt explanation never uses current rules',
        $assert_readonly($receipt_input) === $receipt,
    );
    $hold = WP_XP_Core_Reward_Controls::save(
        [
            'user_id' => $uid,
            'minutes' => 5,
            'reason' => 'Synthetic inspector hold',
            'revision' => WP_XP_Core_Reward_Controls::revision($uid),
        ],
        wp_create_nonce('wp_xp_core_manage'),
    );
    $verify('Synthetic hold saved', !is_wp_error($hold));
    $held = $assert_readonly($simulation);
    $verify(
        'Inspection reuses hold calculation',
        $held['after_quota'] === 100 && $held['after_hold'] === 0 && $held['hypothetical_xp'] === 0,
    );
    $post = wp_insert_post([
        'post_title' => 'Synthetic inspection post',
        'post_status' => 'publish',
        'post_author' => $admin->ID,
    ]);
    $comment = wp_insert_comment([
        'comment_post_ID' => $post,
        'user_id' => $uid,
        'comment_approved' => '1',
        'comment_content' => 'Synthetic inspection comment',
    ]);
    $native = ['mode' => 'simulate', 'user_id' => $uid, 'object_id' => 0];
    foreach (
        ['checkin' => 0, 'visit' => $post, 'comment' => $comment, 'article' => $post]
        as $kind => $object
    ) {
        $result = $assert_readonly(
            array_replace($native, ['kind' => $kind, 'object_id' => $object]),
        );
        $verify('Native primary activity can be inspected: ' . $kind, !is_wp_error($result));
        if ($kind === 'comment' && $result['existing_event_id']) {
            $verify(
                'Comment recovery is explicitly outside new-primary simulation',
                in_array('compensation', array_column($result['checks'], 'rule'), true),
            );
        }
        if ($kind === 'article') {
            $verify('Article target ownership is enforced', $result['may_record'] === false);
        }
    }
    wp_set_current_user($uid);
    $verify('Subscriber cannot inspect rewards', $error($inspect($simulation), 'forbidden'));
    wp_set_current_user($admin->ID);
    $verify(
        'Inspector nonce enforced',
        $error(WP_XP_Core_Reward_Inspector::inspect($simulation, 'invalid'), 'nonce'),
    );
    $deny_target = static function ($caps, $cap, $user, $args) use ($uid) {
        return $cap === 'edit_user' && ($args[0] ?? null) === $uid ? ['do_not_allow'] : $caps;
    };
    add_filter('map_meta_cap', $deny_target, 10, 4);
    $verify('Simulation enforces target permission', $error($inspect($simulation), 'forbidden'));
    $verify(
        'Receipt explanation enforces its stored owner permission',
        $error($inspect($receipt_input), 'forbidden'),
    );
    remove_filter('map_meta_cap', $deny_target, 10);
    $calls = array_fill_keys(array_keys($calls), 0);
    $before = $snapshot();
    $capabilities = WP_XP_Core_Reward_Inspector::capabilities(wp_create_nonce('wp_xp_core_manage'));
    $verify(
        'Capability list reads metadata without source callbacks or writes',
        !is_wp_error($capabilities) && array_sum($calls) === 0 && $snapshot() === $before,
    );
    $templates = WP_XP_Core_Configuration::templates();
    $template_key = 'provider_inspector_probe_events';
    $verify(
        'Registered source offers zero XP disabled task templates',
        isset($templates[$template_key]) &&
            !$templates[$template_key]['enabled'] &&
            $templates[$template_key]['reward_xp'] === 0 &&
            array_sum($calls) === 0,
    );
    $json = WP_XP_Core_Configuration::template_document($template_key);
    $config_nonce = wp_create_nonce('wp_xp_core_configuration');
    $preview = WP_XP_Core_Configuration::preview($json, 'template', $config_nonce);
    $verify(
        'Source task template preview leaves definitions unchanged',
        !is_wp_error($preview) && WP_XP_Core_Achievements::definitions() === $original_tasks,
    );
    $applied = WP_XP_Core_Configuration::apply(
        $json,
        'template',
        $preview['revision'],
        $preview['proof'],
        $config_nonce,
    );
    $verify(
        'Source task requires explicit previewed application',
        !is_wp_error($applied) &&
            count(WP_XP_Core_Achievements::definitions()) === count($original_tasks) + 1 &&
            WP_XP_Core_Settings::rules()['providers']['inspector_probe'] ===
                $rules['providers']['inspector_probe'],
    );
    $property = new ReflectionProperty(WP_XP_Core_Providers::class, 'providers');
    $property->setAccessible(true);
    $registered = $property->getValue();
    $missing = $registered;
    unset($missing['inspector_probe']);
    $property->setValue(null, $missing);
    try {
        $verify(
            'Absent source cannot generate a new task template',
            !isset(WP_XP_Core_Configuration::templates()[$template_key]),
        );
        $missing_result = $assert_readonly($simulation);
        $verify(
            'Missing source is blocked without dropping saved data',
            $missing_result['may_record'] === false,
        );
    } finally {
        $property->setValue(null, $registered);
    }
    foreach (
        [
            'SELECT COALESCE(SUM(CASE' => $simulation,
            "SELECT COUNT(*) FROM $table WHERE user_id=" => $simulation,
            "SELECT * FROM $table WHERE id=" => $receipt_input,
        ]
        as $needle => $fault_input
    ) {
        $faulted = false;
        $fault = static function ($sql) use (&$faulted, $needle) {
            if (!$faulted && str_contains($sql, $needle)) {
                $faulted = true;
                return 'SELECT * FROM synthetic_missing_inspector_table';
            }
            return $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $fault);
        try {
            $failed = $inspect($fault_input);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($suppressed);
        }
        $verify(
            'Inspector failed read is not empty success: ' . $needle,
            $faulted && $error($failed, 'storage'),
        );
    }
} finally {
    wp_set_current_user($admin->ID);
    $save($original_rules);
    update_option(WP_XP_Core_Achievements::OPTION, $original_tasks, false);
    if ($original_boundaries === null) {
        delete_option(WP_XP_Core_Providers::BOUNDARIES);
    } else {
        update_option(WP_XP_Core_Providers::BOUNDARIES, $original_boundaries, false);
    }
    WP_XP_Core_Achievements::clear_cache();
}
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
