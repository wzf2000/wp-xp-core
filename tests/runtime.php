<?php
/** Synthetic SQLite storage exercises production ledger methods; no WordPress site is loaded. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'fixture');
define('ARRAY_A', PDO::FETCH_ASSOC);
function add_action(...$args) {}
function register_activation_hook(...$args) {}
$GLOBALS['likes_enabled'] = true;
function pagenest_companion_like() {}
function pagenest_companion_feature($name)
{
    return $GLOBALS['likes_enabled'];
}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function wp_cache_delete(...$args)
{
    $GLOBALS['cache_deletions'][] = $args;
}
function wp_cache_flush_runtime() {}
function wp_json_encode($value)
{
    return json_encode($value);
}
function wp_date($format, ...$args)
{
    return date($format);
}
function get_userdata($uid)
{
    return $uid > 0 ? (object) ['ID' => $uid] : false;
}
function get_current_user_id()
{
    return 7;
}
function is_user_logged_in()
{
    return $GLOBALS['logged'];
}
function current_user_can(...$args)
{
    return $GLOBALS['capable'];
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'fixture-nonce';
}
class WP_Error
{
    public function __construct(public $code, public $message, public $data) {}
}
final class FixtureStorage
{
    public string $prefix = 'fixture_';
    public string $options = 'fixture_options';
    public string $usermeta = 'fixture_usermeta';
    public PDO $db;
    public bool $failWrite = false;
    public bool $lockBusy = false;
    public int $releases = 0;
    public string $last_error = '';
    public bool $failOptionWrite = false;
    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE fixture_options(option_name TEXT,option_value TEXT)');
        $this->db->exec("INSERT INTO fixture_options VALUES('reader_experience_live','1')");
        $this->db->exec(
            'CREATE TABLE fixture_usermeta(user_id INTEGER,meta_key TEXT,meta_value TEXT,UNIQUE(user_id,meta_key))',
        );
        $this->db->exec(
            'CREATE TABLE fixture_reader_experience_events(id INTEGER PRIMARY KEY AUTOINCREMENT,event_key TEXT UNIQUE,user_id INTEGER,kind TEXT,object_id INTEGER,local_day TEXT,xp INTEGER,created_at INTEGER,detail TEXT)',
        );
    }
    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback(
            '/%[sd]/',
            function ($m) use ($args, &$i) {
                $v = $args[$i++];
                return $m[0] === '%d' ? (string) (int) $v : $this->db->quote((string) $v);
            },
            $sql,
        );
    }
    public function query($sql)
    {
        return $this->db->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql);
    }
    public function get_var($sql)
    {
        if (str_contains($sql, 'GET_LOCK')) {
            return $this->lockBusy ? 0 : 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->releases++;
            return 1;
        }
        return $this->db->query($sql)->fetchColumn();
    }
    public function get_row($sql, $mode)
    {
        return $this->db->query($sql)->fetch($mode);
    }
    public function get_col($sql)
    {
        return $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function get_results($sql, $mode)
    {
        return $this->db->query($sql)->fetchAll($mode);
    }
}
$GLOBALS['wpdb'] = new FixtureStorage();
function metadata_exists($type, $uid, $key)
{
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM fixture_usermeta WHERE user_id=%d AND meta_key=%s',
            $uid,
            $key,
        ),
    ) > 0;
}
function get_user_meta($uid, $key, $single)
{
    global $wpdb;
    $v = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT meta_value FROM fixture_usermeta WHERE user_id=%d AND meta_key=%s',
            $uid,
            $key,
        ),
    );
    return $v === false ? '' : (string) $v;
}
function update_user_meta($uid, $key, $value)
{
    global $wpdb;
    if (Reader_Experience::guard(null, $uid, $key) === false || $wpdb->failWrite) {
        return false;
    }
    if (
        metadata_exists('user', $uid, $key) &&
        get_user_meta($uid, $key, true) === (string) $value
    ) {
        return false;
    }
    return $wpdb->query(
        $wpdb->prepare(
            'INSERT INTO fixture_usermeta VALUES(%d,%s,%s) ON CONFLICT(user_id,meta_key) DO UPDATE SET meta_value=excluded.meta_value',
            $uid,
            $key,
            $value,
        ),
    );
}
function get_option($key, $default = false)
{
    global $wpdb;
    $value = $wpdb->get_var(
        $wpdb->prepare('SELECT option_value FROM fixture_options WHERE option_name=%s', $key),
    );
    return $value === false
        ? $default
        : ($key === WP_XP_Core_Settings::OPTION
            ? unserialize($value)
            : $value);
}
function update_option($key, $value, $autoload = null)
{
    global $wpdb;
    if ($wpdb->failOptionWrite || get_option($key) === $value) {
        return false;
    }
    if ($key === WP_XP_Core_Settings::OPTION) {
        $value = serialize($value);
    }
    if (get_option($key) === false) {
        return $wpdb->query(
            $wpdb->prepare('INSERT INTO fixture_options VALUES(%s,%s)', $key, $value),
        ) > 0;
    }
    return $wpdb->query(
        $wpdb->prepare(
            'UPDATE fixture_options SET option_value=%s WHERE option_name=%s',
            $value,
            $key,
        ),
    ) > 0;
}
$profile = tempnam(sys_get_temp_dir(), 'wp-xp-core-runtime-');
file_put_contents($profile, json_encode(['schema_version' => 1, 'experience' => []]));
define('PAGENEST_COMPATIBILITY_PROFILE_FILE', $profile);
register_shutdown_function(static fn() => unlink($profile));
require dirname(__DIR__) . '/wp-xp-core.php';
$checks = 0;
function verify($name, $condition)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks++;
}
function fails($name, $fn)
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        verify($name, true);
        return;
    }
    verify($name, false);
}
verify('ready without a points provider', Reader_Experience::ready());
foreach (Reader_Experience::MINS as $i => $min) {
    verify('exact level threshold', Reader_Experience::level($min) === $i + 1);
    if ($i > 0) {
        verify('below level threshold', Reader_Experience::level($min - 1) === $i);
    }
}
verify('highest level capped', Reader_Experience::level(999999) === 10);
verify(
    'ordinary balance API write rejected',
    update_user_meta(7, Reader_Experience::BALANCE_META, 8) === false,
);
foreach (['add', 'update', 'delete'] as $operation) {
    verify(
        $operation . ' guard rejects balance',
        Reader_Experience::guard(null, 7, Reader_Experience::BALANCE_META) === false,
    );
}
verify(
    'other metadata guard preserved',
    Reader_Experience::guard('original', 7, 'another_key') === 'original',
);
fails('direct balance setter rejected', fn() => Reader_Experience::set_balance(7, 5));
Reader_Experience::transaction(
    fn() => Reader_Experience::record('checkin:7:fixture', 7, 'checkin', 0, 2, '2026-01-01'),
);
verify(
    'ledger and balance persisted',
    get_user_meta(7, Reader_Experience::BALANCE_META, true) === '2',
);
verify(
    'level filter reads independent balance',
    reader_experience_user_level('fallback', 7) === 'Level 1',
);
verify(
    'duplicate event ignored',
    Reader_Experience::transaction(
        fn() => Reader_Experience::record('checkin:7:fixture', 7, 'checkin', 0, 2, '2026-01-01'),
    ) === false,
);
verify(
    'duplicate does not double balance',
    get_user_meta(7, Reader_Experience::BALANCE_META, true) === '2',
);
Reader_Experience::transaction(fn() => Reader_Experience::set_balance(7, 2));
verify(
    'existing no-op balance accepted',
    get_user_meta(7, Reader_Experience::BALANCE_META, true) === '2',
);
$wpdb->failWrite = true;
fails(
    'failed update rolls event back',
    fn() => Reader_Experience::transaction(
        fn() => Reader_Experience::record('failed:7', 7, 'checkin', 0, 2, '2026-01-01'),
    ),
);
verify('failed event absent', !Reader_Experience::exists('failed:7'));
verify(
    'previous balance retained',
    get_user_meta(7, Reader_Experience::BALANCE_META, true) === '2',
);
fails(
    'missing zero balance failure is detected',
    fn() => Reader_Experience::transaction(fn() => Reader_Experience::set_balance(8, 0)),
);
$wpdb->failWrite = false;
Reader_Experience::transaction(fn() => Reader_Experience::set_balance(8, 0));
verify(
    'zero balance actually created',
    metadata_exists('user', 8, Reader_Experience::BALANCE_META),
);
Reader_Experience::transaction(fn() => Reader_Experience::set_balance(7, -2));
verify(
    'negative balance clamps to zero',
    get_user_meta(7, Reader_Experience::BALANCE_META, true) === '0',
);
verify(
    'guard restored after transactions',
    Reader_Experience::guard(null, 7, Reader_Experience::BALANCE_META) === false,
);
verify('locks released on success and failure', $wpdb->releases === 7);
$wpdb->lockBusy = true;
fails(
    'busy ledger refuses write',
    fn() => Reader_Experience::transaction(fn() => Reader_Experience::set_balance(7, 10)),
);
$wpdb->lockBusy = false;
update_user_meta(7, 'max_2048_score_weekly', 42);
update_user_meta(8, 'max_2048_fib_score_weekly', 51);
update_user_meta(7, 'max_2048_score', 777);
$GLOBALS['cache_deletions'] = [];
Reader_Experience::weekly();
verify(
    'weekly scores removed',
    !metadata_exists('user', 7, 'max_2048_score_weekly') &&
        !metadata_exists('user', 8, 'max_2048_fib_score_weekly'),
);
verify(
    'weekly rotation invalidates affected user caches',
    $GLOBALS['cache_deletions'] === [[7, 'user_meta'], [8, 'user_meta']],
);
verify(
    'weekly rotation preserves lifetime score',
    get_user_meta(7, 'max_2048_score', true) === '777',
);
update_user_meta(7, 'max_2048_score_weekly', 42);
$GLOBALS['cache_deletions'] = [];
Reader_Experience::weekly();
verify(
    'same-week rotation is idempotent',
    get_user_meta(7, 'max_2048_score_weekly', true) === '42' && $GLOBALS['cache_deletions'] === [],
);
update_option(reader_experience_config('week_option'), 'older-week');
$wpdb->failOptionWrite = true;
fails('failed weekly marker update rejected', fn() => Reader_Experience::weekly());
verify(
    'failed weekly marker rolls score deletion back',
    get_user_meta(7, 'max_2048_score_weekly', true) === '42',
);
$wpdb->failOptionWrite = false;
$request = new class {
    public string $nonce = 'fixture-nonce';
    public function get_header($name)
    {
        return $this->nonce;
    }
};
$GLOBALS['logged'] = false;
$GLOBALS['capable'] = true;
verify(
    'anonymous permission rejected',
    Reader_Experience::permission($request)->code === 'login_required',
);
$GLOBALS['logged'] = true;
$GLOBALS['capable'] = false;
verify(
    'insufficient capability rejected',
    Reader_Experience::permission($request)->code === 'login_required',
);
$GLOBALS['capable'] = true;
$request->nonce = 'invalid';
verify('invalid nonce rejected', Reader_Experience::permission($request)->code === 'nonce');
$request->nonce = 'fixture-nonce';
verify('authenticated permission accepted', Reader_Experience::permission($request) === true);
$wpdb->query("UPDATE fixture_options SET option_value='0'");
verify(
    'unready level filter preserves fallback',
    reader_experience_user_level('fallback', 7) === 'fallback',
);
verify(
    'maintenance permission rejected',
    Reader_Experience::permission($request)->code === 'maintenance',
);

$wpdb->query(
    "UPDATE fixture_options SET option_value='1' WHERE option_name='reader_experience_live'",
);
$defaults = WP_XP_Core_Settings::defaults();
verify(
    'read defaults never creates settings',
    get_option(WP_XP_Core_Settings::OPTION, null) === null,
);
$GLOBALS['capable'] = false;
verify(
    'settings require administrator',
    WP_XP_Core_Settings::save($defaults, 'fixture-nonce', WP_XP_Core_Settings::revision(null))
        ->code === 'forbidden',
);
$GLOBALS['capable'] = true;
verify(
    'settings require nonce',
    WP_XP_Core_Settings::save($defaults, 'bad', WP_XP_Core_Settings::revision(null))->code ===
        'nonce',
);
$custom = $defaults;
$custom['levels'] = [0, 3, 9];
$custom['checkin_xp'] = 13;
$custom['comment_xp'] = 7;
$custom['visit_xp'] = 4;
$custom['visit_limit'] = 1;
$custom['article_xp'] = 11;
verify(
    'administrator saves custom policy',
    WP_XP_Core_Settings::save($custom, 'fixture-nonce', WP_XP_Core_Settings::revision(null)) ===
        true,
);
verify(
    'custom thresholds used',
    Reader_Experience::level(8) === 2 && Reader_Experience::level(9) === 3,
);
verify(
    'stale settings form rejected',
    WP_XP_Core_Settings::save($defaults, 'fixture-nonce', WP_XP_Core_Settings::revision(null))
        ->code === 'conflict',
);
foreach (
    [
        ['checkin_xp' => -1],
        ['visit_limit' => 1001],
        ['article_xp' => 1000001],
        ['comment_xp' => '2.5'],
        ['view_100_threshold' => 0],
        ['view_500_threshold' => 50],
        ['like_10_threshold' => 1000000001],
        ['levels' => [1, 2]],
        ['levels' => [0, 2, 2]],
        ['levels' => [0, 1000000001]],
        ['levels' => []],
        ['levels' => array_fill(0, 101, 0)],
        ['unknown' => 1],
    ]
    as $bad
) {
    $bad = array_replace($custom, $bad);
    verify(
        'invalid policy rejected',
        WP_XP_Core_Settings::save($bad, 'fixture-nonce', WP_XP_Core_Settings::revision($custom))
            ->code === 'input',
    );
    verify('invalid save preserves prior policy', WP_XP_Core_Settings::rules() === $custom);
}
verify(
    'textarea levels normalized',
    WP_XP_Core_Settings::validate(array_replace($custom, ['levels' => "0, 3\n9"])) === $custom,
);
Reader_Experience::transaction(function () use ($defaults, $custom) {
    update_option(WP_XP_Core_Settings::OPTION, $defaults, false);
    verify('event uses one policy snapshot', WP_XP_Core_Settings::rules() === $custom);
});
verify('next event sees updated policy', WP_XP_Core_Settings::rules() === $defaults);
update_option(WP_XP_Core_Settings::OPTION, ['broken' => true]);
verify('invalid stored policy safely defaults', WP_XP_Core_Settings::rules() === $defaults);
update_option(WP_XP_Core_Settings::OPTION, $custom);
function get_post($id)
{
    return $id
        ? (object) [
            'ID' => $id,
            'post_author' => 8,
            'post_status' => 'publish',
            'post_type' => 'post',
        ]
        : null;
}
function post_password_required($p)
{
    return false;
}
function get_comment($id)
{
    return $GLOBALS['comments'][$id] ?? null;
}
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
$before = (int) get_user_meta(7, Reader_Experience::BALANCE_META, true);
$state = Reader_Experience::request($request, 'checkin');
verify(
    'configured checkin amount recorded',
    $state['experience'] === $before + 13 && $state['checkin_xp'] === 13 && $state['next'] === null,
);
verify(
    'duplicate checkin still suppressed',
    Reader_Experience::request($request, 'checkin')['experience'] === $before + 13,
);
Reader_Experience::published('publish', 'draft', get_post(700));
verify(
    'configured article award',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE event_key='article:700'",
    ) === 11,
);
$GLOBALS['comments'][90] = (object) [
    'comment_post_ID' => 700,
    'user_id' => 7,
    'comment_parent' => 0,
    'comment_approved' => '1',
    'comment_type' => 'comment',
];
Reader_Experience::comment(90);
verify(
    'configured comment award',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE event_key='comment:90'",
    ) === 7,
);
$custom['comment_xp'] = 99;
update_option(WP_XP_Core_Settings::OPTION, $custom);
$GLOBALS['comments'][90]->comment_approved = '0';
Reader_Experience::comment(90);
verify(
    'old comment revoked by original amount',
    (int) $wpdb->get_var(
        'SELECT SUM(xp) FROM fixture_reader_experience_events WHERE object_id=90',
    ) === 0,
);
$GLOBALS['comments'][90]->comment_approved = '1';
Reader_Experience::comment(90);
verify(
    'old comment restored by original amount',
    (int) $wpdb->get_var(
        'SELECT SUM(xp) FROM fixture_reader_experience_events WHERE object_id=90',
    ) === 7,
);

function wp_salt($scheme)
{
    return 'synthetic-salt';
}
$request->body = ['post_id' => 701, 'issued' => time() - 20, 'signature' => ''];
$request->body['signature'] = hash_hmac(
    'sha256',
    '7:701:' . $request->body['issued'],
    wp_salt('nonce'),
);
Reader_Experience::request($request, 'visit');
verify(
    'configured reading award used',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE kind='visit' AND object_id=701",
    ) === 4,
);
$request->body['post_id'] = 702;
$request->body['signature'] = hash_hmac(
    'sha256',
    '7:702:' . $request->body['issued'],
    wp_salt('nonce'),
);
Reader_Experience::request($request, 'visit');
verify(
    'configured reading daily cap enforced',
    Reader_Experience::count_day(7, 'visit', Reader_Experience::day()) === 1,
);
$custom['article_xp'] = 0;
$custom['comment_limit'] = 0;
$custom['visit_limit'] = 0;
update_option(WP_XP_Core_Settings::OPTION, $custom);
Reader_Experience::published('publish', 'draft', get_post(703));
verify(
    'zero award records marker',
    Reader_Experience::exists('article:703') &&
        (int) $wpdb->get_var(
            "SELECT xp FROM fixture_reader_experience_events WHERE event_key='article:703'",
        ) === 0,
);
$custom['article_xp'] = 50;
update_option(WP_XP_Core_Settings::OPTION, $custom);
Reader_Experience::published('publish', 'draft', get_post(703));
verify(
    'zero marker prevents later rewards',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE event_key='article:703'",
    ) === 0,
);
$GLOBALS['comments'][91] = clone $GLOBALS['comments'][90];
Reader_Experience::comment(91);
verify('zero comment cap suppresses awards', !Reader_Experience::exists('comment:91'));
$request->body['post_id'] = 704;
$request->body['signature'] = hash_hmac(
    'sha256',
    '7:704:' . $request->body['issued'],
    wp_salt('nonce'),
);
Reader_Experience::request($request, 'visit');
verify(
    'zero reading cap suppresses awards',
    !Reader_Experience::exists('visit:7:' . Reader_Experience::day() . ':704'),
);
foreach (['view' => [100, 500, 1000], 'like' => [10, 30, 100]] as $kind => $thresholds) {
    foreach ($thresholds as $i => $threshold) {
        $custom[$kind . '_' . $threshold] = ($i + 1) * 3;
    }
    update_option(WP_XP_Core_Settings::OPTION, $custom);
    Reader_Experience::transaction(function () use ($kind, $thresholds) {
        for ($i = 0; $i < max($thresholds); $i++) {
            Reader_Experience::record(
                'fixture:' . $kind . ':' . $i,
                7,
                $kind,
                705,
                0,
                Reader_Experience::day(),
            );
        }
        Reader_Experience::milestones(705, $kind);
        Reader_Experience::milestones(705, $kind);
    });
    foreach ($thresholds as $i => $threshold) {
        verify(
            'custom milestone reward and deduplication',
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT SUM(xp) FROM fixture_reader_experience_events WHERE event_key=%s',
                    "milestone:$kind:705:$threshold",
                ),
            ) ===
                ($i + 1) * 3,
        );
    }
}
$legacy = array_filter(
    $custom,
    fn($key) => !str_ends_with($key, '_threshold'),
    ARRAY_FILTER_USE_KEY,
);
update_option(WP_XP_Core_Settings::OPTION, $legacy);
verify(
    'legacy custom settings normalized without writes',
    WP_XP_Core_Settings::rules() === $custom && get_option(WP_XP_Core_Settings::OPTION) === $legacy,
);
$partial = $custom;
unset($partial['view_100_threshold']);
try {
    WP_XP_Core_Settings::validate($partial);
    verify('partial thresholds rejected', false);
} catch (InvalidArgumentException $e) {
    verify('partial thresholds rejected', true);
}
$GLOBALS['likes_enabled'] = false;
$disabled = $custom;
unset(
    $disabled['like_10'],
    $disabled['like_30'],
    $disabled['like_100'],
    $disabled['like_10_threshold'],
    $disabled['like_30_threshold'],
    $disabled['like_100_threshold'],
);
verify(
    'disabled likes omitted values preserved',
    WP_XP_Core_Settings::save(
        $disabled,
        'fixture-nonce',
        WP_XP_Core_Settings::revision($legacy),
    ) === true && WP_XP_Core_Settings::rules() === $custom,
);
$disabled['like_10'] = 999;
verify(
    'disabled likes forged value ignored',
    WP_XP_Core_Settings::save(
        $disabled,
        'fixture-nonce',
        WP_XP_Core_Settings::revision($custom),
    ) === true && WP_XP_Core_Settings::rules() === $custom,
);
Reader_Experience::liked(77, 707, 1);
verify('disabled provider records no like event', !Reader_Experience::exists('like:77:707'));
$GLOBALS['likes_enabled'] = true;
$custom['view_100_threshold'] = 2;
$custom['view_500_threshold'] = 4;
$custom['view_1000_threshold'] = 6;
update_option(WP_XP_Core_Settings::OPTION, $custom);
Reader_Experience::transaction(function () {
    for ($i = 0; $i < 2; $i++) {
        Reader_Experience::record('newview:' . $i, 7, 'view', 706, 0, Reader_Experience::day());
    }
    Reader_Experience::milestones(706, 'view');
});
verify(
    'custom count awards stable first slot',
    Reader_Experience::exists('milestone:view:706:100') &&
        !Reader_Experience::exists('milestone:view:706:500'),
);
$custom['view_100_threshold'] = 1;
$custom['view_100'] = 999;
update_option(WP_XP_Core_Settings::OPTION, $custom);
Reader_Experience::transaction(fn() => Reader_Experience::milestones(706, 'view'));
verify(
    'changed count never reawards claimed slot',
    (int) $wpdb->get_var(
        "SELECT SUM(xp) FROM fixture_reader_experience_events WHERE event_key='milestone:view:706:100'",
    ) === 3,
);
echo "$checks independent ledger runtime checks passed\n";
