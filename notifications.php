<?php
/** A private inbox derived from durable ledger events; reading never awards or copies events. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Notifications
{
    const TYPES = ['task', 'review', 'level'];
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function key(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wp_xp_core_inbox';
    }
    private static function permission($nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return self::error('login_required', '请先登录。', 401);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('nonce', '验证已过期，请刷新页面。', 403);
        }
        return Reader_Experience::ready()
            ? true
            : self::error('maintenance', '经验系统维护中。', 503);
    }
    private static function integer($value, int $min, int $max): int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
            strlen((string) $value) > strlen((string) PHP_INT_MAX) ||
            (strlen((string) $value) === strlen((string) PHP_INT_MAX) &&
                strcmp((string) $value, (string) PHP_INT_MAX) > 0) ||
            (int) $value < $min ||
            (int) $value > $max
        ) {
            throw new InvalidArgumentException('通知分页或编号无效。');
        }
        return (int) $value;
    }
    private static function identifier($id): array
    {
        if (!is_string($id) || !preg_match('/^([1-9][0-9]*):(task|review|level)$/D', $id, $m)) {
            throw new InvalidArgumentException('通知编号无效。');
        }
        return [self::integer($m[1], 1, PHP_INT_MAX), array_search($m[2], self::TYPES, true)];
    }
    private static function raw(int $uid)
    {
        wp_cache_delete($uid, 'user_meta');
        return metadata_exists('user', $uid, self::key())
            ? get_user_meta($uid, self::key(), true)
            : null;
    }
    private static function state($raw): array
    {
        $default = [
            'preferences' => array_fill_keys(self::TYPES, true),
            'read_until' => 0,
            'read_ids' => [],
        ];
        if (!is_array($raw)) {
            return $default;
        }
        $default['read_until'] =
            is_int($raw['read_until'] ?? null) && $raw['read_until'] >= 0 ? $raw['read_until'] : 0;
        foreach (self::TYPES as $type) {
            if (is_bool($raw['preferences'][$type] ?? null)) {
                $default['preferences'][$type] = $raw['preferences'][$type];
            }
        }
        $default['read_ids'] = is_array($raw['read_ids'] ?? null)
            ? array_slice(array_filter($raw['read_ids'], 'is_string'), 0, 2000)
            : [];
        return $default;
    }
    private static function maximum(int $uid): int
    {
        global $wpdb;
        $maximum = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(MAX(id),0) FROM ' .
                    Reader_Experience::table() .
                    ' WHERE user_id=%d',
                $uid,
            ),
        );
        if ($wpdb->last_error !== '' || $maximum === null) {
            throw new RuntimeException('Inbox boundary unavailable');
        }
        return (int) $maximum;
    }
    /** Event schema is persisted with the reward, making hook failures irrelevant to recovery. */
    private static function messages(array $event): array
    {
        $detail = json_decode($event['detail'] ?? '', true);
        if (!is_array($detail) || ($detail['notification_schema'] ?? null) !== 1) {
            return [];
        }
        $messages = [];
        if (
            in_array($event['kind'], ['task_complete', 'task_grant'], true) &&
            is_string($detail['title'] ?? null)
        ) {
            $messages['task'] = [
                'title' => '获得成就',
                'message' =>
                    $detail['title'] .
                    ((int) $event['xp'] > 0 ? ' · +' . (int) $event['xp'] . ' 经验' : ''),
            ];
        } elseif (in_array($event['kind'], ['task_revoke', 'task_grant_revoke'], true)) {
            $messages['task'] = [
                'title' => '成就已撤销',
                'message' => is_string($detail['title'] ?? null)
                    ? $detail['title']
                    : '一项成就已撤销，详情可查看个人经验记录。',
            ];
        } elseif (
            $event['kind'] === 'achievement_review' &&
            in_array($detail['decision'] ?? '', ['approve', 'reject'], true)
        ) {
            $messages['review'] = [
                'title' => $detail['decision'] === 'approve' ? '审核已通过' : '完成说明已退回',
                'message' =>
                    is_string($detail['feedback'] ?? null) && $detail['feedback'] !== ''
                        ? $detail['feedback']
                        : '可在任务与成就中查看审核结果。',
            ];
        }
        $transition = $detail['level_transition'] ?? null;
        if (
            is_array($transition) &&
            is_int($transition['before'] ?? null) &&
            is_int($transition['after'] ?? null) &&
            $transition['after'] > $transition['before']
        ) {
            $messages['level'] = [
                'title' => '等级提升',
                'message' => '已升至 Level ' . $transition['after'] . '。',
            ];
        }
        $out = [];
        foreach (self::TYPES as $type) {
            if (isset($messages[$type])) {
                $out[] =
                    [
                        'id' => $event['id'] . ':' . $type,
                        'type' => $type,
                        'created_at' => (int) $event['created_at'],
                        'day' => $event['local_day'],
                    ] + $messages[$type];
            }
        }
        return $out;
    }
    public static function listing(array $input, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            if (array_diff(array_keys($input), ['cursor', 'limit'])) {
                return self::error('input', '通知查询字段无效。');
            }
            $limit = self::integer($input['limit'] ?? 20, 1, 50);
            $cursor = isset($input['cursor']) ? self::identifier($input['cursor']) : null;
            $uid = get_current_user_id();
            $raw = self::raw($uid);
            $state = self::state($raw);
            global $wpdb;
            $through = self::maximum($uid);
            $where = $wpdb->prepare('user_id=%d AND id<=%d', $uid, $through);
            if ($cursor) {
                $where .= $wpdb->prepare(
                    $cursor[1] === 2 ? ' AND id<%d' : ' AND id<=%d',
                    $cursor[0],
                );
            }
            // Only v1 persisted notification facts are candidates. JSON_VALID protects legacy data.
            $detail = "IF(JSON_VALID(detail),detail,'{}')";
            $where .= " AND JSON_EXTRACT($detail,'$.notification_schema')=1 AND (kind IN ('task_complete','task_grant','task_revoke','task_grant_revoke','achievement_review') OR JSON_EXTRACT($detail,'$.level_transition.after')>JSON_EXTRACT($detail,'$.level_transition.before'))";
            $rows = $wpdb->get_results(
                'SELECT id,kind,xp,detail,created_at,local_day FROM ' .
                    Reader_Experience::table() .
                    ' WHERE ' .
                    $where .
                    ' ORDER BY id DESC LIMIT ' .
                    ($limit + 1),
                ARRAY_A,
            );
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                throw new RuntimeException('Inbox unavailable');
            }
            $more = count($rows) > $limit;
            $items = [];
            $next = null;
            foreach (array_slice($rows, 0, $limit) as $row) {
                foreach (self::messages($row) as $message) {
                    $index = array_search($message['type'], self::TYPES, true);
                    if ($cursor && (int) $row['id'] === $cursor[0] && $index <= $cursor[1]) {
                        continue;
                    }
                    if (!$state['preferences'][$message['type']]) {
                        continue;
                    }
                    if (count($items) === $limit) {
                        $more = true;
                        break 2;
                    }
                    $message['read'] =
                        (int) $row['id'] <= $state['read_until'] ||
                        in_array($message['id'], $state['read_ids'], true);
                    $items[] = $message;
                    $next = $message['id'];
                }
                if (count($items) < $limit) {
                    $next = $row['id'] . ':level';
                }
            }
            return [
                'items' => $items,
                'next_cursor' => $more ? $next : null,
                'through' => $through,
                'preferences' => $state['preferences'],
                'revision' => WP_XP_Core_Settings::revision($raw),
            ];
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '通知暂时无法读取，请重试。', 503);
        }
    }
    public static function save(array $input, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            if (
                array_diff(array_keys($input), ['revision', 'preferences', 'ids', 'through']) ||
                !is_string($input['revision'] ?? null) ||
                strlen($input['revision']) !== 64 ||
                (isset($input['preferences']) && (isset($input['ids']) || isset($input['through'])))
            ) {
                return self::error('input', '通知设置字段无效。');
            }
            if (
                !isset($input['preferences']) &&
                !isset($input['ids']) &&
                !isset($input['through'])
            ) {
                return self::error('input', '请选择已读操作或通知偏好。');
            }
            if (isset($input['preferences'])) {
                if (
                    !is_array($input['preferences']) ||
                    array_diff(array_keys($input['preferences']), self::TYPES) ||
                    count($input['preferences']) !== count(self::TYPES)
                ) {
                    return self::error('input', '通知类型偏好无效。');
                }
                foreach ($input['preferences'] as $v) {
                    if (!is_bool($v)) {
                        return self::error('input', '通知偏好须为布尔值。');
                    }
                }
            }
            if (
                isset($input['ids']) &&
                (!is_array($input['ids']) || count($input['ids']) > 50 || isset($input['through']))
            ) {
                return self::error('input', '每次最多标记五十条通知。');
            }
            $ids = [];
            foreach ($input['ids'] ?? [] as $id) {
                $parsed = self::identifier($id);
                $ids[$id] = $parsed;
            }
            $through = isset($input['through'])
                ? self::integer($input['through'], 0, PHP_INT_MAX)
                : null;
            return Reader_Experience::transaction(function () use ($input, $ids, $through) {
                if (!Reader_Experience::ready()) {
                    return self::error('maintenance', '经验系统维护中。', 503);
                }
                $uid = get_current_user_id();
                $raw = self::raw($uid);
                $state = self::state($raw);
                if (!hash_equals(WP_XP_Core_Settings::revision($raw), $input['revision'])) {
                    return self::error('conflict', '通知设置已更新，请重新读取后操作。', 409);
                }
                if (isset($input['preferences'])) {
                    $state['preferences'] = $input['preferences'];
                }
                if ($through !== null) {
                    if ($through > self::maximum($uid)) {
                        return self::error('input', '已读范围超过当前通知。');
                    }
                    $state['read_until'] = max($state['read_until'], $through);
                    $state['read_ids'] = array_values(
                        array_filter(
                            $state['read_ids'],
                            fn($id) => self::identifier($id)[0] > $state['read_until'],
                        ),
                    );
                }
                foreach ($ids as $id => [$event_id]) {
                    global $wpdb;
                    $row = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT id,kind,xp,detail,created_at,local_day FROM ' .
                                Reader_Experience::table() .
                                ' WHERE id=%d AND user_id=%d',
                            $event_id,
                            $uid,
                        ),
                        ARRAY_A,
                    );
                    if ($wpdb->last_error !== '') {
                        throw new RuntimeException('Notification ownership unavailable');
                    }
                    if (!$row || !in_array($id, array_column(self::messages($row), 'id'), true)) {
                        return self::error('missing', '通知不存在。', 404);
                    }
                    if (
                        $event_id > $state['read_until'] &&
                        !in_array($id, $state['read_ids'], true)
                    ) {
                        $state['read_ids'][] = $id;
                    }
                }
                if (count($state['read_ids']) > 2000) {
                    return self::error('limit', '请先将当前通知全部标为已读。', 409);
                }
                sort($state['read_ids'], SORT_STRING);
                if ($state !== self::state($raw) || $raw === null) {
                    if (!update_user_meta($uid, self::key(), $state)) {
                        throw new RuntimeException('Inbox state write failed');
                    }
                }
                return [
                    'saved' => true,
                    'revision' => WP_XP_Core_Settings::revision($state),
                    'preferences' => $state['preferences'],
                ];
            });
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', '通知设置未能保存，请重试。', 503);
        }
    }
}
