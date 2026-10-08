<?php
/** Native installation runs only through WordPress activation; user data is never migrated. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Lifecycle
{
    const SCHEMA_OPTION = 'reader_experience_schema';
    const INTENT_OPTION = 'reader_experience_install_intent';
    private static function ownership(): array
    {
        return ['owner' => 'wp-xp-core', 'schema' => 1, 'table' => Reader_Experience::table()];
    }
    public static function installed(): bool
    {
        $marker = get_option(self::SCHEMA_OPTION, null);
        return is_array($marker) && $marker === self::ownership();
    }
    public static function activate($network_wide = false): void
    {
        try {
            if (!current_user_can('activate_plugins')) {
                throw new RuntimeException('You need permission to activate plugins.');
            }
            if ($network_wide) {
                throw new RuntimeException(
                    'Activate WP XP Core separately on each site; network activation is not supported.',
                );
            }
            if (!reader_experience_configured()) {
                throw new RuntimeException(
                    'The external compatibility profile is invalid or has no experience section.',
                );
            }
            if (reader_experience_external()) {
                return;
            }
            self::install();
        } catch (Throwable $error) {
            wp_die(
                esc_html('WP XP Core could not activate: ' . $error->getMessage()),
                'WP XP Core installation',
                ['back_link' => true],
            );
        }
    }
    private static function engine(string $table): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table,
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Could not inspect database storage.');
        }
        return $value === null ? null : (string) $value;
    }
    private static function shape(): void
    {
        global $wpdb;
        $table = Reader_Experience::table();
        if (self::engine($table) !== 'InnoDB') {
            throw new RuntimeException(
                'The experience ledger must use InnoDB; no storage engines were changed.',
            );
        }
        $columns = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',
                $table,
            ),
            ARRAY_A,
        );
        $types = [
            'id' => 'bigint unsigned',
            'event_key' => 'varchar(191)',
            'user_id' => 'bigint unsigned',
            'kind' => 'varchar(32)',
            'object_id' => 'bigint unsigned',
            'local_day' => 'date',
            'xp' => 'bigint',
            'created_at' => 'bigint',
            'detail' => 'text',
        ];
        if ($wpdb->last_error !== '' || !is_array($columns) || count($columns) !== count($types)) {
            throw new RuntimeException('The experience ledger has an incompatible column layout.');
        }
        foreach ($columns as $column) {
            $name = $column['COLUMN_NAME'];
            $type = preg_replace(
                '/\b(bigint)\([0-9]+\)/',
                '$1',
                strtolower($column['COLUMN_TYPE']),
            );
            if (
                !isset($types[$name]) ||
                $type !== $types[$name] ||
                $column['IS_NULLABLE'] !== ($name === 'detail' ? 'YES' : 'NO') ||
                strtolower($column['EXTRA']) !== ($name === 'id' ? 'auto_increment' : '') ||
                ($name === 'object_id'
                    ? (string) $column['COLUMN_DEFAULT'] !== '0'
                    : !(
                        $column['COLUMN_DEFAULT'] === null ||
                        ($name === 'detail' &&
                            strtoupper((string) $column['COLUMN_DEFAULT']) === 'NULL')
                    )) ||
                (in_array($name, ['event_key', 'kind'], true) &&
                    $column['CHARACTER_SET_NAME'] !== 'ascii') ||
                ($name === 'event_key' && $column['COLLATION_NAME'] !== 'ascii_bin')
            ) {
                throw new RuntimeException(
                    'The experience ledger has incompatible columns; it was left unchanged.',
                );
            }
            unset($types[$name]);
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX',
                $table,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Could not inspect experience ledger indexes.');
        }
        $indexes = [];
        foreach ($rows as $row) {
            if ($row['SUB_PART'] !== null) {
                throw new RuntimeException(
                    'The experience ledger has incompatible partial indexes.',
                );
            }
            $indexes[$row['INDEX_NAME']]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $indexes[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }
        $expected = [
            'PRIMARY' => ['unique' => true, 'columns' => ['id']],
            'event_key' => ['unique' => true, 'columns' => ['event_key']],
            'user_day' => ['unique' => false, 'columns' => ['user_id', 'local_day', 'kind']],
            'object_kind' => ['unique' => false, 'columns' => ['object_id', 'kind']],
        ];
        ksort($indexes);
        ksort($expected);
        if ($indexes !== $expected) {
            throw new RuntimeException(
                'The experience ledger has incompatible indexes; it was left unchanged.',
            );
        }
    }
    private static function install(): void
    {
        global $wpdb;
        $lock = reader_experience_lock('event_lock');
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            throw new RuntimeException('Experience storage is busy. Please try activation again.');
        }
        try {
            foreach ([$wpdb->usermeta, $wpdb->postmeta, $wpdb->options] as $table) {
                if (self::engine($table) !== 'InnoDB') {
                    throw new RuntimeException(
                        'WordPress usermeta, postmeta and options must use InnoDB before activation. No tables were changed.',
                    );
                }
            }
            $ownership = self::ownership();
            $marker = get_option(self::SCHEMA_OPTION, null);
            $live = get_option(reader_experience_config('live_option'), null);
            if ($marker !== null) {
                if ($marker !== $ownership || !in_array($live, ['0', '1', 0, 1], true)) {
                    throw new RuntimeException(
                        'Existing installation markers are incompatible. They were left unchanged.',
                    );
                }
                self::shape();
                return;
            }
            if ($live !== null || get_option(WP_XP_Core_Settings::OPTION, null) !== null) {
                throw new RuntimeException(
                    'Existing experience live settings or rules need an explicit external integration; they were left unchanged.',
                );
            }
            $balances = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s",
                    Reader_Experience::balance_meta(),
                ),
            );
            if ($wpdb->last_error !== '' || $balances === null || (int) $balances !== 0) {
                throw new RuntimeException(
                    'Existing experience balances cannot be adopted by a fresh installation. Use explicit external integration.',
                );
            }
            $intent = get_option(self::INTENT_OPTION, null);
            if ($intent !== null && $intent !== $ownership) {
                throw new RuntimeException('An incompatible installation intent already exists.');
            }
            $table = Reader_Experience::table();
            if (self::engine($table) !== null) {
                if ($intent !== $ownership) {
                    throw new RuntimeException(
                        'An unowned experience ledger already exists. Use explicit external integration; it was left unchanged.',
                    );
                }
                self::shape();
                $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
                if ($wpdb->last_error !== '' || $count === null || (int) $count !== 0) {
                    throw new RuntimeException(
                        'An incomplete installation contains ledger data and cannot be resumed automatically.',
                    );
                }
            } else {
                if ($intent === null && !add_option(self::INTENT_OPTION, $ownership, '', false)) {
                    throw new RuntimeException('Could not save installation ownership intent.');
                }
                try {
                    Reader_Experience::q(
                        'CREATE TABLE ' .
                            $table .
                            ' (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,event_key varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id bigint unsigned NOT NULL,kind varchar(32) CHARACTER SET ascii NOT NULL,object_id bigint unsigned NOT NULL DEFAULT 0,local_day date NOT NULL,xp bigint NOT NULL,created_at bigint NOT NULL,detail text NULL,KEY user_day(user_id,local_day,kind),KEY object_kind(object_id,kind)) ENGINE=InnoDB ' .
                            $wpdb->get_charset_collate(),
                    );
                } catch (Throwable $error) {
                    // A failed CREATE never proves ownership of a concurrently created table.
                    delete_option(self::INTENT_OPTION);
                    throw $error;
                }
                self::shape();
            }
            // DDL commits implicitly. Only the final readiness options are committed together.
            Reader_Experience::q('START TRANSACTION');
            try {
                if (
                    !add_option(self::SCHEMA_OPTION, $ownership, '', false) ||
                    !add_option(reader_experience_config('live_option'), '1', '', false)
                ) {
                    throw new RuntimeException(
                        'Could not save installation readiness. Please try activation again.',
                    );
                }
                Reader_Experience::q('COMMIT');
            } catch (Throwable $error) {
                $wpdb->query('ROLLBACK');
                foreach (
                    [self::SCHEMA_OPTION, reader_experience_config('live_option')]
                    as $option
                ) {
                    wp_cache_delete($option, 'options');
                }
                wp_cache_delete('notoptions', 'options');
                wp_cache_delete('alloptions', 'options');
                throw $error;
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
