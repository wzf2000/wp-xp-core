<?php
/** Synthetic SQLite storage exercises production ledger methods; no WordPress site is loaded. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'fixture');
define('ARRAY_A', PDO::FETCH_ASSOC);
function add_action(...$args) {}
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
    return $value === false ? $default : $value;
}
function update_option($key, $value, $autoload = null)
{
    global $wpdb;
    if ($wpdb->failOptionWrite || get_option($key) === $value) {
        return false;
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
require dirname(__DIR__) . '/wp-xp-core.php';
$GLOBALS['reader_experience_profile'] = reader_experience_validate([]);
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
echo "$checks independent ledger runtime checks passed\n";
