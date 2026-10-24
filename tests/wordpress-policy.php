<?php
/** Policy and observer contracts on the dedicated synthetic WordPress instance. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
$checks = [];
$verify = static function ($name, $value) use (&$checks) {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$save = static fn($rules) => WP_XP_Core_Settings::save(
    $rules,
    wp_create_nonce('wp_xp_core_settings'),
    WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
);
$original = WP_XP_Core_Settings::rules();
$rules = $original;
$rules['dwell_seconds'] = 30;
$verify(
    'Real WordPress can save configurable dwell time',
    $save($rules) === true && WP_XP_Core_Settings::rules()['dwell_seconds'] === 30,
);
$rules['milestones']['view'][] = ['id' => 'm_synthetic_fourth', 'threshold' => 10000, 'xp' => 30];
$verify(
    'Fourth stable milestone saves',
    $save($rules) === true && count(WP_XP_Core_Settings::rules()['milestones']['view']) === 4,
);
array_pop($rules['milestones']['view']);
$verify(
    'Removing a milestone retires its identifier',
    $save($rules) === true &&
        in_array(
            'm_synthetic_fourth',
            WP_XP_Core_Settings::rules()['retired_milestones']['view'],
            true,
        ),
);
$rules = WP_XP_Core_Settings::rules();
$rules['milestones']['view'][] = ['id' => 'm_synthetic_fourth', 'threshold' => 10000, 'xp' => 30];
$verify('Retired milestone cannot be reused', is_wp_error($save($rules)));
$rules = WP_XP_Core_Settings::rules();
$rules['timezone'] = 'UTC';
$verify('Existing activity protects the daily time boundary', is_wp_error($save($rules)));
$seen = [];
$listener = static function ($event) use (&$seen) {
    global $wpdb;
    $seen[] = [
        'event' => $event,
        'active' => Reader_Experience::transaction_active(),
        'lock_free' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT IS_FREE_LOCK(%s)', reader_experience_lock('event_lock')),
        ),
    ];
};
add_action('wp_xp_core_event_committed', $listener);
$reader = get_user_by('login', 'fixture-reader');
$key = 'synthetic-policy:' . wp_generate_uuid4();
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        $key,
        (int) $reader->ID,
        'visit',
        0,
        0,
        Reader_Experience::day(),
    ),
);
$verify(
    'Observer runs after COMMIT and lock release',
    count($seen) === 1 && !$seen[0]['active'] && $seen[0]['lock_free'] === 1,
);
$verify(
    'New event carries its policy revision',
    isset($seen[0]['event']['detail']['rule_revision']),
);
Reader_Experience::transaction(
    static fn() => Reader_Experience::record(
        $key,
        (int) $reader->ID,
        'visit',
        0,
        0,
        Reader_Experience::day(),
    ),
);
$verify('Duplicate event does not notify twice', count($seen) === 1);
$badkey = 'synthetic-rollback:' . wp_generate_uuid4();
try {
    Reader_Experience::transaction(static function () use ($badkey, $reader) {
        Reader_Experience::record(
            $badkey,
            (int) $reader->ID,
            'visit',
            0,
            0,
            Reader_Experience::day(),
        );
        throw new RuntimeException('synthetic rollback');
    });
} catch (RuntimeException $e) {
}
$verify(
    'Rolled back event neither persists nor notifies',
    !Reader_Experience::exists($badkey) && count($seen) === 1,
);
remove_action('wp_xp_core_event_committed', $listener);
$original['retired_milestones'] = WP_XP_Core_Settings::rules()['retired_milestones'];
$verify(
    'Original active rules restore without reviving retired identifiers',
    $save($original) === true,
);
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
