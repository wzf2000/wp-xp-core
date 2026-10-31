<?php
/** Actual zero-receipt reasons, bounded aggregate CSV and request-local provider observations. */
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
$verify = static function ($name, $value) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$original_rules = WP_XP_Core_Settings::rules();
$original_tasks = WP_XP_Core_Achievements::definitions();
$users = [];
foreach (['one', 'two'] as $suffix) {
    $user = wp_insert_user([
        'user_login' => 'fixture-provider-stats-' . $suffix,
        'user_email' => 'stats-' . $suffix . '@example.invalid',
        'user_pass' => wp_generate_password(32),
        'role' => 'subscriber',
    ]);
    $verify('Statistics subject created ' . $suffix, !is_wp_error($user));
    $users[] = (int) $user;
}
$day = '2040-06-15';
$clock = static function ($value, $format) use (&$day) {
    return $format === 'Y-m-d' ? $day : $value;
};
add_filter('wp_date', $clock, 10, 2);
$observations = ['stats_alpha' => 0, 'stats_beta' => 0];
$throw_beta = false;
$label = " =SUM(1,2)\r\n\"课程\"";
foreach (['stats_alpha', 'stats_beta'] as $source) {
    wp_xp_core_register_activity_provider($source, [
        'label' => $source === 'stats_alpha' ? $label : 'Synthetic beta',
        'authorize' => static fn($uid) => in_array($uid, $users, true),
        'revoke_authorize' => static fn($uid) => in_array($uid, $users, true),
        'available' => static function () use ($source, &$observations, &$throw_beta) {
            $observations[$source]++;
            if ($source === 'stats_beta' && $throw_beta) {
                throw new RuntimeException('Synthetic unavailable source');
            }
            return true;
        },
    ]);
}
$save = static fn($rules) => WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$event = static fn(
    $key,
    $object = 0,
    $user = null,
    $source = 'stats_alpha',
) => wp_xp_core_record_activity($source, $key, $user ?? $users[0], $object);
$hold = static fn($minutes) => WP_XP_Core_Reward_Controls::save(
    [
        'user_id' => $users[0],
        'minutes' => $minutes,
        'reason' => 'Synthetic statistics hold',
        'revision' => WP_XP_Core_Reward_Controls::revision($users[0]),
    ],
    wp_create_nonce('wp_xp_core_manage'),
);
$table = Reader_Experience::table();
$snapshot = static fn() => [
    $wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A),
    $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
    WP_XP_Core_Settings::rules(),
    WP_XP_Core_Achievements::definitions(),
];
try {
    update_option(WP_XP_Core_Achievements::OPTION, [], false);
    WP_XP_Core_Achievements::clear_cache();
    $task_ids = [];
    foreach (['single', 'mixed'] as $name) {
        $steps = [['kind' => 'provider_reward', 'provider_id' => 'stats_alpha', 'count' => 1000]];
        if ($name === 'mixed') {
            $steps[] = [
                'kind' => 'provider_reward',
                'provider_id' => 'stats_beta',
                'count' => 1000,
            ];
        }
        $saved = WP_XP_Core_Achievements::save(
            [
                'id' => '',
                'title' => 'Statistics task ' . $name,
                'description' => '',
                'icon' => '◇',
                'type' => 'automatic',
                'period' => 'once',
                'enabled' => true,
                'steps' => $steps,
            ],
            wp_create_nonce('wp_xp_core_achievements'),
            WP_XP_Core_Achievements::revision(),
        );
        $verify('Statistics task saves ' . $name, !is_wp_error($saved));
        $task_ids[$name] = $saved['id'];
    }
    $rules = $original_rules;
    $rules['providers']['stats_alpha'] = ['enabled' => true, 'xp' => 5, 'day_limit' => 1];
    $rules['providers']['stats_beta'] = ['enabled' => true, 'xp' => 2];
    $rules['activity_caps'] = ['day' => 0, 'week' => 0];
    $verify('Statistics policy saves', $save($rules) === true);
    $positive = $event('positive', 1);
    $revoked_zero = $event('quota-revoked', 2);
    $quota = $event('quota-valid', 3);
    $verify(
        'Real positive and exhausted quota receipts persist',
        !is_wp_error($positive) && !is_wp_error($revoked_zero) && !is_wp_error($quota),
    );
    $rules['providers']['stats_alpha']['xp'] = 0;
    $rules['providers']['stats_alpha']['day_limit'] = 10;
    $save($rules);
    $zero_rule = $event('rule-zero', 4);
    $rules['providers']['stats_alpha']['xp'] = 5;
    $save($rules);
    $verify('Statistics hold saves', !is_wp_error($hold(5)));
    $zero_hold = $event('held-zero', 5);
    $hold(0);
    $rules['activity_caps'] = ['day' => 5, 'week' => 8];
    $save($rules);
    $zero_cap = $event('cap-zero', 6);
    Reader_Experience::transaction(static function () use ($users): void {
        Reader_Experience::record(
            'provider:stats_alpha:' . hash('sha256', 'legacy-other'),
            $users[0],
            'provider_reward',
            7,
            0,
            Reader_Experience::day(),
            ['provider' => 'stats_alpha', 'provider_label' => 'Historical source'],
        );
    });
    $beta = $event('beta', 0, $users[1], 'stats_beta');
    $partial = $event('partial', 8, $users[1]);
    $verify(
        'Real held, rule-zero and capped receipts persist',
        !is_wp_error($zero_rule) && !is_wp_error($zero_hold) && !is_wp_error($zero_cap),
    );
    $verify(
        'Positive partial truncation remains a positive receipt',
        !is_wp_error($beta) &&
            !is_wp_error($partial) &&
            (int) $wpdb->get_var(
                $wpdb->prepare("SELECT xp FROM $table WHERE id=%d", $partial['event_id']),
            ) === 3,
    );
    $day = '2040-06-16';
    $verify(
        'Real positive and zero receipts revoke on next local day',
        !is_wp_error(wp_xp_core_revoke_activity('stats_alpha', 'positive', $users[0], 1)) &&
            !is_wp_error(wp_xp_core_revoke_activity('stats_alpha', 'quota-revoked', $users[0], 2)),
    );
    $range = ['period' => 'custom', 'from' => '2040-06-15', 'to' => '2040-06-16'];
    $before = $snapshot();
    $queries = [];
    $observations = array_fill_keys(array_keys($observations), 0);
    $observe = static function ($sql) use (&$queries) {
        if (str_contains($sql, 'GROUP BY provider ORDER BY provider')) {
            $queries[] = $sql;
        }
        return $sql;
    };
    add_filter('query', $observe);
    try {
        $data = WP_XP_Core_Insights::provider_sources($range);
    } finally {
        remove_filter('query', $observe);
    }
    $verify(
        'All provider groups share one aggregate query',
        !is_wp_error($data) && count($queries) === 1 && array_sum($observations) === 0,
    );
    $rows = array_column($data['rows'], null, 'provider');
    $alpha = $rows['stats_alpha'];
    $verify(
        'Raw activities, zero reversals and net XP aggregate exactly',
        $alpha['activity_count'] === 8 &&
            $alpha['reversal_count'] === 2 &&
            $alpha['user_count'] === 2 &&
            $alpha['positive_xp'] === 8 &&
            $alpha['negative_xp'] === -5 &&
            $alpha['net_xp'] === 3,
    );
    $verify(
        'Each effective zero reason counts one actual original receipt',
        array_intersect_key(
            $alpha,
            array_flip(['zero_quota', 'zero_hold', 'zero_cap', 'zero_rule', 'zero_other']),
        ) === [
            'zero_quota' => 1,
            'zero_hold' => 1,
            'zero_cap' => 1,
            'zero_rule' => 1,
            'zero_other' => 1,
        ],
    );
    $verify(
        'Per-source people are not additive across overlapping sources',
        $rows['stats_beta']['user_count'] === 1 && !isset($data['totals']),
    );
    $links = array_column($alpha['tasks'], null, 'id');
    $verify(
        'Current task rates link sources without claiming mixed-source attribution',
        $links[$task_ids['single']]['participants'] === 2 &&
            $links[$task_ids['single']]['rate'] === 0 &&
            !$links[$task_ids['single']]['mixed_sources'] &&
            $links[$task_ids['mixed']]['mixed_sources'],
    );
    $original_day = WP_XP_Core_Insights::provider_sources([
        'period' => 'custom',
        'from' => '2040-06-15',
        'to' => '2040-06-15',
    ]);
    $original_alpha = array_column($original_day['rows'], null, 'provider')['stats_alpha'];
    $verify(
        'Reversal outside selected dates still excludes its zero original from effective reasons',
        $original_alpha['reversal_count'] === 0 &&
            $original_alpha['zero_quota'] === 1 &&
            $original_alpha['net_xp'] === 8,
    );
    $invalid = WP_XP_Core_Insights::provider_sources([
        'period' => 'custom',
        'from' => '2030-01-01',
        'to' => $day,
    ]);
    $verify('Provider statistics reject ranges longer than 366 days', $error($invalid, 'input'));
    $verify(
        'Provider statistics reject future and malformed dates',
        $error(
            WP_XP_Core_Insights::provider_sources([
                'period' => 'custom',
                'from' => $day,
                'to' => '2040-06-17',
            ]),
            'input',
        ) &&
            $error(
                WP_XP_Core_Insights::provider_sources([
                    'period' => 'custom',
                    'from' => 'bad',
                    'to' => $day,
                ]),
                'input',
            ),
    );
    $csv_queries = [];
    $observe_csv = static function ($sql) use (&$csv_queries) {
        $csv_queries[] = $sql;
        return $sql;
    };
    add_filter('query', $observe_csv);
    try {
        $csv = WP_XP_Core_Insights::provider_csv(
            $range,
            wp_create_nonce('wp_xp_core_insights_export'),
        );
    } finally {
        remove_filter('query', $observe_csv);
    }
    $verify(
        'Aggregate CSV performs no task-participation scan',
        is_string($csv) &&
            count(
                array_filter(
                    $csv_queries,
                    static fn($sql) => str_contains($sql, 'GROUP BY provider ORDER BY provider'),
                ),
            ) === 1 &&
            !array_filter(
                $csv_queries,
                static fn($sql) => str_contains($sql, 'SELECT task_index,COUNT(DISTINCT user_id)'),
            ),
    );
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, substr($csv, 3));
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', '');
    $csv_rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $csv_rows[$values[0]] = array_combine($header, $values);
    }
    fclose($stream);
    $verify(
        'CSV is bounded UTF-8 and preserves quotes/newlines without formulas',
        str_starts_with($csv, "\xEF\xBB\xBF") &&
            count($csv_rows) === 2 &&
            $csv_rows['stats_alpha']['label'] === "'" . $label &&
            $csv_rows['stats_alpha']['negative_xp'] === '-5',
    );
    $verify(
        'CSV omits personal event IDs, emails and administrative reasons',
        !in_array('user_id', $header, true) &&
            !in_array('event_id', $header, true) &&
            !str_contains($csv, 'stats-one@example.invalid') &&
            !str_contains($csv, 'Synthetic statistics hold'),
    );
    $verify(
        'CSV sanitizes control characters and whitespace-prefixed formulas',
        WP_XP_Core_Insights::csv_cell("\t\x00 =1+1") === "' =1+1" &&
            WP_XP_Core_Insights::csv_cell(-5) === '-5',
    );
    $verify(
        'CSV download hook is private and registered',
        has_action('admin_post_wp_xp_core_provider_csv') !== false &&
            !has_action('admin_post_nopriv_wp_xp_core_provider_csv'),
    );
    $verify(
        'CSV nonce is enforced',
        $error(WP_XP_Core_Insights::provider_csv($range, 'bad'), 'nonce'),
    );
    wp_set_current_user($users[0]);
    $verify(
        'Ordinary users cannot read or export provider aggregates',
        $error(WP_XP_Core_Insights::provider_sources($range), 'forbidden') &&
            $error(
                WP_XP_Core_Insights::provider_csv(
                    $range,
                    wp_create_nonce('wp_xp_core_insights_export'),
                ),
                'forbidden',
            ),
    );
    wp_set_current_user($admin->ID);
    foreach (
        ['GROUP BY provider ORDER BY provider', 'SELECT task_index,COUNT(DISTINCT user_id)']
        as $needle
    ) {
        $hit = false;
        $fault = static function ($sql) use (&$hit, $needle) {
            if (!$hit && str_contains($sql, $needle)) {
                $hit = true;
                return 'SELECT * FROM synthetic_missing_provider_statistics';
            }
            return $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $fault);
        try {
            $failure = WP_XP_Core_Insights::provider_sources($range);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($suppressed);
        }
        $verify(
            'Provider read failure stays an error: ' . $needle,
            $hit && $error($failure, 'storage'),
        );
    }
    $verify(
        'Provider statistics and export leave all source state unchanged',
        $snapshot() === $before,
    );
    $definitions = [];
    for ($i = 0; $i < 50; $i++) {
        $id = 't_' . substr(hash('sha256', 'availability-task-' . $i), 0, 16);
        $steps = [];
        for ($j = 0; $j < 8; $j++) {
            $steps[] = [
                'kind' => 'provider_reward',
                'provider_id' => $j % 2 ? 'stats_beta' : 'stats_alpha',
                'count' => 1000,
                'mode' => 'events',
                'post_ids' => [],
                'category_ids' => [],
            ];
        }
        $definitions[$id] = [
            'id' => $id,
            'title' => 'Availability task ' . $i,
            'description' => '',
            'icon' => '',
            'type' => 'automatic',
            'period' => 'once',
            'enabled' => true,
            'steps' => $steps,
            'prerequisites' => [],
            'starts_on' => '',
            'ends_on' => '',
            'version' => 1,
            'start_event_id' => 0,
            'number' => $i + 1,
            'created_at' => time(),
        ];
    }
    update_option(WP_XP_Core_Achievements::OPTION, $definitions, false);
    WP_XP_Core_Achievements::clear_cache();
    wp_set_current_user($users[0]);
    $observations = array_fill_keys(array_keys($observations), 0);
    $first = WP_XP_Core_Achievements::personal();
    $verify(
        'Fifty tasks with eight steps observe each source once instead of 400 calls',
        count($first['tasks']) === 50 && $observations === ['stats_alpha' => 1, 'stats_beta' => 1],
    );
    $observations = array_fill_keys(array_keys($observations), 0);
    $second = WP_XP_Core_Achievements::personal();
    $verify(
        'Repeated personal calls reobserve sources without persistent caching or output changes',
        $second === $first && $observations === ['stats_alpha' => 1, 'stats_beta' => 1],
    );
    wp_set_current_user($users[1]);
    $observations = array_fill_keys(array_keys($observations), 0);
    $other = WP_XP_Core_Achievements::personal();
    $verify(
        'Switching users reobserves sources and separates progress',
        $observations === ['stats_alpha' => 1, 'stats_beta' => 1] &&
            $other['tasks'][0]['steps'][0]['current'] !== $first['tasks'][0]['steps'][0]['current'],
    );
    $throw_beta = true;
    $observations = array_fill_keys(array_keys($observations), 0);
    $unavailable = WP_XP_Core_Achievements::personal();
    $verify(
        'Unavailable-source exceptions are observed once and remain explicit',
        $observations === ['stats_alpha' => 1, 'stats_beta' => 1] &&
            !$unavailable['tasks'][0]['steps'][1]['source_available'],
    );
    $throw_beta = false;
    $fresh = WP_XP_Core_Achievements::personal();
    $verify(
        'Source recovery is observed on the next call',
        $fresh['tasks'][0]['steps'][1]['source_available'],
    );
    $hit = false;
    $fault = static function ($sql) use (&$hit) {
        if (!$hit && str_contains($sql, 'COUNT(DISTINCT CASE')) {
            $hit = true;
            return 'SELECT * FROM synthetic_missing_availability_progress';
        }
        return $sql;
    };
    $suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $fault);
    $failed = false;
    try {
        WP_XP_Core_Achievements::personal();
    } catch (RuntimeException $e) {
        $failed = true;
    } finally {
        remove_filter('query', $fault);
        $wpdb->suppress_errors($suppressed);
    }
    $verify('Fifty-task failed SQL read cannot become empty progress', $hit && $failed);
    $observations = array_fill_keys(array_keys($observations), 0);
    $recovered = WP_XP_Core_Achievements::personal();
    $verify(
        'A failed large progress read is retried without persistent failure caching',
        count($recovered['tasks']) === 50 &&
            $observations === ['stats_alpha' => 1, 'stats_beta' => 1],
    );
    $scripts = $GLOBALS['wp_scripts'] ?? null;
    $styles = $GLOBALS['wp_styles'] ?? null;
    try {
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $GLOBALS['wp_styles'] = new WP_Styles();
        wp_set_current_user(0);
        Reader_Experience::assets();
        $anonymous_scripts = wp_scripts();
        $verify(
            'Anonymous assets keep the registered queued handle without an application URL',
            isset($anonymous_scripts->registered['reader_experience']) &&
                $anonymous_scripts->registered['reader_experience']->src === false &&
                in_array('reader_experience', $anonymous_scripts->queue, true),
        );
        ob_start();
        wp_print_scripts(['reader_experience']);
        $anonymous_html = ob_get_clean();
        $verify(
            'Anonymous inline compatibility globals and translation bridge still print',
            str_contains($anonymous_html, 'window.ReaderExperience=') &&
                str_contains($anonymous_html, 'window.WPXPI18n=') &&
                str_contains($anonymous_html, 'window.WPXPLocale=') &&
                !preg_match('/<script[^>]+src=/i', $anonymous_html),
        );
        $verify('Anonymous CSS remains registered', wp_style_is('reader_experience', 'enqueued'));
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        wp_set_current_user($users[0]);
        Reader_Experience::assets();
        $verify(
            'Separate logged-in asset queue retains the application script and signed config',
            is_string(wp_scripts()->registered['reader_experience']->src) &&
                str_contains(wp_scripts()->registered['reader_experience']->src, 'app-') &&
                str_contains(
                    implode('', wp_scripts()->registered['reader_experience']->extra['before']),
                    '"logged":true',
                ),
        );
    } finally {
        $GLOBALS['wp_scripts'] = $scripts;
        $GLOBALS['wp_styles'] = $styles;
    }
} finally {
    wp_set_current_user($admin->ID);
    $save($original_rules);
    update_option(WP_XP_Core_Achievements::OPTION, $original_tasks, false);
    WP_XP_Core_Achievements::clear_cache();
    remove_filter('wp_date', $clock, 10);
}
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
