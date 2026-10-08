<?php
/** Synthetic MySQL metadata and failure injection exercise the native activation boundary. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'installation_fixture');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['authorized'] = true;
$GLOBALS['multisite'] = false;
$GLOBALS['removed_hooks'] = [];
$GLOBALS['registered_shortcodes'] = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode($name, ...$args)
{
    $GLOBALS['registered_shortcodes'][] = $name;
}
function register_activation_hook($file, $callback)
{
    $GLOBALS['activation'] = $callback;
}
function current_user_can(...$args)
{
    return $GLOBALS['authorized'];
}
function is_multisite()
{
    return $GLOBALS['multisite'];
}
function esc_html($text)
{
    return $text;
}
function wp_die($message, ...$args)
{
    throw new RuntimeException($message);
}
function wp_cache_delete(...$args) {}
function remove_all_actions($hook)
{
    $GLOBALS['removed_hooks'][] = $hook;
}
function get_option($key, $default = false)
{
    return $GLOBALS['wpdb']->values[$key] ?? $default;
}
function add_option($key, $value, ...$args)
{
    $db = $GLOBALS['wpdb'];
    if (isset($db->values[$key]) || $db->failOption === $key) {
        return false;
    }
    $db->writes[] = 'option:' . $key;
    $db->values[$key] = $value;
    return true;
}
function delete_option($key)
{
    $db = $GLOBALS['wpdb'];
    unset($db->values[$key]);
    return true;
}
final class Installation_Storage
{
    public string $prefix = 'fixture_';
    public string $options = 'fixture_options';
    public string $usermeta = 'fixture_usermeta';
    public string $postmeta = 'fixture_postmeta';
    public array $values = [];
    public array $tables = [
        'fixture_options' => 'InnoDB',
        'fixture_usermeta' => 'InnoDB',
        'fixture_postmeta' => 'InnoDB',
    ];
    public array $balances = [];
    public array $writes = [];
    public int $ledgerRows = 0;
    public int $releases = 0;
    public bool $busy = false;
    public bool $failCreate = false;
    public bool $badColumns = false;
    public bool $badIndexes = false;
    public string $last_error = '';
    public string $failOption = '';
    private array $snapshot = [];
    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback(
            '/%[sd]/',
            static function ($match) use ($args, &$i) {
                $value = $args[$i++];
                return $match[0] === '%d'
                    ? (string) (int) $value
                    : "'" . str_replace("'", "''", $value) . "'";
            },
            $sql,
        );
    }
    public function get_var($sql)
    {
        $this->last_error = '';
        if (str_contains($sql, 'GET_LOCK')) {
            return $this->busy ? 0 : 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->releases++;
            return 1;
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
            return $this->tables[$match[1]] ?? null;
        }
        if (str_contains($sql, 'meta_key=')) {
            preg_match("/meta_key='([^']+)'/", $sql, $match);
            return $this->balances[$match[1]] ?? 0;
        }
        if (str_contains($sql, 'option_name=')) {
            preg_match("/option_name='([^']+)'/", $sql, $match);
            return $this->values[$match[1]] ?? null;
        }
        if (str_starts_with($sql, 'SELECT COUNT(*)')) {
            return $this->ledgerRows;
        }
        throw new RuntimeException('Unexpected fixture query: ' . $sql);
    }
    public function query($sql)
    {
        $this->last_error = '';
        $this->writes[] = $sql;
        if (str_starts_with($sql, 'CREATE TABLE')) {
            if ($this->failCreate) {
                return false;
            }
            preg_match('/CREATE TABLE ([a-zA-Z0-9_]+)/', $sql, $match);
            $this->tables[$match[1]] = 'InnoDB';
        } elseif ($sql === 'START TRANSACTION') {
            $this->snapshot = $this->values;
        } elseif ($sql === 'ROLLBACK') {
            $this->values = $this->snapshot;
        } elseif ($sql !== 'COMMIT') {
            throw new RuntimeException('Unexpected fixture mutation: ' . $sql);
        }
        return 1;
    }
    public function get_charset_collate()
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
    public function get_results($sql, $mode)
    {
        $this->last_error = '';
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            $types = [
                'id' => 'bigint(20) unsigned',
                'event_key' => 'varchar(191)',
                'user_id' => 'bigint(20) unsigned',
                'kind' => 'varchar(32)',
                'object_id' => 'bigint(20) unsigned',
                'local_day' => 'date',
                'xp' => 'bigint(20)',
                'created_at' => 'bigint(20)',
                'detail' => 'text',
            ];
            $columns = [];
            foreach ($types as $name => $type) {
                $columns[] = [
                    'COLUMN_NAME' => $name,
                    'COLUMN_TYPE' => $this->badColumns && $name === 'xp' ? 'int' : $type,
                    'IS_NULLABLE' => $name === 'detail' ? 'YES' : 'NO',
                    'COLUMN_DEFAULT' => $name === 'object_id' ? '0' : null,
                    'EXTRA' => $name === 'id' ? 'auto_increment' : '',
                    'CHARACTER_SET_NAME' => in_array($name, ['event_key', 'kind']) ? 'ascii' : null,
                    'COLLATION_NAME' => $name === 'event_key' ? 'ascii_bin' : null,
                ];
            }
            return $columns;
        }
        if (str_contains($sql, 'information_schema.STATISTICS')) {
            $indexes = [
                'PRIMARY' => [0, ['id']],
                'event_key' => [0, ['event_key']],
                'object_kind' => [1, ['object_id', 'kind']],
                'user_day' => [1, ['user_id', 'local_day', 'kind']],
            ];
            if ($this->badIndexes) {
                unset($indexes['event_key']);
            }
            $rows = [];
            foreach ($indexes as $name => [$nonUnique, $columns]) {
                foreach ($columns as $i => $column) {
                    $rows[] = [
                        'INDEX_NAME' => $name,
                        'NON_UNIQUE' => $nonUnique,
                        'SEQ_IN_INDEX' => $i + 1,
                        'COLUMN_NAME' => $column,
                        'SUB_PART' => null,
                    ];
                }
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected metadata query: ' . $sql);
    }
}
$GLOBALS['wpdb'] = new Installation_Storage();
$mode = $argv[1] ?? 'native';
if ($mode !== 'native') {
    $profile = tempnam(sys_get_temp_dir(), 'wp-xp-core-install-');
    $content = match ($mode) {
        'external-valid' => json_encode([
            'schema_version' => 1,
            'experience' => ['table_suffix' => 'existing_events'],
        ]),
        'external-missing' => json_encode(['schema_version' => 1]),
        'external-invalid' => '{invalid',
        'external-path' => null,
    };
    if ($content !== null) {
        file_put_contents($profile, $content);
        register_shutdown_function(static fn() => unlink($profile));
    } else {
        unlink($profile);
    }
    define('PAGENEST_COMPATIBILITY_PROFILE_FILE', $profile);
}
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
function rejected($name, $callback, $message)
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        verify($name, str_contains($error->getMessage(), $message));
        return;
    }
    verify($name, false);
}
function fresh()
{
    return $GLOBALS['wpdb'] = new Installation_Storage();
}
function activate($network = false)
{
    $GLOBALS['activation']($network);
}
if ($mode !== 'native') {
    verify(
        'explicit external mode cannot become native',
        reader_experience_external() && !reader_experience_native(),
    );
    if ($mode === 'external-valid') {
        activate();
        verify(
            'external route table preserved',
            Reader_Experience::table() === 'fixture_existing_events',
        );
    } else {
        verify(
            'invalid external configuration stays disabled',
            !reader_experience_configured() && !Reader_Experience::ready(),
        );
        rejected(
            'invalid external activation fails closed',
            fn() => activate(),
            'external compatibility profile',
        );
        verify('disabled external has no shortcode', $GLOBALS['registered_shortcodes'] === []);
    }
    verify('external load and activation never write', $GLOBALS['wpdb']->writes === []);
    echo "$checks $mode installation checks passed\n";
    exit();
}
$db = $GLOBALS['wpdb'];
verify('native load does not write', $db->writes === []);
verify(
    'activation callback registered',
    $GLOBALS['activation'] === [WP_XP_Core_Lifecycle::class, 'activate'],
);
verify('native shortcode registered', $GLOBALS['registered_shortcodes'] === ['reader_experience']);
verify('preinstall panel returns maintenance', str_contains(Reader_Experience::panel(), '维护'));
verify(
    'ordinary panel and readiness reads never install',
    !Reader_Experience::ready() && $db->writes === [],
);
$GLOBALS['authorized'] = false;
rejected('activation requires plugin permission', fn() => activate(), 'permission');
verify('permission failure is read-only', $db->writes === []);
$GLOBALS['authorized'] = true;
rejected('network activation rejected before writes', fn() => activate(true), 'network activation');
verify('network rejection does not mutate', $db->writes === []);
$db->busy = true;
rejected('concurrent installer fails before mutations', fn() => activate(), 'busy');
verify(
    'failed lock neither writes nor releases another connection lock',
    $db->writes === [] && $db->releases === 0,
);
$db->busy = false;
activate();
verify('fresh native install becomes ready', Reader_Experience::ready());
verify(
    'native table created once',
    count(array_filter($db->writes, fn($sql) => str_starts_with($sql, 'CREATE TABLE'))) === 1,
);
verify(
    'only own installation options written',
    array_keys($db->values) === [
        WP_XP_Core_Lifecycle::INTENT_OPTION,
        WP_XP_Core_Lifecycle::SCHEMA_OPTION,
        'reader_experience_live',
    ],
);
verify(
    'no users initialized or rules copied',
    $db->balances === [] && !isset($db->values[WP_XP_Core_Settings::OPTION]),
);
$db->ledgerRows = 7;
$db->balances[Reader_Experience::balance_meta()] = 2;
$db->values[WP_XP_Core_Settings::OPTION] = ['preserved' => true];
$writes = $db->writes;
activate();
verify(
    'reactivation preserves nonempty native data and rules',
    $db->ledgerRows === 7 &&
        $db->balances[Reader_Experience::balance_meta()] === 2 &&
        $db->values[WP_XP_Core_Settings::OPTION] === ['preserved' => true] &&
        $db->writes === $writes,
);
$db->values['reader_experience_live'] = '0';
activate();
verify(
    'reactivation preserves existing maintenance flag',
    !Reader_Experience::ready() && $db->values['reader_experience_live'] === '0',
);
Reader_Experience::legacy();
Reader_Experience::weekly();
verify(
    'native mode leaves legacy like and game callbacks untouched',
    $GLOBALS['removed_hooks'] === [] && $db->writes === $writes,
);
foreach (['fixture_usermeta', 'fixture_postmeta', 'fixture_options'] as $table) {
    $db = fresh();
    $db->tables[$table] = 'MyISAM';
    rejected('nontransactional WordPress storage rejected', fn() => activate(), 'must use InnoDB');
    verify(
        'engine rejection precedes DDL and options',
        $db->writes === [] && !Reader_Experience::ready(),
    );
}
$db = fresh();
$db->tables[Reader_Experience::table()] = 'InnoDB';
$db->ledgerRows = 9;
rejected('unowned existing ledger rejected', fn() => activate(), 'unowned');
verify(
    'existing ledger retained without markers',
    $db->ledgerRows === 9 && $db->writes === [] && !Reader_Experience::ready(),
);
$db->ledgerRows = 0;
rejected('empty unowned table is not silently adopted', fn() => activate(), 'unowned');
foreach (['reader_experience_live', WP_XP_Core_Settings::OPTION] as $option) {
    $db = fresh();
    $db->values[$option] = 'existing';
    rejected('unowned options rejected', fn() => activate(), 'left unchanged');
    verify(
        'existing options preserved without DDL',
        $db->values === [$option => 'existing'] && $db->writes === [],
    );
}
$db = fresh();
$db->balances[Reader_Experience::balance_meta()] = 1;
rejected('existing balance rows rejected even if zero-valued', fn() => activate(), 'balances');
verify(
    'balance rejection is read-only',
    $db->writes === [] && $db->balances[Reader_Experience::balance_meta()] === 1,
);
$db = fresh();
$db->failCreate = true;
rejected('DDL failure does not enable rewards', fn() => activate(), 'storage failed');
verify(
    'DDL failure revokes intent and cannot adopt a concurrent foreign table',
    $db->values === [] && !Reader_Experience::ready() && $db->releases === 1,
);
$db->failCreate = false;
$db->tables[Reader_Experience::table()] = 'InnoDB';
rejected(
    'concurrent foreign table never adopted after failed create',
    fn() => activate(),
    'unowned',
);
unset($db->tables[Reader_Experience::table()]);
activate();
verify('failed creation can be retried safely', Reader_Experience::ready());
foreach (['badColumns', 'badIndexes'] as $failure) {
    $db = fresh();
    $db->$failure = true;
    rejected('incompatible new schema rejected', fn() => activate(), 'incompatible');
    verify(
        'schema failure writes no readiness',
        !Reader_Experience::ready() &&
            !isset(
                $db->values[WP_XP_Core_Lifecycle::SCHEMA_OPTION],
                $db->values['reader_experience_live'],
            ),
    );
    $db->$failure = false;
    activate();
    verify(
        'compatible empty owned partial install retries',
        Reader_Experience::ready() &&
            count(array_filter($db->writes, fn($sql) => str_starts_with($sql, 'CREATE TABLE'))) ===
                1,
    );
}
$db = fresh();
$db->failOption = 'reader_experience_live';
rejected('failed readiness write rejected', fn() => activate(), 'readiness');
verify(
    'failed readiness transaction rolls both options back',
    !Reader_Experience::ready() &&
        array_keys($db->values) === [WP_XP_Core_Lifecycle::INTENT_OPTION],
);
$db->ledgerRows = 1;
$db->failOption = '';
rejected('nonempty partial install cannot be adopted', fn() => activate(), 'contains ledger data');
verify(
    'nonempty partial ledger is preserved',
    $db->ledgerRows === 1 && !Reader_Experience::ready(),
);
$db->ledgerRows = 0;
activate();
verify('empty failed readiness can retry', Reader_Experience::ready());
$db->badIndexes = true;
$writes = $db->writes;
rejected('owned schema indexes verified on reactivation', fn() => activate(), 'incompatible');
verify('owned schema mismatch is never repaired or reset', $db->writes === $writes);
$db = fresh();
$GLOBALS['multisite'] = true;
verify(
    'native multisite balance key is site scoped',
    Reader_Experience::balance_meta() === 'fixture_reader_experience_balance',
);
activate();
verify('per-site multisite activation succeeds', Reader_Experience::ready());
$db->prefix = 'fixture_2_';
verify(
    'another site cannot use first site schema marker',
    !Reader_Experience::ready() &&
        Reader_Experience::balance_meta() === 'fixture_2_reader_experience_balance',
);
foreach (
    ['external-valid', 'external-missing', 'external-invalid', 'external-path']
    as $externalMode
) {
    $command =
        escapeshellarg(PHP_BINARY) .
        ' ' .
        escapeshellarg(__FILE__) .
        ' ' .
        escapeshellarg($externalMode);
    passthru($command, $status);
    verify('external profile subprocess passed', $status === 0);
}
echo "$checks native installation checks passed\n";
