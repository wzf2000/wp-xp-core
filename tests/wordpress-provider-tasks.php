<?php
/** Trusted provider task steps on the runner-owned synthetic WordPress installation. */
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
$verify = static function ($name, $value) use (&$checks) {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$uid = wp_insert_user([
    'user_login' => 'fixture-provider-tasks',
    'user_email' => 'provider-tasks@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Provider task subject created', !is_wp_error($uid));
$uid = (int) $uid;
$day = '2039-05-12';
$clock = static function ($value, $format) use (&$day) {
    return $format === 'Y-m-d' ? $day : $value;
};
add_filter('wp_date', $clock, 10, 2);
$original_rules = WP_XP_Core_Settings::rules();
$original_tasks = WP_XP_Core_Achievements::definitions();
$rules = $original_rules;
$provider_definition = [
    'label' => 'Synthetic course completion',
    'authorize' => static fn($user, $object, $context) => $user === $uid &&
        ($context['verified'] ?? false) === true,
    'revoke_authorize' => static fn($user, $object, $context) => $user === $uid &&
        ($context['verified'] ?? false) === true,
];
foreach (['task_course', 'task_other'] as $provider) {
    $verify(
        'Register trusted task source ' . $provider,
        wp_xp_core_register_activity_provider($provider, $provider_definition),
    );
    $rules['providers'][$provider] = ['enabled' => true, 'xp' => 0];
}
$save_rules = static fn($value) => WP_XP_Core_Settings::save(
    $value,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$save = static fn($value) => WP_XP_Core_Achievements::save(
    $value,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$event = static fn(
    $key,
    $object = 2000000001,
    $source = 'task_course',
) => wp_xp_core_record_activity($source, $key, $uid, $object, ['verified' => true]);
$revoke = static fn($key, $object = 2000000001) => wp_xp_core_revoke_activity(
    'task_course',
    $key,
    $uid,
    $object,
    ['verified' => true],
);
$table = Reader_Experience::table();
$providers_property = new ReflectionProperty(WP_XP_Core_Providers::class, 'providers');
$providers_property->setAccessible(true);
$registered = null;
try {
    $verify('Zero XP task sources explicitly enabled', $save_rules($rules) === true);
    $disabled_rules = $rules;
    $disabled_rules['providers']['task_course']['enabled'] = false;
    $verify(
        'Source can stay disabled independently of tasks',
        $save_rules($disabled_rules) === true && $error($event('disabled'), 'disabled'),
    );
    $verify('Explicit zero XP source reenabled', $save_rules($rules) === true);
    $historical = $event('before-task');
    $verify('Historical source receipt exists', !is_wp_error($historical));
    $input = [
        'id' => '',
        'title' => 'Synthetic trusted provider task',
        'description' => '',
        'icon' => '★',
        'type' => 'automatic',
        'period' => 'month',
        'enabled' => true,
        'steps' => [
            [
                'kind' => 'provider_reward',
                'provider_id' => 'task_course',
                'count' => 4,
                'mode' => 'events',
            ],
            [
                'kind' => 'provider_reward',
                'provider_id' => 'task_course',
                'count' => 3,
                'mode' => 'objects',
            ],
            [
                'kind' => 'provider_reward',
                'provider_id' => 'task_course',
                'count' => 2,
                'mode' => 'days',
            ],
        ],
    ];
    wp_set_current_user($uid);
    $verify('Subscriber cannot create provider task', $error($save($input), 'forbidden'));
    wp_set_current_user($admin->ID);
    $invalid = $input;
    $invalid['steps'][0]['provider_id'] = 'unregistered_course';
    $verify(
        'Task preview requires a valid nonce',
        $error(
            WP_XP_Core_Achievements::preview(
                $input,
                'invalid',
                WP_XP_Core_Achievements::revision(),
            ),
            'nonce',
        ),
    );
    $verify('Unknown source cannot create a task', $error($save($invalid), 'input'));
    $invalid = $input;
    $invalid['steps'][0]['post_ids'] = [1];
    $verify('Provider source refuses WordPress content scope', $error($save($invalid), 'input'));
    $invalid['steps'][0]['post_ids'] = [];
    $invalid['steps'][0]['category_ids'] = [1];
    $verify('Provider source refuses category scope', $error($save($invalid), 'input'));
    $preview = WP_XP_Core_Achievements::preview(
        $input,
        wp_create_nonce('wp_xp_core_achievements'),
        WP_XP_Core_Achievements::revision(),
    );
    $verify(
        'Provider task preview validates without writing',
        !is_wp_error($preview) && WP_XP_Core_Achievements::definitions() === $original_tasks,
    );
    $saved = $save($input);
    $verify('Provider task saves', !is_wp_error($saved));
    $id = $saved['id'];
    $task = static fn() => WP_XP_Core_Achievements::definitions()[$id];
    $progress = static fn() => WP_XP_Core_Achievements::progress($task(), $uid);
    $counts = static fn() => array_column($progress()['steps'], 'current');
    $verify('Task boundary excludes earlier source receipts', $counts() === [0, 0, 0]);
    $verify(
        'Unverified activity cannot advance a task',
        $error(
            wp_xp_core_record_activity('task_course', 'forged', $uid, 2000000001),
            'ineligible',
        ) && $counts() === [0, 0, 0],
    );
    $verify(
        'Wrong user cannot advance target task',
        $error(
            wp_xp_core_record_activity('task_course', 'wrong-user', $admin->ID, 2000000001, [
                'verified' => true,
            ]),
            'ineligible',
        ),
    );
    $first = $event('first');
    $verify(
        'Zero XP valid receipt counts without a WordPress object',
        !is_wp_error($first) && $counts() === [1, 1, 1] && !get_post(2000000001),
    );
    $duplicate = $event('first');
    $verify(
        'Duplicate source identity does not advance task',
        !is_wp_error($duplicate) && $duplicate['duplicate'] && $counts() === [1, 1, 1],
    );
    $verify(
        'Other source does not advance task',
        !is_wp_error($event('other-source', 2000000002, 'task_other')) && $counts() === [1, 1, 1],
    );
    $verify(
        'Objects deduplicate within selected source',
        !is_wp_error($event('same-object')) && $counts() === [2, 1, 1],
    );
    $verify(
        'Zero object counts only as event and day',
        !is_wp_error($event('zero-object', 0)) && $counts() === [3, 1, 1],
    );
    $verify(
        'Reversal excludes original before task completion',
        !is_wp_error($revoke('first')) && $counts() === [2, 1, 1],
    );
    $verify(
        'Repeated reversal is idempotent',
        !is_wp_error($revoke('first')) && $counts() === [2, 1, 1],
    );
    wp_set_current_user($uid);
    $personal = WP_XP_Core_Achievements::personal();
    $personal_task = array_values(
        array_filter($personal['tasks'], static fn($item) => $item['id'] === $id),
    )[0];
    $verify(
        'Personal batch counters agree with single-task progress',
        array_column($personal_task['steps'], 'current') === [2, 1, 1],
    );
    $verify(
        'Provider label reaches personal next-step data',
        $personal_task['steps'][0]['label'] === $provider_definition['label'],
    );
    $verify(
        'Another user has no target progress',
        array_column(
            WP_XP_Core_Achievements::progress($task(), $admin->ID)['steps'],
            'current',
        ) === [0, 0, 0],
    );
    $verify(
        'Incomplete task cannot be claimed',
        $error(WP_XP_Core_Achievements::claim($id, wp_create_nonce('wp_rest')), 'incomplete'),
    );
    $verify(
        'Arbitrary provider REST event has no write route',
        rest_do_request(new WP_REST_Request('POST', '/wp-xp-core/v1/activity'))->get_status() ===
            404,
    );
    $verify('Day two records selected source', !is_wp_error($event('second-object', 2000000002)));
    $day = '2039-05-13';
    $provider_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id=%d AND kind='provider_reward'",
            $uid,
        ),
    );
    $verify(
        'Distinct provider object on next local day completes task',
        !is_wp_error($event('third-object', 2000000003)) &&
            $counts() === [4, 3, 2] &&
            $progress()['earned'],
    );
    $receipt_key =
        'task:' .
        $id .
        ':' .
        $task()['version'] .
        ':' .
        WP_XP_Core_Achievements::period($task())['key'] .
        ':' .
        $uid;
    $verify(
        'Task has exactly one stable completion receipt without adding provider activity',
        (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE user_id=%d AND kind='task_complete' AND event_key=%s AND JSON_UNQUOTE(JSON_EXTRACT(detail,'$.task_id'))=%s",
                $uid,
                $receipt_key,
                $id,
            ),
        ) === 1 &&
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE user_id=%d AND kind='provider_reward'",
                    $uid,
                ),
            ) ===
                $provider_count + 1 &&
            $counts() === [4, 3, 2],
    );
    $verify(
        'Revoking activity retains earned achievement without cascading',
        !is_wp_error($revoke('third-object', 2000000003)) &&
            $counts() === [3, 2, 1] &&
            $progress()['earned'],
    );
    wp_set_current_user($admin->ID);
    $edit = WP_XP_Core_Achievements::validate(array_intersect_key($task(), $input));
    $edit['steps'][0]['count'] = 5;
    $verify(
        'Changing provider threshold creates future task version',
        !is_wp_error($save($edit)) && $task()['version'] === 2 && $counts() === [0, 0, 0],
    );
    $verify(
        'Duplicate old identity cannot satisfy a new version',
        !is_wp_error($event('same-object')) && $counts() === [0, 0, 0],
    );
    $verify(
        'Future source receipt enters new version',
        !is_wp_error($event('new-version', 2000000004)) && $counts() === [1, 1, 1],
    );
    $day_task = $task();
    $day_task['period'] = 'day';
    $week_task = $task();
    $week_task['period'] = 'week';
    $day = '2039-05-14';
    $verify(
        'Daily provider progress starts again on the next day',
        array_column(WP_XP_Core_Achievements::progress($day_task, $uid)['steps'], 'current') === [
            0,
            0,
            0,
        ],
    );
    $verify(
        'Weekly provider progress retains same-week events',
        array_column(WP_XP_Core_Achievements::progress($week_task, $uid)['steps'], 'current') === [
            1,
            1,
            1,
        ],
    );
    $day = '2039-05-16';
    $verify(
        'Weekly provider progress excludes prior week',
        array_column(WP_XP_Core_Achievements::progress($week_task, $uid)['steps'], 'current') === [
            0,
            0,
            0,
        ],
    );
    $day = '2039-06-01';
    $verify('Monthly period excludes prior month activity', $counts() === [0, 0, 0]);
    $day = '2039-05-13';
    $registered = $providers_property->getValue();
    $without = $registered;
    unset($without['task_course']);
    $providers_property->setValue(null, $without);
    $verify('Missing source retains readable historical progress', $counts() === [1, 1, 1]);
    $edit = WP_XP_Core_Achievements::validate(array_intersect_key($task(), $input));
    $edit['title'] = 'Retained missing source task';
    $verify(
        'Existing missing source can be edited without changing version',
        !is_wp_error($save($edit)) && $task()['version'] === 2,
    );
    $verify(
        'Missing source can preview existing definition',
        !is_wp_error(
            WP_XP_Core_Achievements::preview(
                $edit,
                wp_create_nonce('wp_xp_core_achievements'),
                WP_XP_Core_Achievements::revision(),
            ),
        ),
    );
    $config_nonce = wp_create_nonce('wp_xp_core_configuration');
    $export = WP_XP_Core_Configuration::export($config_nonce);
    $verify('Missing source remains exportable', !is_wp_error($export));
    $json = wp_json_encode($export);
    $verify(
        'Same-site import retains missing source definition',
        !is_wp_error(WP_XP_Core_Configuration::preview($json, 'update', $config_nonce)),
    );
    $verify(
        'New template cannot adopt unknown missing source',
        $error(WP_XP_Core_Configuration::preview($json, 'template', $config_nonce), 'input'),
    );
    $edit['id'] = '';
    $verify('Copy cannot create new task with missing source', $error($save($edit), 'input'));
    wp_set_current_user($uid);
    $personal = WP_XP_Core_Achievements::personal();
    $personal_task = array_values(
        array_filter($personal['tasks'], static fn($item) => $item['id'] === $id),
    )[0];
    $verify(
        'Missing source is visible without dropping progress',
        $personal_task['steps'][0]['source_available'] === false &&
            $personal_task['steps'][0]['current'] === 1,
    );
    $providers_property->setValue(null, $registered);
    wp_set_current_user($admin->ID);
    $rates = WP_XP_Core_Insights::task_rates();
    $rate =
        array_values(array_filter($rates['rows'], static fn($item) => $item['id'] === $id))[0] ??
        null;
    $verify(
        'Task statistics include selected provider participation',
        !is_wp_error($rates) && $rate !== null && $rate['participants'] >= 1,
    );
    $fault = static function ($needle, $callback) use ($wpdb, $verify) {
        $hit = false;
        $filter = static function ($sql) use ($needle, &$hit) {
            if (!$hit && str_contains($sql, $needle)) {
                $hit = true;
                return 'SELECT * FROM synthetic_intentionally_missing_provider_task_table';
            }
            return $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            return $callback();
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($suppressed);
            $verify('Selected provider task read failed: ' . $needle, $hit);
        }
    };
    wp_set_current_user($uid);
    $failed = false;
    try {
        $fault('COUNT(DISTINCT CASE', $progress);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    $verify('Single task read failure is not empty progress', $failed);
    $failed = false;
    try {
        $fault('COUNT(DISTINCT CASE', static fn() => WP_XP_Core_Achievements::personal());
    } catch (RuntimeException $e) {
        $failed = true;
    }
    $verify('Batch task read failure is not empty progress', $failed);
    $result = $fault(
        'COUNT(DISTINCT CASE',
        static fn() => WP_XP_Core_Achievements::claim($id, wp_create_nonce('wp_rest')),
    );
    $verify('Claim read failure returns storage error', $error($result, 'storage'));
    wp_set_current_user($admin->ID);
    $verify(
        'Task statistics fail closed on provider predicate read failure',
        $error(
            $fault(
                'SELECT DISTINCT e.user_id FROM',
                static fn() => WP_XP_Core_Insights::task_rates(),
            ),
            'storage',
        ),
    );
} finally {
    if ($registered !== null) {
        $providers_property->setValue(null, $registered);
    }
    wp_set_current_user($admin->ID);
    $save_rules($original_rules);
    update_option(WP_XP_Core_Achievements::OPTION, $original_tasks, false);
    WP_XP_Core_Achievements::clear_cache();
    remove_filter('wp_date', $clock, 10);
}
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
