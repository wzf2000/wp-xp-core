<?php
/** Rules and commit notifications run against the same synthetic transactional storage as the ledger. */
require __DIR__ . '/runtime.php';
$wpdb = new FixtureStorage();
$checks = 0;
$GLOBALS['capable'] = true;
$GLOBALS['logged'] = true;
$GLOBALS['nonce_action'] = 'wp_xp_core_settings';
function save_policy(array $rules)
{
    return WP_XP_Core_Settings::save(
        $rules,
        'fixture-nonce',
        WP_XP_Core_Settings::revision(get_option(WP_XP_Core_Settings::OPTION, null)),
    );
}
$defaults = WP_XP_Core_Settings::defaults();
$extensions = array_fill_keys(
    ['activities', 'dwell_seconds', 'timezone', 'scope', 'milestones', 'retired_milestones'],
    true,
);
$legacy = array_diff_key($defaults, $extensions);
verify(
    'legacy policies gain default extensions only in memory',
    WP_XP_Core_Settings::validate($legacy) === $defaults &&
        get_option(WP_XP_Core_Settings::OPTION, null) === null,
);
$rules = $defaults;
$rules['timezone'] = 'UTC';
verify('unused site may choose timezone', save_policy($rules) === true);
verify(
    'default activities preserve all original awards',
    !in_array(false, $defaults['activities'], true) &&
        $defaults['dwell_seconds'] === 15 &&
        $defaults['timezone'] === 'Asia/Shanghai',
);
foreach (
    [
        ['dwell_seconds' => 4],
        ['dwell_seconds' => 3601],
        ['timezone' => 'fake/timezone'],
        ['scope' => ['post_ids' => '-1', 'category_ids' => []]],
    ]
    as $invalid
) {
    verify(
        'invalid extension rejected',
        save_policy(array_replace($rules, $invalid)) instanceof WP_Error,
    );
}
verify('audit requires a real transaction', !Reader_Experience::transaction_active());
fails(
    'audit cannot be written outside a transaction',
    fn() => WP_XP_Core_Management::audit('test', null, []),
);
fails(
    'touching options requires a transaction',
    fn() => Reader_Experience::touch_option('fixture_option'),
);
$events = [];
$level_changes = [];
$GLOBALS['fixture_hooks']['wp_xp_core_event_committed'] = [
    function ($event) use (&$events) {
        verify(
            'notification follows lock release and commit',
            !Reader_Experience::transaction_active(),
        );
        $events[] = $event;
    },
];
$GLOBALS['fixture_hooks']['wp_xp_core_level_changed'] = [
    function ($event) use (&$level_changes) {
        $level_changes[] = $event;
    },
];
Reader_Experience::transaction(
    fn() => Reader_Experience::record('policy:one', 7, 'checkin', 0, 6, Reader_Experience::day()),
);
verify(
    'event contains authoritative rule revision',
    $events[0]['detail']['rule_revision'] === WP_XP_Core_Settings::policy_revision(),
);
verify(
    'event payload has before and after balances',
    $events[0]['before_xp'] === 0 &&
        $events[0]['after_xp'] === 6 &&
        $events[0]['key'] === 'policy:one',
);
verify(
    'level change notification emitted',
    count($level_changes) === 1 &&
        $level_changes[0]['before_level'] === 1 &&
        $level_changes[0]['after_level'] === 2,
);
Reader_Experience::transaction(
    fn() => Reader_Experience::record('policy:one', 7, 'checkin', 0, 6, Reader_Experience::day()),
);
verify('duplicate record emits no event', count($events) === 1);
fails(
    'rollback discards queued notifications',
    fn() => Reader_Experience::transaction(function () {
        Reader_Experience::record('policy:rollback', 7, 'checkin', 0, 3, Reader_Experience::day());
        throw new RuntimeException('synthetic failure');
    }),
);
verify(
    'rollback emits no event',
    count($events) === 1 && !Reader_Experience::exists('policy:rollback'),
);
fails(
    'nested transaction rejected',
    fn() => Reader_Experience::transaction(fn() => Reader_Experience::transaction(fn() => null)),
);
$GLOBALS['fixture_hooks']['wp_xp_core_event_committed'] = [
    function () {
        throw new RuntimeException('synthetic observer');
    },
];
verify(
    'observer exception cannot fail committed operation',
    Reader_Experience::transaction(
        fn() => Reader_Experience::record(
            'policy:observer',
            7,
            'visit',
            0,
            1,
            Reader_Experience::day(),
        ),
    ) === true,
);
verify('observer failure leaves award committed', Reader_Experience::exists('policy:observer'));
$GLOBALS['fixture_hooks']['wp_xp_core_event_committed'] = [
    function ($event) {
        if ($event['key'] === 'policy:outer') {
            Reader_Experience::transaction(
                fn() => Reader_Experience::record(
                    'policy:observer-child',
                    7,
                    'visit',
                    0,
                    0,
                    Reader_Experience::day(),
                ),
            );
        }
    },
];
Reader_Experience::transaction(
    fn() => Reader_Experience::record('policy:outer', 7, 'visit', 0, 0, Reader_Experience::day()),
);
verify(
    'committed observer may start a new transaction',
    Reader_Experience::exists('policy:observer-child'),
);
$GLOBALS['fixture_hooks']['wp_xp_core_event_committed'] = [];
$rules['timezone'] = 'Asia/Shanghai';
verify('timezone locked after ledger begins', save_policy($rules)->code === 'timezone_locked');
$rules['timezone'] = 'UTC';
$rules['activities']['article'] = false;
$rules['activities']['checkin'] = false;
$rules['activities']['visit'] = false;
$rules['activities']['view'] = false;
$rules['activities']['like'] = false;
verify('activity switches save', save_policy($rules) === true);
Reader_Experience::published('publish', 'draft', get_post(900));
Reader_Experience::liked(7, 900, 1);
verify(
    'disabled article and like create no markers',
    !Reader_Experience::exists('article:900') && !Reader_Experience::exists('like:7:900'),
);
$request = new class {
    public array $body = [];
    public function get_json_params()
    {
        return $this->body;
    }
    public function is_json_content_type()
    {
        return true;
    }
    public function get_body()
    {
        return json_encode($this->body);
    }
};
Reader_Experience::request($request, 'checkin');
verify(
    'disabled checkin creates no marker',
    !Reader_Experience::exists('checkin:7:' . Reader_Experience::day()),
);
$request->body = ['post_id' => 900, 'issued' => time() - 20, 'signature' => ''];
$request->body['signature'] = hash_hmac(
    'sha256',
    '7:900:' . $request->body['issued'],
    wp_salt('nonce'),
);
Reader_Experience::request($request, 'visit');
verify(
    'disabled reading and views create no marker',
    !Reader_Experience::exists('visit:7:' . Reader_Experience::day() . ':900') &&
        !Reader_Experience::exists('view:7:' . Reader_Experience::day() . ':900'),
);
$GLOBALS['comments'][901] = (object) [
    'comment_post_ID' => 900,
    'user_id' => 7,
    'comment_parent' => 0,
    'comment_approved' => '1',
    'comment_type' => 'comment',
];
Reader_Experience::comment(901);
$rules['activities']['comment'] = false;
$rules['scope']['post_ids'] = [999];
save_policy($rules);
$GLOBALS['comments'][901]->comment_approved = '0';
Reader_Experience::comment(901);
verify(
    'disabled and excluded old comment still compensates',
    (int) $wpdb->get_var(
        'SELECT SUM(xp) FROM fixture_reader_experience_events WHERE object_id=901',
    ) === 0,
);
$GLOBALS['comments'][901]->comment_approved = '1';
Reader_Experience::comment(901);
verify(
    'disabled old comment restores original amount',
    (int) $wpdb->get_var(
        'SELECT SUM(xp) FROM fixture_reader_experience_events WHERE object_id=901',
    ) === 2,
);
verify(
    'scope excludes other content and site-wide visits',
    !WP_XP_Core_Settings::in_scope(900) && !WP_XP_Core_Settings::in_scope(0),
);
$rules['scope']['category_ids'] = [12];
$GLOBALS['categories'][900] = [12];
save_policy($rules);
verify(
    'scope permits content or category match',
    WP_XP_Core_Settings::in_scope(900) && WP_XP_Core_Settings::in_scope(999),
);
$rules['activities']['visit'] = true;
$rules['dwell_seconds'] = 30;
save_policy($rules);
verify(
    'configured dwell time rejects early visit',
    Reader_Experience::request($request, 'visit')->code === 'dwell',
);
$request->body = ['post_id' => 900];
verify(
    'ticket exposes configured dwell time',
    Reader_Experience::request($request, 'ticket')['dwell_seconds'] === 30,
);

$rules = WP_XP_Core_Settings::rules();
array_shift($rules['milestones']['view']);
verify('milestone deletion saves', save_policy($rules) === true);
$rules = WP_XP_Core_Settings::rules();
verify(
    'deleted milestone is permanently retired',
    in_array('100', $rules['retired_milestones']['view'], true),
);
array_unshift($rules['milestones']['view'], ['id' => '100', 'threshold' => 100, 'xp' => 5]);
verify('retired id cannot be reused', save_policy($rules)->code === 'input');
$rules = WP_XP_Core_Settings::rules();
$rules['milestones']['view'][] = ['id' => 'm_new', 'threshold' => 2000, 'xp' => 17];
verify('new stable milestone can be added', save_policy($rules) === true);
$saved = WP_XP_Core_Settings::rules();
$old_form = array_diff_key($saved, $extensions);
$old_form['checkin_xp'] = 3;
verify(
    'old form keeps extensions and dynamic milestones',
    save_policy($old_form) === true &&
        WP_XP_Core_Settings::rules()['milestones'] === $saved['milestones'] &&
        WP_XP_Core_Settings::rules()['dwell_seconds'] === 30,
);
$GLOBALS['likes_enabled'] = false;
$forged = WP_XP_Core_Settings::rules();
$forged['activities']['like'] = !$forged['activities']['like'];
$forged['milestones']['like'] = [];
verify(
    'disabled provider preserves whole like policy',
    save_policy($forged) === true &&
        WP_XP_Core_Settings::rules()['milestones']['like'] === $saved['milestones']['like'],
);
$GLOBALS['likes_enabled'] = true;
$rules = WP_XP_Core_Settings::rules();
$rules['activities']['view'] = true;
$rules['milestones']['view'] = [['id' => 'm_once', 'threshold' => 1, 'xp' => 7]];
verify('dynamic group replaces prior slots', save_policy($rules) === true);
Reader_Experience::transaction(function () {
    Reader_Experience::record('policy:view:1', 7, 'view', 900, 0, Reader_Experience::day());
    Reader_Experience::milestones(900, 'view');
});
verify(
    'dynamic milestone uses its stable event key',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE event_key='milestone:view:900:m_once'",
    ) === 7,
);
$rules = WP_XP_Core_Settings::rules();
$rules['milestones']['view'][0]['threshold'] = 2;
$rules['milestones']['view'][0]['xp'] = 99;
save_policy($rules);
Reader_Experience::transaction(function () {
    Reader_Experience::record('policy:view:2', 7, 'view', 900, 0, Reader_Experience::day());
    Reader_Experience::milestones(900, 'view');
});
verify(
    'editing threshold and amount cannot reaward same slot',
    (int) $wpdb->get_var(
        "SELECT SUM(xp) FROM fixture_reader_experience_events WHERE event_key='milestone:view:900:m_once'",
    ) === 7,
);
$rules['activities']['article'] = true;
$rules['scope'] = ['post_ids' => [902], 'category_ids' => []];
save_policy($rules);
$wpdb->failWrite = true;
Reader_Experience::published('publish', 'draft', get_post(902));
$wpdb->failWrite = false;
$failure = WP_XP_Core_Management::failures()[0];
$retry = [
    'request_id' => '10000000-0000-4000-8000-000000000001',
    'failure_id' => $failure['id'],
    'reason' => '重试发表奖励',
];
$rules['activities']['article'] = false;
save_policy($rules);
$GLOBALS['nonce_action'] = 'wp_xp_core_manage';
verify(
    'retry respects disabled activity',
    WP_XP_Core_Management::retry($retry, 'fixture-nonce')->code === 'ineligible',
);
$GLOBALS['nonce_action'] = 'wp_xp_core_settings';
$rules['activities']['article'] = true;
$rules['scope']['post_ids'] = [999];
save_policy($rules);
$GLOBALS['nonce_action'] = 'wp_xp_core_manage';
verify(
    'retry respects current scope',
    WP_XP_Core_Management::retry($retry, 'fixture-nonce')->code === 'ineligible',
);
$GLOBALS['cache_deletions'] = [];
fails(
    'registered extension option rolls back',
    fn() => Reader_Experience::transaction(function () {
        Reader_Experience::touch_option('fixture_growth_rules');
        update_option('fixture_growth_rules', 'changed', false);
        throw new RuntimeException('synthetic extension failure');
    }),
);
verify(
    'extension rollback clears its option cache',
    get_option('fixture_growth_rules', null) === null &&
        in_array(['fixture_growth_rules', 'options'], $GLOBALS['cache_deletions'], true),
);
$fresh = WP_XP_Core_Management::user_summary(41);
verify(
    'account with no events needs no balance row',
    $fresh['balance'] === null && $fresh['consistent'],
);
echo "$checks policy and commit checks passed\n";
