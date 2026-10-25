<?php
/** Portable policy, provider, updater and privacy risks on a dedicated empty local lab. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
$checks = [];
global $wpdb;
$verify = static function (string $name, $value) use (&$checks) {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$error = static fn($value, $code) => is_wp_error($value) && $value->get_error_code() === $code;
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$nonce = wp_create_nonce('wp_xp_core_configuration');
$export = WP_XP_Core_Configuration::export($nonce);
$verify(
    'Export contains only portable policy sections',
    is_array($export) &&
        array_keys($export) === ['schema', 'rules', 'growth', 'tasks'] &&
        !isset(
            $export['growth']['streak']['generation'],
            $export['growth']['streak']['start_event_id'],
            $export['rules']['retired_milestones'],
        ),
);
foreach ($export['tasks'] as $task) {
    $verify(
        'Export omits internal task state',
        !array_diff(array_keys($task), WP_XP_Core_Configuration::FIELDS),
    );
}
$encode = static fn($doc) => wp_json_encode($doc, JSON_UNESCAPED_UNICODE);
$preview = static fn($doc, $mode = 'update') => WP_XP_Core_Configuration::preview(
    $encode($doc),
    $mode,
    wp_create_nonce('wp_xp_core_configuration'),
);
$apply = static fn($doc, $plan, $mode = 'update') => WP_XP_Core_Configuration::apply(
    $encode($doc),
    $mode,
    $plan['revision'],
    $plan['proof'],
    wp_create_nonce('wp_xp_core_configuration'),
);
$before = WP_XP_Core_Achievements::definitions();
$plan = $preview($export);
$verify(
    'Same-site round trip reports no rules or growth changes',
    !is_wp_error($plan) && !$plan['rules_changed'] && !$plan['growth_changed'],
);
$verify(
    'Same-site round trip does not change task versions',
    !array_filter(
        $plan['changes'],
        static fn($change) => !in_array($change['status'], ['unchanged', 'preserved'], true),
    ),
);
$verify(
    'Same-site round trip applies without historical boundary changes',
    !is_wp_error($apply($export, $plan)) && WP_XP_Core_Achievements::definitions() === $before,
);
// Exercise the maximum legal scoped structure using owned synthetic definitions only.
$owned_definitions = WP_XP_Core_Achievements::definitions();
$large = [];
for ($i = 0; $i < 50; $i++) {
    $id = 't_' . str_pad(dechex($i + 4096), 16, '0', STR_PAD_LEFT);
    $task = WP_XP_Core_Achievements::validate(
        [
            'id' => $id,
            'title' => 'Synthetic maximum structure ' . $i,
            'icon' => '◇',
            'type' => 'automatic',
            'period' => 'once',
            'enabled' => true,
            'steps' => array_fill(0, 8, [
                'kind' => 'visit',
                'count' => 1000,
                'mode' => 'objects',
                'post_ids' => range(1000000000, 1000000099),
                'category_ids' => range(2000000000, 2000000099),
            ]),
        ],
        false,
    );
    $large[$id] = $task + [
        'number' => $i + 1,
        'version' => 1,
        'start_event_id' => 0,
        'created_at' => time(),
    ];
}
Reader_Experience::transaction(function () use ($large) {
    Reader_Experience::touch_option(WP_XP_Core_Achievements::OPTION);
    update_option(WP_XP_Core_Achievements::OPTION, $large, false);
});
try {
    $large_doc = WP_XP_Core_Configuration::export($nonce);
    $large_json = wp_json_encode($large_doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $large_plan = WP_XP_Core_Configuration::preview($large_json, 'update', $nonce);
    $verify(
        'Maximum 50x8x100+100 ID pretty export fits the bounded import limit',
        strlen($large_json) > 262144 &&
            strlen($large_json) <= WP_XP_Core_Configuration::MAX_BYTES &&
            !is_wp_error($large_plan),
    );
    $verify(
        'Maximum legal scoped configuration round trips without a new version',
        !is_wp_error(
            WP_XP_Core_Configuration::apply(
                $large_json,
                'update',
                $large_plan['revision'],
                $large_plan['proof'],
                $nonce,
            ),
        ) && WP_XP_Core_Achievements::definitions() === $large,
    );
} finally {
    Reader_Experience::transaction(function () use ($owned_definitions) {
        Reader_Experience::touch_option(WP_XP_Core_Achievements::OPTION);
        update_option(WP_XP_Core_Achievements::OPTION, $owned_definitions, false);
    });
    WP_XP_Core_Achievements::clear_cache();
}
$invalid = $export;
$invalid['balances'] = [];
$verify('Unknown configuration sections are rejected', $error($preview($invalid), 'input'));
$invalid = $export;
$invalid['growth']['streak']['start_event_id'] = 0;
$verify('Imported generation and boundary are rejected', $error($preview($invalid), 'input'));
$invalid = $export;
$invalid['tasks'][] = ['id' => 't_7777777777777777', 'version' => 1];
$verify('Imported task versions are rejected', $error($preview($invalid), 'input'));
$verify(
    'Oversized configuration is rejected',
    $error(
        WP_XP_Core_Configuration::preview(
            str_repeat(' ', WP_XP_Core_Configuration::MAX_BYTES + 1),
            'update',
            $nonce,
        ),
        'input',
    ),
);
$foreign = $export;
$foreign['tasks'] = [];
foreach (['view', 'like'] as $kind) {
    foreach ($foreign['rules']['milestones'][$kind] as $i => &$milestone) {
        $milestone['id'] = 'foreign_' . $kind . '_' . $i;
    }
}
unset($milestone);
$foreign_plan = $preview($foreign, 'template');
$verify(
    'Foreign same-threshold milestones reuse existing identities without rule changes',
    !is_wp_error($foreign_plan) && !$foreign_plan['rules_changed'],
);
$timezone_rules = WP_XP_Core_Settings::rules();
$timezone_rules['timezone'] = $timezone_rules['timezone'] === 'UTC' ? 'Asia/Shanghai' : 'UTC';
$fail_timezone = static fn($sql) => $sql ===
'SELECT id FROM ' . Reader_Experience::table() . ' LIMIT 1'
    ? 'INVALID SYNTHETIC TIMEZONE EVIDENCE'
    : $sql;
add_filter('query', $fail_timezone);
$suppressed = $wpdb->suppress_errors(true);
try {
    $timezone_result = WP_XP_Core_Settings::save(
        $timezone_rules,
        wp_create_nonce('wp_xp_core_settings'),
        WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
    );
    $timezone_doc = $export;
    $timezone_doc['rules']['timezone'] = $timezone_rules['timezone'];
    $timezone_preview = $preview($timezone_doc);
} finally {
    remove_filter('query', $fail_timezone);
    $wpdb->suppress_errors($suppressed);
}
$verify(
    'Timezone query failures cannot authorize either settings save or configuration import',
    $error($timezone_result, 'storage') &&
        is_wp_error($timezone_preview) &&
        WP_XP_Core_Settings::rules()['timezone'] !== $timezone_rules['timezone'],
);
$doc = $export;
$doc['tasks'] = [
    [
        'id' => 't_7676767676767676',
        'title' => 'Synthetic imported target',
        'description' => 'User text stays literal: 经验规则',
        'icon' => '◇',
        'type' => 'automatic',
        'period' => 'once',
        'enabled' => true,
        'steps' => [['kind' => 'checkin', 'count' => 1]],
        'reward_xp' => 0,
    ],
    [
        'id' => 't_7878787878787878',
        'title' => 'Synthetic imported followup',
        'icon' => '☆',
        'type' => 'review',
        'period' => 'once',
        'enabled' => true,
        'steps' => [],
        'prerequisites' => [['id' => 't_7676767676767676', 'scope' => 'lifetime']],
    ],
];
$plan = $preview($doc, 'template');
$verify(
    'Foreign template previews new tasks',
    !is_wp_error($plan) &&
        count(array_filter($plan['changes'], static fn($c) => $c['status'] === 'new')) === 2,
);
$verify(
    'Preview alone never writes definitions',
    WP_XP_Core_Achievements::definitions() === $before,
);
$verify(
    'Changed JSON cannot reuse another preview',
    $error($apply(array_replace($doc, ['schema' => 2]), $plan, 'template'), 'preview'),
);
$done = $apply($doc, $plan, 'template');
$verify('Foreign template applies explicitly', !is_wp_error($done));
$imported = WP_XP_Core_Achievements::definitions();
$first = $plan['changes'][0]['id'];
$second = $plan['changes'][1]['id'];
$verify(
    'Unknown task IDs are remapped and prerequisite references reconnect',
    $first !== 't_7676767676767676' &&
        $second !== 't_7878787878787878' &&
        $imported[$second]['prerequisites'][0]['id'] === $first,
);
$again = $preview($doc, 'template');
$verify(
    'Repeated template preserves versions and boundaries',
    !is_wp_error($again) &&
        !array_filter(
            $again['changes'],
            static fn($c) => !in_array($c['status'], ['unchanged', 'preserved'], true),
        ) &&
        !is_wp_error($apply($doc, $again, 'template')) &&
        WP_XP_Core_Achievements::definitions() === $imported,
);
$changed = $doc;
$changed['tasks'][0]['steps'][0]['count'] = 2;
$stale = $preview($changed, 'template');
$rules = WP_XP_Core_Settings::rules();
$rules['visit_xp'] += 1;
$verify(
    'Concurrent rule edit succeeds',
    !is_wp_error(
        WP_XP_Core_Settings::save(
            $rules,
            wp_create_nonce('wp_xp_core_settings'),
            WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
        ),
    ),
);
$verify(
    'A stale preview rejects all sections without task writes',
    $error($apply($changed, $stale, 'template'), 'conflict') &&
        WP_XP_Core_Achievements::definitions() === $imported,
);
$changed['rules'] = $rules;
unset($changed['rules']['retired_milestones']);
$plan = $preview($changed, 'template');
$verify(
    'Changed completion conditions preview a new version',
    !is_wp_error($plan) && $plan['changes'][0]['status'] === 'new_version',
);
global $wpdb;
$old_rules = get_option(WP_XP_Core_Settings::OPTION);
$old_tasks = get_option(WP_XP_Core_Achievements::OPTION);
$changed['rules']['visit_xp'] += 1;
$plan = $preview($changed, 'template');
$inject = static fn($sql) => str_contains($sql, 'UPDATE') &&
str_contains($sql, WP_XP_Core_Achievements::OPTION)
    ? 'INVALID SYNTHETIC IMPORT STATEMENT'
    : $sql;
add_filter('query', $inject);
$wpdb->suppress_errors(true);
$result = $apply($changed, $plan, 'template');
remove_filter('query', $inject);
$wpdb->suppress_errors(false);
wp_cache_delete('alloptions', 'options');
wp_cache_delete(WP_XP_Core_Settings::OPTION, 'options');
wp_cache_delete(WP_XP_Core_Achievements::OPTION, 'options');
$verify(
    'Import failure rolls back rules, tasks and audit together',
    $error($result, 'storage') &&
        get_option(WP_XP_Core_Settings::OPTION) === $old_rules &&
        get_option(WP_XP_Core_Achievements::OPTION) === $old_tasks,
);
$rules = WP_XP_Core_Settings::rules();
$rules['activity_caps'] = ['day' => 1, 'week' => 1];
$save = static fn($r) => WP_XP_Core_Settings::save(
    $r,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$verify(
    'Provider registers only server authorization metadata',
    wp_xp_core_register_activity_provider('synthetic', [
        'label' => 'Synthetic provider',
        'authorize' => static fn($uid, $object, $context) => ($context['verified'] ?? false) ===
            true,
    ]),
);
$verify(
    'Provider registration rejects reward amounts in the declaration',
    !wp_xp_core_register_activity_provider('unsafe', [
        'label' => 'Unsafe',
        'xp' => 999,
        'authorize' => static fn() => true,
    ]),
);
$uid = wp_insert_user([
    'user_login' => 'fixture-product-reader',
    'user_email' => 'fixture-product-reader@example.invalid',
    'user_pass' => wp_generate_password(32),
    'role' => 'subscriber',
]);
$verify('Product test recipient exists', !is_wp_error($uid));
$verify(
    'Trusted provider defaults to disabled',
    $error(
        wp_xp_core_record_activity('synthetic', 'first', $uid, 0, ['verified' => true]),
        'disabled',
    ),
);
$rules['providers'] = ['synthetic' => ['enabled' => true, 'xp' => 3]];
$verify('Administrator saves provider policy', !is_wp_error($save($rules)));
$verify(
    'Unverified server evidence is refused',
    $error(wp_xp_core_record_activity('synthetic', 'bad', $uid, 0, []), 'ineligible'),
);
$one = wp_xp_core_record_activity('synthetic', 'first', $uid, 0, ['verified' => true]);
$two = wp_xp_core_record_activity('synthetic', 'first', $uid, 0, ['verified' => true]);
$event = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT * FROM ' . Reader_Experience::table() . ' WHERE id=%d',
        $one['event_id'],
    ),
    ARRAY_A,
);
$verify(
    'Provider obeys saved XP, gross cap and stable idempotency',
    !is_wp_error($one) &&
        !$one['duplicate'] &&
        $two['duplicate'] &&
        (int) $event['xp'] === 1 &&
        $event['kind'] === 'provider_reward' &&
        json_decode($event['detail'], true)['activity_cap']['requested_xp'] === 3,
);
$verify(
    'Provider source is included in public activity and cap kinds',
    in_array('provider_reward', WP_XP_Core_Insights::ACTIVITY_KINDS, true) &&
        in_array('provider_reward', WP_XP_Core_Fairness::FIRST_REWARD_KINDS, true) &&
        !in_array('provider_reward', WP_XP_Core_Achievements::KINDS, true),
);
$verify(
    'Provider cannot reassign an existing event',
    $error(
        wp_xp_core_record_activity('synthetic', 'first', $admin->ID, 0, ['verified' => true]),
        'conflict',
    ),
);
$verify(
    'Provider context cannot become stored private payload',
    !isset(json_decode($event['detail'], true)['verified']),
);
$exports = WP_XP_Core_Privacy::export('fixture-product-reader@example.invalid', 1);
$verify(
    'WordPress privacy export is recipient scoped and complete for one row',
    $exports['done'] &&
        count($exports['data']) === 2 &&
        !str_contains(wp_json_encode($exports), 'retry_actor'),
);
$verify(
    'Unknown email and invalid pages export no records',
    WP_XP_Core_Privacy::export('missing@example.invalid', 1) === ['data' => [], 'done' => true] &&
        WP_XP_Core_Privacy::export('fixture-product-reader@example.invalid', 0) === [
            'data' => [],
            'done' => true,
        ],
);
Reader_Experience::transaction(function () use ($uid) {
    for ($i = 0; $i < 51; $i++) {
        Reader_Experience::record(
            'synthetic-export:' . $i,
            $uid,
            'synthetic_export',
            0,
            0,
            Reader_Experience::day(),
            [
                'title' => 'User literal 经验规则',
                'reason' => 'INTERNAL-REASON',
                'actor' => 98765,
                'other_user_email' => 'SECRET@example.invalid',
            ],
        );
    }
});
$a = WP_XP_Core_Privacy::export('fixture-product-reader@example.invalid', 1);
$b = WP_XP_Core_Privacy::export('fixture-product-reader@example.invalid', 2);
$verify(
    'Privacy export pages have bounded disjoint IDs and preserve literal user data',
    !$a['done'] &&
        $b['done'] &&
        count($a['data']) === 51 &&
        count($b['data']) === 2 &&
        !array_intersect(
            array_column($a['data'], 'item_id'),
            array_column($b['data'], 'item_id'),
        ) &&
        str_contains(wp_json_encode($a, JSON_UNESCAPED_UNICODE), 'User literal 经验规则') &&
        !str_contains(wp_json_encode($a), 'INTERNAL-REASON') &&
        !str_contains(wp_json_encode($a), 'SECRET@example.invalid'),
);
$verify(
    'WordPress exporter is registered without an eraser',
    isset(apply_filters('wp_privacy_personal_data_exporters', [])['wp-xp-core']) &&
        !isset(apply_filters('wp_privacy_personal_data_erasers', [])['wp-xp-core']),
);
$release = static function ($version, $pre = false) {
    return [
        'tag_name' => 'v' . $version,
        'draft' => false,
        'prerelease' => $pre,
        'assets' => [
            [
                'name' => 'wp-xp-core-' . $version . '.zip',
                'size' => 1024,
                'browser_download_url' =>
                    'https://github.com/wzf2000/wp-xp-core/releases/download/v' .
                    $version .
                    '/wp-xp-core-' .
                    $version .
                    '.zip',
            ],
        ],
    ];
};
$verify(
    'Update checks default to disabled',
    WP_XP_Core_Updates::settings() === ['enabled' => false, 'channel' => 'stable'],
);
$verify(
    'Stable updates never downgrade the current RC',
    WP_XP_Core_Updates::candidate([$release('1.9.9')], 'stable', '2.0.0-rc.3') === null,
);
$verify(
    'Stable ignores prereleases and chooses highest newer stable',
    WP_XP_Core_Updates::candidate(
        [$release('2.0.1'), $release('2.1.0-rc.1', true), $release('2.0.0')],
        'stable',
        '2.0.0-rc.3',
    )['version'] === '2.0.1',
);
$verify(
    'Prerelease channel chooses by version rather than API order',
    WP_XP_Core_Updates::candidate(
        [$release('2.1.0-rc.1', true), $release('2.0.2')],
        'prerelease',
        '2.0.0-rc.3',
    )['version'] === '2.1.0-rc.1',
);
$unsafe = $release('9.0.0');
$unsafe['assets'][0]['browser_download_url'] = 'https://evil.invalid/package.zip';
$verify(
    'Update assets require the fixed official ZIP URL',
    WP_XP_Core_Updates::candidate([$unsafe], 'stable', '2.0.0') === null,
);
$stale_update = (object) [
    'response' => ['wp-xp-core/wp-xp-core.php' => (object) ['package' => 'https://evil.invalid']],
];
$verify(
    'Disabled updater removes stale own update entries',
    !isset(WP_XP_Core_Updates::filter($stale_update)->response['wp-xp-core/wp-xp-core.php']),
);
$verify(
    'Updater rejects arbitrary source configuration',
    $error(
        WP_XP_Core_Updates::save(
            [
                'enabled' => true,
                'channel' => 'stable',
                'revision' => WP_XP_Core_Updates::revision(),
                'url' => 'https://evil.invalid',
            ],
            $nonce,
        ),
        'input',
    ),
);
$verify(
    'Dynamic editor blocks register alongside existing shortcodes',
    WP_Block_Type_Registry::get_instance()->is_registered('wp-xp-core/growth') &&
        WP_Block_Type_Registry::get_instance()->is_registered('wp-xp-core/leaderboard') &&
        shortcode_exists('reader_experience') &&
        shortcode_exists('xp_leaderboard'),
);
wp_set_current_user($uid);
$verify(
    'Configuration export and preview enforce administration permissions',
    $error(WP_XP_Core_Configuration::export($nonce), 'forbidden') &&
        $error($preview($export), 'forbidden'),
);
wp_set_current_user($admin->ID);
$verify(
    'Configuration export enforces nonce',
    $error(WP_XP_Core_Configuration::export('bad'), 'nonce'),
);
$old_locale = determine_locale();
switch_to_locale('en_US');
WP_XP_Core_I18n::load();
$verify(
    'Packaged English gettext catalog changes fixed interface labels',
    wp_xp_core_t('我的成长') === 'My growth' && wp_xp_core_t('配置工具') === 'Configuration tools',
);
$english = WP_XP_Core_Growth_UI::panel();
$verify(
    'English panel translates fixed HTML nodes without translating user task text',
    str_contains($english, 'My growth') && str_contains($english, 'Site notifications'),
);
restore_previous_locale();
WP_XP_Core_I18n::load();
$verify('Switching back to Chinese restores fixed labels', wp_xp_core_t('我的成长') === '我的成长');
$restore_doc = WP_XP_Core_Configuration::export(wp_create_nonce('wp_xp_core_configuration'));
$restore_doc['rules'] = $export['rules'];
$restore_doc['tasks'] = [];
$restore_plan = $preview($restore_doc);
$verify(
    'Product fixtures restore prior policy without changing history',
    !is_wp_error($restore_plan) && !is_wp_error($apply($restore_doc, $restore_plan)),
);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
