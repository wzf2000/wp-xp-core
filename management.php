<?php
/** Administrator operations append to the existing ledger; they never repair balances in place. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Management
{
    const AUDIT_OPTION = 'wp_xp_core_rule_audit';
    const FAILURE_OPTION = 'wp_xp_core_failed_events';
    const MAX_FAILURES = 200;
    const MAX_AUDIT = 200;

    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    public static function permission(int $uid = 0)
    {
        if (!current_user_can('manage_options')) {
            return self::error('forbidden', '没有管理经验的权限。', 403);
        }
        if ($uid && !self::can_target($uid)) {
            return self::error('forbidden', '没有管理该用户经验的权限。', 403);
        }
        return true;
    }
    private static function can_target(int $uid): bool
    {
        if (!current_user_can('edit_user', $uid)) {
            return false;
        }
        if (function_exists('is_multisite') && is_multisite()) {
            if (!is_user_member_of_blog($uid, get_current_blog_id())) {
                return false;
            }
            if (is_super_admin($uid) && !is_super_admin(get_current_user_id())) {
                return false;
            }
        }
        return true;
    }
    private static function integer($value, int $min, int $max): ?int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^-?(0|[1-9][0-9]*)$/D', (string) $value)
        ) {
            return null;
        }
        if (strlen((string) $value) > 16 || (int) $value < $min || (int) $value > $max) {
            return null;
        }
        return (int) $value;
    }
    private static function date($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
    private static function query(array $filters, bool $private = false)
    {
        global $wpdb;
        if (
            array_diff(array_keys($filters), ['user_id', 'kind', 'from', 'to', 'cursor', 'limit'])
        ) {
            return self::error('input', '查询条件无效。');
        }
        $where = ['1=1'];
        foreach (['user_id' => 1, 'cursor' => 0, 'limit' => 1] as $key => $min) {
            if (isset($filters[$key])) {
                $value = self::integer($filters[$key], $min, $key === 'limit' ? 100 : PHP_INT_MAX);
                if ($value === null) {
                    return self::error('input', '用户、页码或每页数量无效。');
                }
                $filters[$key] = $value;
            }
        }
        if (isset($filters['user_id'])) {
            $where[] = $wpdb->prepare('user_id=%d', $filters['user_id']);
        }
        if (!empty($filters['cursor'])) {
            $where[] = $wpdb->prepare('id<%d', $filters['cursor']);
        }
        if (isset($filters['kind']) && $filters['kind'] !== '') {
            if (
                !is_string($filters['kind']) ||
                !preg_match('/^[a-z_]{1,32}$/D', $filters['kind'])
            ) {
                return self::error('input', '事件类型无效。');
            }
            $where[] = $wpdb->prepare('kind=%s', $filters['kind']);
        }
        foreach (['from', 'to'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                if (!self::date($filters[$key])) {
                    return self::error('input', '日期格式无效。');
                }
                $where[] = $wpdb->prepare(
                    'local_day' . ($key === 'from' ? '>=' : '<=') . '%s',
                    $filters[$key],
                );
            }
        }
        if (
            !empty($filters['from']) &&
            !empty($filters['to']) &&
            $filters['from'] > $filters['to']
        ) {
            return self::error('input', '开始日期不能晚于结束日期。');
        }
        $limit = $filters['limit'] ?? 50;
        $columns = $private ? 'id,kind,xp,local_day,created_at' : '*';
        $rows = $wpdb->get_results(
            'SELECT ' .
                $columns .
                ' FROM ' .
                Reader_Experience::table() .
                ' WHERE ' .
                implode(' AND ', $where) .
                ' ORDER BY id DESC LIMIT ' .
                ($limit + 1),
            ARRAY_A,
        );
        if (!is_array($rows) || $wpdb->last_error !== '') {
            return self::error('storage', '暂时无法读取经验记录。', 503);
        }
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $cursor = $more ? (int) $rows[count($rows) - 1]['id'] : null;
        foreach ($rows as &$row) {
            foreach (['id', 'user_id', 'object_id', 'xp', 'created_at'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = (int) $row[$key];
                }
            }
            if (!$private) {
                $row['detail'] = json_decode($row['detail'] ?? '', true) ?: [];
            }
        }
        unset($row);
        if (!$private) {
            $rows = array_values(
                array_filter($rows, fn($row) => self::can_target($row['user_id'])),
            );
        }
        return ['items' => $rows, 'next_cursor' => $cursor];
    }
    public static function events(array $filters = [])
    {
        $permission = self::permission();
        if ($permission !== true) {
            return $permission;
        }
        if (isset($filters['user_id'])) {
            $uid = self::integer($filters['user_id'], 1, PHP_INT_MAX);
            if ($uid !== null && self::permission($uid) !== true) {
                return self::permission($uid);
            }
        }
        return self::query($filters);
    }
    public static function personal_history(array $filters = [])
    {
        $uid = get_current_user_id();
        if (!$uid || !current_user_can('read')) {
            return self::error('login_required', '请先登录。', 401);
        }
        if (
            isset($filters['user_id']) &&
            self::integer($filters['user_id'], 1, PHP_INT_MAX) !== $uid
        ) {
            return self::error('forbidden', '只能查看自己的经验记录。', 403);
        }
        $filters['user_id'] = $uid;
        return self::query($filters, true);
    }
    public static function user_summary(int $uid)
    {
        $permission = self::permission($uid);
        if ($permission !== true) {
            return $permission;
        }
        $user = get_userdata($uid);
        if ($uid < 1 || !$user) {
            return self::error('missing', '用户不存在。', 404);
        }
        global $wpdb;
        $rows = self::projection($wpdb->prepare('SELECT %d AS user_id', $uid));
        if ($rows instanceof WP_Error) {
            return $rows;
        }
        $row = $rows[0];
        return $row + [
            'display_name' => $user->display_name ?? '',
            'level' => Reader_Experience::level($row['balance'] ?? 0),
        ];
    }
    /** Read balances and ledger sums in one statement, so an intervening award cannot look like drift. */
    private static function projection(string $owners)
    {
        global $wpdb;
        $table = Reader_Experience::table();
        $meta = $wpdb->usermeta;
        $key = $wpdb->prepare('%s', Reader_Experience::balance_meta());
        $rows = $wpdb->get_results(
            'SELECT owners.user_id,' .
                '(SELECT COALESCE(SUM(xp),0) FROM ' .
                $table .
                ' e WHERE e.user_id=owners.user_id) AS ledger_total,' .
                '(SELECT COUNT(*) FROM ' .
                $table .
                ' e WHERE e.user_id=owners.user_id) AS event_count,' .
                '(SELECT COUNT(*) FROM ' .
                $meta .
                ' m WHERE m.user_id=owners.user_id AND m.meta_key=' .
                $key .
                ') AS balance_rows,' .
                '(SELECT MIN(meta_value) FROM ' .
                $meta .
                ' m WHERE m.user_id=owners.user_id AND m.meta_key=' .
                $key .
                ') AS balance_raw ' .
                'FROM (' .
                $owners .
                ') AS owners ORDER BY owners.user_id ASC',
            ARRAY_A,
        );
        if (!is_array($rows) || $wpdb->last_error !== '') {
            return self::error('storage', '暂时无法读取经验记录。', 503);
        }
        $items = [];
        foreach ($rows as $row) {
            $balance = $row['balance_raw'] === null ? null : (int) $row['balance_raw'];
            $valid =
                (int) $row['balance_rows'] === 1 &&
                preg_match('/^(0|[1-9][0-9]*)$/D', (string) $row['balance_raw']);
            $items[] = [
                'user_id' => (int) $row['user_id'],
                'user_exists' => (bool) get_userdata((int) $row['user_id']),
                'balance_present' => (int) $row['balance_rows'] > 0,
                'balance_rows' => (int) $row['balance_rows'],
                'balance' => $balance,
                'ledger_total' => (int) $row['ledger_total'],
                'event_count' => (int) $row['event_count'],
                'consistent' => (bool) $valid && $balance === (int) $row['ledger_total'],
            ];
        }
        return $items;
    }
    public static function diagnostics(int $cursor = 0, int $limit = 50)
    {
        $permission = self::permission();
        if ($permission !== true) {
            return $permission;
        }
        if ($cursor < 0 || $limit < 1 || $limit > 100) {
            return self::error('input', '查询范围无效。');
        }
        global $wpdb;
        // The union includes deleted ledger owners and metadata owners without ledger rows.
        $owners = $wpdb->prepare(
            'SELECT user_id FROM (SELECT user_id FROM ' .
                Reader_Experience::table() .
                ' UNION SELECT user_id FROM ' .
                $wpdb->usermeta .
                ' WHERE meta_key=%s) AS candidates WHERE user_id>%d ORDER BY user_id ASC LIMIT %d',
            Reader_Experience::balance_meta(),
            $cursor,
            $limit + 1,
        );
        $items = self::projection($owners);
        if ($items instanceof WP_Error) {
            return $items;
        }
        $more = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        return [
            'items' => $items,
            'next_cursor' => $more ? $items[count($items) - 1]['user_id'] : null,
        ];
    }
    private static function option_rows(string $name): array
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $raw = get_option($name, '[]');
        $rows = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($rows)) {
            throw new RuntimeException('Experience management data invalid');
        }
        return $rows;
    }
    private static function store_rows(string $name, array $rows): void
    {
        $value = wp_json_encode($rows);
        if (
            !is_string($value) ||
            (!update_option($name, $value, false) && get_option($name, null) !== $value)
        ) {
            throw new RuntimeException('Experience management write failed');
        }
    }
    public static function require_options_transaction(): void
    {
        global $wpdb;
        if (
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                    $wpdb->options,
                ),
            ) !== 'InnoDB'
        ) {
            throw new RuntimeException('Transactional options storage required');
        }
    }
    public static function clear_option_cache(): void
    {
        foreach (
            [
                self::AUDIT_OPTION,
                self::FAILURE_OPTION,
                WP_XP_Core_Settings::OPTION,
                'alloptions',
                'notoptions',
            ]
            as $name
        ) {
            wp_cache_delete($name, 'options');
        }
    }
    /** Called under the settings transaction and its revision lock. */
    public static function audit_rules($before, array $after): void
    {
        self::require_options_transaction();
        $rows = self::option_rows(self::AUDIT_OPTION);
        $rows[] = [
            'action' => 'rules',
            'actor' => get_current_user_id(),
            'created_at' => time(),
            'before' => $before,
            'after' => $after,
        ];
        self::store_rows(self::AUDIT_OPTION, array_slice($rows, -self::MAX_AUDIT));
    }
    public static function audit_history()
    {
        $permission = self::permission();
        if ($permission !== true) {
            return $permission;
        }
        try {
            return array_reverse(self::option_rows(self::AUDIT_OPTION));
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取规则修改记录。', 503);
        }
    }
    /** Best effort after the original rollback; recording cannot replace the original error. */
    public static function capture_failure(array $attempt): void
    {
        if (!in_array($attempt['kind'] ?? '', ['article', 'comment'], true)) {
            return;
        }
        try {
            self::require_options_transaction();
            Reader_Experience::transaction(function () use ($attempt) {
                $rows = self::option_rows(self::FAILURE_OPTION);
                $id = substr(hash('sha256', $attempt['event_key']), 0, 32);
                $old = $rows[$id] ?? null;
                $rows[$id] = ($old ?? $attempt) + ['id' => $id, 'created_at' => time()];
                $rows[$id]['attempts'] = (int) ($old['attempts'] ?? 0) + 1;
                $rows[$id]['status'] = 'pending';
                $rows[$id]['last_error'] = '记录未完成';
                while (count($rows) > self::MAX_FAILURES) {
                    $remove = null;
                    foreach ($rows as $key => $row) {
                        if ($row['status'] === 'resolved') {
                            $remove = $key;
                            break;
                        }
                    }
                    unset($rows[$remove ?? array_key_first($rows)]);
                }
                self::store_rows(self::FAILURE_OPTION, $rows);
            });
        } catch (Throwable $e) {
            self::clear_option_cache();
            error_log('reader_experience: failure record could not be saved');
        }
    }
    public static function failures()
    {
        $permission = self::permission();
        if ($permission !== true) {
            return $permission;
        }
        try {
            return array_values(array_reverse(self::option_rows(self::FAILURE_OPTION), true));
        } catch (Throwable $e) {
            return self::error('storage', '暂时无法读取未完成记录。', 503);
        }
    }
    private static function input(array $input, $nonce, array $fields)
    {
        $permission = self::permission();
        if ($permission !== true) {
            return $permission;
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_manage')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        $keys = array_merge(['request_id', 'reason'], $fields);
        if (
            array_diff(array_keys($input), $keys) ||
            array_diff($keys, array_keys($input)) ||
            !is_string($input['request_id']) ||
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $input['request_id'],
            )
        ) {
            return self::error('input', '请求编号或操作参数无效。');
        }
        if (
            !is_string($input['reason']) ||
            !preg_match('/\S/u', $input['reason']) ||
            preg_match_all('/./us', $input['reason']) > 300 ||
            strlen($input['reason']) > 1200 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $input['reason'])
        ) {
            return self::error('input', '请填写 1–300 字的原因。');
        }
        $input['reason'] = trim($input['reason']);
        $input['request_id'] = strtolower($input['request_id']);
        return $input;
    }
    private static function event(string $key)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                $key,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Experience read failed');
        }
        return $row;
    }
    private static function execute(string $action, array $input, callable $operation)
    {
        try {
            return Reader_Experience::transaction(function () use ($action, $input, $operation) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中，请稍后重试。', 503);
                }
                $payload = $input;
                unset($payload['request_id']);
                ksort($payload);
                $hash = hash('sha256', wp_json_encode([$action, get_current_user_id(), $payload]));
                $key = 'manage:' . $input['request_id'];
                $existing = self::event($key);
                if ($existing) {
                    $detail = json_decode($existing['detail'] ?? '', true) ?: [];
                    if (!hash_equals((string) ($detail['request_hash'] ?? ''), $hash)) {
                        return self::error(
                            'conflict',
                            '该请求编号已用于另一项操作，请刷新后重试。',
                            409,
                        );
                    }
                    $permission = self::permission((int) $existing['user_id']);
                    if ($permission !== true) {
                        return $permission;
                    }
                    return [
                        'event_id' => (int) ($detail['result_event_id'] ?? $existing['id']),
                        'user_id' => (int) $existing['user_id'],
                        'duplicate' => true,
                    ];
                }
                return $operation($key, [
                    'actor' => get_current_user_id(),
                    'reason' => $input['reason'],
                    'request_id' => $input['request_id'],
                    'request_hash' => $hash,
                ]);
            });
        } catch (Throwable $e) {
            self::clear_option_cache();
            error_log('reader_experience management operation failed: ' . $action);
            return self::error('storage', '操作未能完成，请保留请求编号后重试。', 503);
        }
    }
    private static function balance_allows(int $uid, int $xp)
    {
        wp_cache_delete($uid, 'user_meta');
        $balance = (int) get_user_meta($uid, Reader_Experience::balance_meta(), true);
        if ($xp < 0 && $balance < -$xp) {
            return self::error('insufficient_balance', '该用户的经验不足，无法完成扣减。', 409);
        }
        if ($xp > 0 && $balance > PHP_INT_MAX - $xp) {
            return self::error('input', '经验余额超出可用范围。');
        }
        return true;
    }
    public static function adjust(array $input, $nonce)
    {
        $input = self::input($input, $nonce, ['user_id', 'xp']);
        if ($input instanceof WP_Error) {
            return $input;
        }
        $uid = self::integer($input['user_id'], 1, PHP_INT_MAX);
        $xp = self::integer($input['xp'], -1000000, 1000000);
        if ($uid === null || $xp === null || $xp === 0) {
            return self::error('input', '请选择用户并填写非零整数经验，单次最多 1000000。');
        }
        $input['user_id'] = $uid;
        $input['xp'] = $xp;
        return self::execute('adjust', $input, function ($key, $detail) use ($uid, $xp) {
            $permission = self::permission($uid);
            if ($permission !== true) {
                return $permission;
            }
            if (!get_userdata($uid)) {
                return self::error('missing', '用户不存在。', 404);
            }
            $allowed = self::balance_allows($uid, $xp);
            if ($allowed !== true) {
                return $allowed;
            }
            if (
                !Reader_Experience::record(
                    $key,
                    $uid,
                    'manual_adjust',
                    0,
                    $xp,
                    Reader_Experience::day(),
                    $detail,
                )
            ) {
                throw new RuntimeException('Experience adjustment failed');
            }
            return [
                'event_id' => (int) self::event($key)['id'],
                'user_id' => $uid,
                'duplicate' => false,
            ];
        });
    }
    public static function reverse(array $input, $nonce)
    {
        $input = self::input($input, $nonce, ['event_id']);
        if ($input instanceof WP_Error) {
            return $input;
        }
        $id = self::integer($input['event_id'], 1, PHP_INT_MAX);
        if ($id === null) {
            return self::error('input', '记录编号无效。');
        }
        $input['event_id'] = $id;
        return self::execute('reverse', $input, function ($key, $detail) use ($id) {
            global $wpdb;
            $original = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . Reader_Experience::table() . ' WHERE id=%d', $id),
                ARRAY_A,
            );
            if (!$original || $original['kind'] !== 'manual_adjust') {
                return self::error('input', '只能撤销人工调整记录。');
            }
            $uid = (int) $original['user_id'];
            $permission = self::permission($uid);
            if ($permission !== true) {
                return $permission;
            }
            if (!get_userdata($uid)) {
                return self::error('missing', '用户不存在。', 404);
            }
            if (
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT id FROM ' .
                            Reader_Experience::table() .
                            " WHERE kind='manual_reversal' AND object_id=%d",
                        $id,
                    ),
                )
            ) {
                return self::error('conflict', '这项人工调整已经撤销。', 409);
            }
            $xp = -(int) $original['xp'];
            $allowed = self::balance_allows($uid, $xp);
            if ($allowed !== true) {
                return $allowed;
            }
            $detail['original'] = $id;
            if (
                !Reader_Experience::record(
                    $key,
                    $uid,
                    'manual_reversal',
                    $id,
                    $xp,
                    Reader_Experience::day(),
                    $detail,
                )
            ) {
                throw new RuntimeException('Experience reversal failed');
            }
            return [
                'event_id' => (int) self::event($key)['id'],
                'user_id' => $uid,
                'duplicate' => false,
            ];
        });
    }
    public static function retry(array $input, $nonce)
    {
        $input = self::input($input, $nonce, ['failure_id']);
        if ($input instanceof WP_Error) {
            return $input;
        }
        if (
            !is_string($input['failure_id']) ||
            !preg_match('/^[a-f0-9]{32}$/D', $input['failure_id'])
        ) {
            return self::error('input', '未完成记录编号无效。');
        }
        return self::execute('retry', $input, function ($key, $detail) use ($input) {
            self::require_options_transaction();
            $rows = self::option_rows(self::FAILURE_OPTION);
            $failure = $rows[$input['failure_id']] ?? null;
            if (!$failure || !in_array($failure['kind'], ['article', 'comment'], true)) {
                return self::error('missing', '未找到可重试的记录。', 404);
            }
            $uid = (int) $failure['user_id'];
            $permission = self::permission($uid);
            if ($permission !== true) {
                return $permission;
            }
            if (!get_userdata($uid)) {
                return self::error('missing', '用户不存在。', 404);
            }
            $existing = self::event($failure['event_key']);
            if (!$existing) {
                if ($failure['status'] !== 'pending' || !self::retry_eligible($failure)) {
                    return self::error('ineligible', '该文章或评论目前不符合奖励条件。', 409);
                }
                $allowed = self::balance_allows($uid, (int) $failure['xp']);
                if ($allowed !== true) {
                    return $allowed;
                }
                if (
                    !Reader_Experience::record(
                        $failure['event_key'],
                        $uid,
                        $failure['kind'],
                        (int) $failure['object_id'],
                        (int) $failure['xp'],
                        $failure['local_day'],
                        [
                            'retry_actor' => get_current_user_id(),
                            'failure_id' => $failure['id'],
                            'reason' => $input['reason'],
                        ],
                    )
                ) {
                    throw new RuntimeException('Experience retry failed');
                }
                $existing = self::event($failure['event_key']);
            }
            $rows[$input['failure_id']]['status'] = 'resolved';
            $rows[$input['failure_id']]['resolved_at'] = time();
            self::store_rows(self::FAILURE_OPTION, $rows);
            $detail['failure_id'] = $failure['id'];
            $detail['result_event_id'] = (int) $existing['id'];
            Reader_Experience::record(
                $key,
                $uid,
                'management_retry',
                (int) $failure['object_id'],
                0,
                Reader_Experience::day(),
                $detail,
            );
            return ['event_id' => (int) $existing['id'], 'user_id' => $uid, 'duplicate' => false];
        });
    }
    private static function retry_eligible(array $failure): bool
    {
        if ($failure['kind'] === 'article') {
            $p = get_post((int) $failure['object_id']);
            return $p &&
                $p->post_type === 'post' &&
                $p->post_status === 'publish' &&
                (int) $p->post_author === (int) $failure['user_id'];
        }
        $c = get_comment((int) $failure['object_id']);
        if (!$c) {
            return false;
        }
        $p = get_post((int) $c->comment_post_ID);
        $parent = $c->comment_parent ? get_comment((int) $c->comment_parent) : null;
        $uid = (int) $failure['user_id'];
        return (int) $c->user_id === $uid &&
            $c->comment_approved === '1' &&
            in_array($c->comment_type, ['', 'comment'], true) &&
            $p &&
            $p->post_status === 'publish' &&
            in_array($p->post_type, ['post', 'page'], true) &&
            (int) $p->post_author !== $uid &&
            (!$parent || (int) $parent->user_id !== $uid) &&
            Reader_Experience::count_day($uid, 'comment', $failure['local_day']) <
                WP_XP_Core_Settings::rules()['comment_limit'];
    }
}
