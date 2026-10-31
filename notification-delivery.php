<?php
/** Bounded, post-commit channel attempts. A crash leaves an unknown outcome, never an auto retry. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Notification_Delivery
{
    const OPTION = 'wp_xp_core_channel_delivery';
    const LEGACY = 'wp_xp_core_channel_results';
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function option(string $name, $fallback)
    {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Delivery state unavailable');
        }
        if ($raw === null) {
            return $fallback;
        }
        $value = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($value)) {
            throw new RuntimeException('Invalid delivery state');
        }
        return $value;
    }
    private static function state(): array
    {
        $state = self::option(self::OPTION, [
            'entries' => [],
            'closed_through' => 0,
            'sequence' => 0,
        ]);
        if (
            !is_array($state['entries'] ?? null) ||
            count($state['entries']) > 50 ||
            !is_int($state['closed_through'] ?? null) ||
            !is_int($state['sequence'] ?? null) ||
            $state['closed_through'] < 0 ||
            $state['sequence'] < 0
        ) {
            throw new RuntimeException('Invalid delivery state shape');
        }
        foreach ($state['entries'] as $key => $row) {
            if (
                !is_array($row) ||
                !is_int($row['event_id'] ?? null) ||
                $row['event_id'] < 1 ||
                !is_string($row['channel'] ?? null) ||
                !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $row['channel']) ||
                $key !== self::key($row['event_id'], $row['channel']) ||
                !in_array($row['status'] ?? '', ['failed', 'unknown', 'delivered'], true) ||
                !is_string($row['revision'] ?? null) ||
                $row['revision'] === '' ||
                !is_int($row['sequence'] ?? null) ||
                !is_int($row['time'] ?? null) ||
                !is_string($row['code'] ?? null) ||
                !is_int($row['attempts'] ?? null) ||
                $row['attempts'] < 0
            ) {
                throw new RuntimeException('Invalid delivery entry');
            }
        }
        return $state;
    }
    private static function key(int $event, string $channel): string
    {
        return hash('sha256', $event . ':' . $channel);
    }
    private static function rows(array $state): array
    {
        $legacy = self::option(self::LEGACY, []);
        $rows = [];
        foreach (array_slice($legacy, -50) as $row) {
            if (
                !is_array($row) ||
                !is_int($row['event_id'] ?? null) ||
                !is_string($row['channel'] ?? null) ||
                !is_string($row['code'] ?? null) ||
                !is_int($row['time'] ?? null)
            ) {
                continue;
            }
            $row['status'] =
                $row['code'] === 'delivered'
                    ? 'delivered'
                    : (in_array($row['code'], ['exception', 'unknown'], true)
                        ? 'unknown'
                        : 'failed');
            $row['revision'] = hash('sha256', serialize($row));
            $row['attempts'] = 1;
            $rows[self::key($row['event_id'], $row['channel'])] = $row;
        }
        foreach ($state['entries'] as $key => $row) {
            $rows[$key] = $row;
        }
        // Legacy insertion order and new sequence break equal-second timestamps consistently.
        $ordered = [];
        foreach (array_values($rows) as $index => $row) {
            $ordered[] = $row + ['order' => $index];
        }
        usort(
            $ordered,
            static fn($a, $b) => [$b['time'], $b['sequence'] ?? 0, $b['order']] <=> [
                $a['time'],
                $a['sequence'] ?? 0,
                $a['order'],
            ],
        );
        return array_slice($ordered, 0, 50);
    }
    private static function previous(array $state, int $event, string $channel): ?array
    {
        $current = $state['entries'][self::key($event, $channel)] ?? null;
        if ($current !== null) {
            return $current;
        }
        foreach (self::rows($state) as $row) {
            if ($row['event_id'] === $event && $row['channel'] === $channel) {
                return $row;
            }
        }
        return null;
    }
    public static function recent($nonce)
    {
        if (
            WP_XP_Core_Management::permission() !== true ||
            !is_string($nonce) ||
            !wp_verify_nonce($nonce, 'wp_xp_core_manage')
        ) {
            return self::error('forbidden', wp_xp_core_t('没有查看通知投递的权限。'), 403);
        }
        try {
            global $wpdb;
            $rows = self::rows(self::state());
            if (!$rows) {
                return ['rows' => []];
            }
            // Legacy rows have no owner. Resolve all original owners in one bounded query,
            // and use the ledger rather than trusting an optional stored user_id field.
            $ids = array_unique(array_map(static fn($row) => (int) $row['event_id'], $rows));
            $owners = $wpdb->get_results(
                'SELECT id,user_id FROM ' .
                    Reader_Experience::table() .
                    ' WHERE id IN (' .
                    implode(',', $ids) .
                    ')',
                ARRAY_A,
            );
            if ($wpdb->last_error !== '' || !is_array($owners)) {
                throw new RuntimeException('Delivery owners unavailable');
            }
            $owners = array_column($owners, 'user_id', 'id');
            $visible = [];
            foreach ($rows as $row) {
                $uid = (int) ($owners[$row['event_id']] ?? 0);
                if ($uid > 0 && WP_XP_Core_Management::permission($uid) === true) {
                    $row['user_id'] = $uid;
                    unset($row['order']);
                    $visible[] = $row;
                }
            }
            return ['rows' => $visible];
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('通知投递状态暂不可读。'), 503);
        }
    }
    private static function lock(callable $callback)
    {
        global $wpdb;
        $lock = 'wp-xp-delivery:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)', $lock)) !== 1) {
            throw new RuntimeException('Delivery busy');
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    private static function event(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id,user_id,kind,detail FROM ' . Reader_Experience::table() . ' WHERE id=%d',
                $id,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Delivery receipt unavailable');
        }
        if (!$row || !in_array($row['kind'], ['provider_reward', 'provider_revoke'], true)) {
            throw new InvalidArgumentException('Invalid activity receipt');
        }
        $detail = json_decode($row['detail'] ?? '', true);
        if (
            !is_array($detail) ||
            !is_array($detail['activity_channels'] ?? null) ||
            count($detail['activity_channels']) > 10 ||
            !is_string($detail['activity_message']['title'] ?? null) ||
            !is_string($detail['activity_message']['message'] ?? null)
        ) {
            throw new InvalidArgumentException('Missing original channel snapshot');
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'kind' => $row['kind'],
            'detail' => $detail,
        ];
    }
    private static function persist(array &$state, array $row): void
    {
        $key = self::key($row['event_id'], $row['channel']);
        $state['entries'][$key] = $row;
        uasort($state['entries'], static fn($a, $b) => $a['sequence'] <=> $b['sequence']);
        while (count($state['entries']) > 50) {
            $removed = array_shift($state['entries']);
            $state['closed_through'] = max($state['closed_through'], $removed['event_id']);
        }
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        if (!update_option(self::OPTION, $state, false)) {
            throw new RuntimeException('Delivery state write failed');
        }
    }
    private static function attempt(
        array $event,
        string $channel_id,
        array &$state,
        ?array $previous,
        ?array $request,
    ) {
        if (!in_array($channel_id, $event['detail']['activity_channels'], true)) {
            return self::error('snapshot', wp_xp_core_t('渠道不属于原活动消息快照。'), 409);
        }
        if ($request !== null) {
            $allowed = WP_XP_Core_Management::permission($event['user_id']);
            if ($allowed !== true) {
                return $allowed;
            }
            if (!$previous || $previous['status'] !== 'failed') {
                return self::error(
                    'state',
                    wp_xp_core_t('只能重试明确失败的最近消息，成功或结果未知的消息不能重投。'),
                    409,
                );
            }
            if (!hash_equals($previous['revision'], $request['revision'])) {
                return self::error(
                    'conflict',
                    wp_xp_core_t('投递状态已改变，请重新读取后再操作。'),
                    409,
                );
            }
        } elseif ($previous !== null || $event['id'] <= $state['closed_through']) {
            return null;
        }
        if (!WP_XP_Core_Notifications::activity_enabled($event['user_id'])) {
            return self::error('preference', wp_xp_core_t('本人已关闭活动通知，未尝试投递。'), 409);
        }
        $channel = WP_XP_Core_Activity_Notifications::channels()[$channel_id] ?? null;
        $available = false;
        try {
            $available = $channel && call_user_func($channel['available']) === true;
        } catch (Throwable $e) {
            $available = false;
        }
        if (!$available && $request !== null) {
            return self::error('unavailable', wp_xp_core_t('渠道当前不可用，未尝试重投。'), 409);
        }
        // Availability callbacks may themselves dispatch a committed activity on this connection.
        // Re-read under the reentrant named lock rather than overwriting their new entries.
        $state = self::state();
        $current = self::previous($state, $event['id'], $channel_id);
        if (
            $request !== null &&
            (!$current ||
                !hash_equals($current['revision'], $request['revision']) ||
                $current['status'] !== 'failed')
        ) {
            return self::error(
                'conflict',
                wp_xp_core_t('投递状态已改变，请重新读取后再操作。'),
                409,
            );
        }
        if ($request === null && ($current !== null || $event['id'] <= $state['closed_through'])) {
            return null;
        }
        $row = [
            'event_id' => $event['id'],
            'channel' => $channel_id,
            'user_id' => $event['user_id'],
            'status' => $available ? 'unknown' : 'failed',
            'code' => $available ? 'unknown' : 'unavailable',
            'revision' => wp_generate_uuid4(),
            'time' => time(),
            'sequence' => ++$state['sequence'],
            'attempts' => (int) ($previous['attempts'] ?? 0) + ($available ? 1 : 0),
            'actor' => $request !== null ? get_current_user_id() : 0,
            'reason' => $request['reason'] ?? '',
        ];
        // Durable unknown is written before invoking an external delivery callback.
        self::persist($state, $row);
        if (!$available) {
            return ['status' => 'failed', 'attempted' => false];
        }
        $code = 'unknown';
        $status = 'unknown';
        try {
            $result = call_user_func($channel['deliver'], $event['user_id'], [
                'event_id' => $event['id'],
                'kind' => $event['kind'],
                'title' => $event['detail']['activity_message']['title'],
                'message' => $event['detail']['activity_message']['message'],
            ]);
            if ($result === true) {
                $status = $code = 'delivered';
            } elseif (is_wp_error($result) || $result === false) {
                $status = 'failed';
                $code = is_wp_error($result) ? $result->get_error_code() : 'failed';
                if (!is_string($code) || !preg_match('/^[a-z0-9_]{1,40}$/D', $code)) {
                    $code = 'failed';
                }
            }
        } catch (Throwable $e) {
            /* External side effects are uncertain: retain unknown. */
        }
        $row['status'] = $status;
        $row['code'] = $code;
        // A failure here leaves the pre-call unknown receipt persisted.
        if ($status !== 'unknown') {
            $latest = self::state();
            $key = self::key($event['id'], $channel_id);
            $retained = $latest['entries'][$key] ?? null;
            // An inner dispatch may have evicted this row. Never resurrect an aged attempt.
            if ($retained && hash_equals($retained['revision'], $row['revision'])) {
                $retained['status'] = $status;
                $retained['code'] = $code;
                self::persist($latest, $retained);
                $state = $latest;
            }
        }
        return ['status' => $status, 'attempted' => true, 'event_id' => $event['id']];
    }
    public static function committed(array $input): void
    {
        if (
            !is_array($input['detail']['activity_channels'] ?? null) ||
            empty($input['detail']['activity_channels']) ||
            (int) ($input['id'] ?? 0) < 1
        ) {
            return;
        }
        foreach ($input['detail']['activity_channels'] as $channel) {
            if (!is_string($channel) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $channel)) {
                continue;
            }
            try {
                self::lock(static function () use ($input, $channel): void {
                    $event = self::event((int) $input['id']);
                    $state = self::state();
                    $previous = self::previous($state, $event['id'], $channel);
                    self::attempt($event, $channel, $state, $previous, null);
                });
            } catch (Throwable $e) {
                error_log('WP XP Core channel delivery state unavailable');
            }
        }
    }
    public static function retry(array $input, $nonce)
    {
        if (WP_XP_Core_Management::permission() !== true) {
            return self::error('forbidden', wp_xp_core_t('没有管理通知投递的权限。'), 403);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_manage')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        if (
            array_diff(array_keys($input), ['event_id', 'channel', 'revision', 'reason']) ||
            (!is_int($input['event_id'] ?? null) && !is_string($input['event_id'] ?? null)) ||
            !preg_match('/^[1-9][0-9]{0,15}$/D', (string) $input['event_id']) ||
            !is_string($input['channel'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $input['channel']) ||
            !is_string($input['revision'] ?? null) ||
            !is_string($input['reason'] ?? null) ||
            trim($input['reason']) === '' ||
            mb_strlen($input['reason']) > 300 ||
            preg_match('/[<>\x00-\x1f]/u', $input['reason'])
        ) {
            return self::error('input', wp_xp_core_t('请填写原事件、渠道、当前状态与重试原因。'));
        }
        $input['reason'] = trim($input['reason']);
        try {
            return self::lock(static function () use ($input) {
                $event = self::event((int) $input['event_id']);
                $state = self::state();
                $previous = self::previous($state, $event['id'], $input['channel']);
                return self::attempt($event, $input['channel'], $state, $previous, $input);
            });
        } catch (InvalidArgumentException $e) {
            return self::error('snapshot', wp_xp_core_t('原活动没有可重试的渠道消息快照。'), 409);
        } catch (Throwable $e) {
            return self::error(
                'storage',
                wp_xp_core_t('投递未能完成；若结果未知，请核对渠道，勿直接重投。'),
                503,
            );
        }
    }
}
