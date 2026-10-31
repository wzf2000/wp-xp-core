<?php
/** Administrator-only explanations and hypothetical primary receipts, without reward callbacks. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Reward_Inspector
{
    private static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function permission($nonce, int $uid = 0)
    {
        $allowed = WP_XP_Core_Management::permission($uid);
        if ($allowed !== true) {
            return $allowed;
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_manage')) {
            return self::error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), 403);
        }
        return Reader_Experience::ready()
            ? true
            : self::error('maintenance', wp_xp_core_t('经验系统维护中。'), 503);
    }
    private static function integer($value, int $minimum = 0): int
    {
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]{0,15})$/D', (string) $value) ||
            (int) $value < $minimum
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请填写有效的用户、对象或事件编号。'));
        }
        return (int) $value;
    }
    private static function row(string $sql): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Inspection read unavailable');
        }
        return $row ?: null;
    }
    private static function check(
        array &$checks,
        string $rule,
        string $state,
        string $message,
    ): void {
        $checks[] = ['rule' => $rule, 'state' => $state, 'message' => $message];
    }
    public static function inspect(array $input, $nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            if (($input['mode'] ?? '') === 'receipt') {
                if (array_diff(array_keys($input), ['mode', 'event_id'])) {
                    throw new InvalidArgumentException(wp_xp_core_t('查询条件无效。'));
                }
                return self::receipt(self::integer($input['event_id'] ?? null, 1), $nonce);
            }
            if (
                ($input['mode'] ?? '') !== 'simulate' ||
                array_diff(array_keys($input), [
                    'mode',
                    'user_id',
                    'kind',
                    'object_id',
                    'provider_id',
                    'event',
                    'score',
                ])
            ) {
                throw new InvalidArgumentException(wp_xp_core_t('查询条件无效。'));
            }
            $uid = self::integer($input['user_id'] ?? null, 1);
            $allowed = self::permission($nonce, $uid);
            if ($allowed !== true) {
                return $allowed;
            }
            if (!get_userdata($uid)) {
                return self::error('missing', wp_xp_core_t('用户不存在。'), 404);
            }
            return self::simulate($input, $uid);
        } catch (InvalidArgumentException $e) {
            return self::error('input', $e->getMessage());
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法解释或模拟奖励，请重试。'), 503);
        }
    }
    private static function receipt(int $id, $nonce)
    {
        global $wpdb;
        $row = self::row(
            $wpdb->prepare('SELECT * FROM ' . Reader_Experience::table() . ' WHERE id=%d', $id),
        );
        if (!$row) {
            return self::error('missing', wp_xp_core_t('活动记录不存在。'), 404);
        }
        $allowed = self::permission($nonce, (int) $row['user_id']);
        if ($allowed !== true) {
            return $allowed;
        }
        $detail = json_decode($row['detail'] ?? '', true);
        $detail = is_array($detail) ? $detail : [];
        $snapshots = [];
        // Only accounting facts; never return private notification text, context or admin reasons.
        foreach (
            [
                'rule_revision' => null,
                'provider' => null,
                'provider_label' => null,
                'activity_score' => null,
                'provider_quota' => ['limit', 'exhausted', 'requested_xp'],
                'reward_hold' => ['requested_xp', 'until', 'held'],
                'activity_cap' => [
                    'requested_xp',
                    'actual_xp',
                    'daily_limit',
                    'weekly_limit',
                    'daily_gross_before',
                    'weekly_gross_before',
                    'week_from',
                    'week_to',
                    'truncated',
                ],
            ]
            as $key => $fields
        ) {
            $value = $detail[$key] ?? null;
            if ($fields !== null && is_array($value)) {
                $value = array_intersect_key($value, array_flip($fields));
                $value = array_filter(
                    $value,
                    static fn($item) => is_scalar($item) || $item === null,
                );
            } elseif ($fields !== null || (!is_scalar($value) && $value !== null)) {
                $value = null;
            }
            $snapshots[$key] = $value;
        }
        $reversal = null;
        if ($row['kind'] === 'provider_reward') {
            $reversal = self::row(
                $wpdb->prepare(
                    'SELECT id,xp FROM ' .
                        Reader_Experience::table() .
                        " WHERE event_key=%s AND kind='provider_revoke' AND user_id=%d AND object_id=%d",
                    'provider-revoke:' . $id,
                    $row['user_id'],
                    $id,
                ),
            );
        }
        return [
            'mode' => 'receipt',
            'event_id' => $id,
            'user_id' => (int) $row['user_id'],
            'kind' => $row['kind'],
            'object_id' => (int) $row['object_id'],
            'local_day' => $row['local_day'],
            'actual_xp' => (int) $row['xp'],
            'snapshots' => $snapshots,
            'reversal' => $reversal
                ? ['event_id' => (int) $reversal['id'], 'xp' => (int) $reversal['xp']]
                : null,
            'note' => wp_xp_core_t('按原回执快照解释；未保存的事实显示为空，不用当前规则重算。'),
        ];
    }
    private static function simulate(array $input, int $uid): array
    {
        global $wpdb;
        $kind = $input['kind'] ?? '';
        if (!is_string($kind) || !in_array($kind, WP_XP_Core_Achievements::KINDS, true)) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择有效的任务步骤。'));
        }
        $object = self::integer($input['object_id'] ?? 0);
        $provider = $input['provider_id'] ?? '';
        $identity = $input['event'] ?? '';
        if (
            !is_string($identity) ||
            strlen($identity) > 191 ||
            preg_match('/[\x00-\x1f]/', $identity)
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('活动编号无效。'));
        }
        if (
            !is_string($provider) ||
            ($provider !== '' && !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $provider))
        ) {
            throw new InvalidArgumentException(wp_xp_core_t('请选择已注册的活动来源。'));
        }
        $score = ($input['score'] ?? '') === '' ? null : self::integer($input['score']);
        $rules = WP_XP_Core_Settings::rules();
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Inspection policy unavailable');
        }
        $day = Reader_Experience::day();
        $checks = [];
        $key = '';
        $xp = 0;
        $eligible = true;
        $quota = null;
        $boundary = null;
        if ($kind === 'provider_reward') {
            if ($provider === '') {
                throw new InvalidArgumentException(wp_xp_core_t('请选择已注册的活动来源。'));
            }
            $definition = WP_XP_Core_Providers::definitions()[$provider] ?? null;
            $provider_registered = $definition !== null;
            $policy = $rules['providers'][$provider] ?? ['enabled' => false, 'xp' => 0];
            $enabled = $provider_registered && $policy['enabled'];
            self::check(
                $checks,
                'source',
                $provider_registered ? 'pass' : 'blocked',
                $provider_registered
                    ? wp_xp_core_t('来源已注册，运行资格尚未由来源验证。')
                    : wp_xp_core_t('来源未加载。'),
            );
            self::check(
                $checks,
                'eligibility',
                'unknown',
                wp_xp_core_t('假设服务端资格满足；未调用来源验证或读取权威发生时间。'),
            );
            if ($identity !== '') {
                $key = 'provider:' . $provider . ':' . hash('sha256', $identity);
            } else {
                self::check(
                    $checks,
                    'identity',
                    'unknown',
                    wp_xp_core_t('未填写稳定活动编号，无法核对是否重复。'),
                );
            }
            if (isset($definition['facts'])) {
                $boundary = WP_XP_Core_Providers::boundary($provider);
                self::check(
                    $checks,
                    'created_at',
                    $boundary > 0 ? 'unknown' : 'blocked',
                    $boundary > 0
                        ? wp_xp_core_t('来源发生时间须晚于当前服务器边界，此处尚未验证。')
                        : wp_xp_core_t('来源缺少服务器边界，请先由管理员保存活动规则。'),
                );
                $eligible = $boundary > 0;
            }
            if (isset($policy['min_score'])) {
                $passed = $score !== null && $score >= $policy['min_score'];
                self::check(
                    $checks,
                    'score',
                    $score === null ? 'unknown' : ($passed ? 'pass' : 'blocked'),
                    wp_xp_core_t('分数仅为模拟输入，须由来源核实。'),
                );
                if ($score !== null && !$passed) {
                    $eligible = false;
                }
            }
            $xp = (int) $policy['xp'];
            $limit = $policy['day_limit'] ?? null;
            if ($limit !== null) {
                $used = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM ' .
                            Reader_Experience::table() .
                            " WHERE user_id=%d AND kind='provider_reward' AND local_day=%s AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(detail),detail,'{}'),'$.provider'))=%s",
                        $uid,
                        $day,
                        $provider,
                    ),
                );
                if ($wpdb->last_error !== '' || $used === null) {
                    throw new RuntimeException('Inspection provider quota unavailable');
                }
                $quota = [
                    'used' => (int) $used,
                    'limit' => $limit,
                    'exhausted' => (int) $used >= $limit,
                ];
                if ($limit === 0) {
                    $eligible = false;
                }
            }
        } else {
            if ($provider !== '' || $identity !== '' || $score !== null) {
                throw new InvalidArgumentException(wp_xp_core_t('只有扩展活动可以指定来源。'));
            }
            $enabled = (bool) $rules['activities'][$kind];
            $xp = $rules[$kind . '_xp'];
            $key = match ($kind) {
                'checkin' => "checkin:$uid:$day",
                'visit' => "visit:$uid:$day:$object",
                default => "$kind:$object",
            };
            if ($kind === 'checkin') {
                if ($object !== 0) {
                    throw new InvalidArgumentException(wp_xp_core_t('签到的对象编号须为零。'));
                }
            } else {
                $comment = $kind === 'comment' ? get_comment($object) : null;
                $post_id = $kind === 'comment' ? (int) ($comment->comment_post_ID ?? 0) : $object;
                $post = $post_id > 0 ? get_post($post_id) : null;
                $eligible =
                    $post &&
                    $post->post_status === 'publish' &&
                    $post->post_password === '' &&
                    in_array($post->post_type, ['post', 'page'], true) &&
                    WP_XP_Core_Settings::in_scope($post_id);
                if ($kind === 'visit') {
                    $eligible =
                        $post &&
                        in_array($post->post_type, ['post', 'page'], true) &&
                        ($post->post_status === 'publish' ||
                            user_can($uid, 'read_post', $post_id)) &&
                        WP_XP_Core_Settings::in_scope($post_id);
                }
                if ($kind === 'article') {
                    $eligible =
                        $post &&
                        $post->post_status === 'publish' &&
                        $post->post_type === 'post' &&
                        (int) $post->post_author === $uid &&
                        WP_XP_Core_Settings::in_scope($post_id);
                }
                if ($kind === 'comment') {
                    $parent = !empty($comment->comment_parent)
                        ? get_comment($comment->comment_parent)
                        : null;
                    $eligible =
                        $eligible &&
                        $comment &&
                        (int) $comment->user_id === $uid &&
                        $comment->comment_approved === '1' &&
                        in_array($comment->comment_type, ['', 'comment'], true) &&
                        (int) $post->post_author !== $uid &&
                        (!$parent || (int) $parent->user_id !== $uid);
                }
                self::check(
                    $checks,
                    'object',
                    $eligible ? 'pass' : 'blocked',
                    wp_xp_core_t('按目标用户的对象访问资格、归属与内容范围核对。'),
                );
                self::check(
                    $checks,
                    'request',
                    'unknown',
                    wp_xp_core_t('未验证真实阅读停留或新建请求，只模拟所选主体回执。'),
                );
            }
            if (in_array($kind, ['visit', 'comment'], true)) {
                $used = Reader_Experience::count_day($uid, $kind, $day);
                $limit = $rules[$kind . '_limit'];
                $quota = ['used' => $used, 'limit' => $limit, 'exhausted' => $used >= $limit];
                $eligible = $eligible && !$quota['exhausted'];
            }
        }
        self::check(
            $checks,
            'enabled',
            $enabled ? 'pass' : 'blocked',
            $enabled ? wp_xp_core_t('活动记录已启用。') : wp_xp_core_t('活动记录未启用。'),
        );
        $existing =
            $key !== ''
                ? self::row(
                    $wpdb->prepare(
                        'SELECT id,user_id,object_id,kind,xp FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $key,
                    ),
                )
                : null;
        if ($existing) {
            $same =
                (int) $existing['user_id'] === $uid &&
                (int) $existing['object_id'] === $object &&
                $existing['kind'] === $kind;
            self::check(
                $checks,
                'identity',
                $same ? 'duplicate' : 'blocked',
                $same
                    ? wp_xp_core_t('已有相同活动回执，不会新增经验。')
                    : wp_xp_core_t('活动编号归属冲突。'),
            );
        }
        if ($quota !== null) {
            self::check(
                $checks,
                'quota',
                $quota['exhausted']
                    ? ($kind === 'provider_reward' && $quota['limit'] > 0
                        ? 'zero'
                        : 'blocked')
                    : 'pass',
                $quota['exhausted']
                    ? ($kind === 'provider_reward' && $quota['limit'] > 0
                        ? wp_xp_core_t('今日次数已用完，合法新活动仍可保留零经验回执。')
                        : wp_xp_core_t('今日次数已用完，不新增活动回执。'))
                    : wp_xp_core_t('今日次数尚未用完。'),
            );
        }
        if ($kind === 'comment' && $existing) {
            self::check(
                $checks,
                'compensation',
                'unknown',
                wp_xp_core_t(
                    '已有评论回执的恢复或补偿不在新活动模拟范围，请解释原回执并查看对应补偿记录。',
                ),
            );
        }
        $requested = $xp;
        $after_quota = $quota && $quota['exhausted'] ? 0 : $xp;
        $detail = [];
        $hold = WP_XP_Core_Reward_Controls::status($uid);
        $after_hold = WP_XP_Core_Reward_Controls::evaluate($after_quota, $hold, $detail);
        $totals = WP_XP_Core_Fairness::totals($uid, $day);
        $actual = WP_XP_Core_Fairness::evaluate(
            $after_hold,
            $rules['activity_caps'],
            $totals,
            $detail,
        );
        if (!$enabled || !$eligible || $existing) {
            $actual = 0;
        }
        $balance = get_user_meta($uid, Reader_Experience::balance_meta(), true);
        if (
            $wpdb->last_error !== '' ||
            ($balance !== '' && !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $balance))
        ) {
            throw new RuntimeException('Inspection balance unavailable');
        }
        $overflow = $actual > 0 && (int) $balance > PHP_INT_MAX - $actual;
        if ($overflow) {
            self::check($checks, 'balance', 'blocked', wp_xp_core_t('经验余额将溢出，不能记账。'));
            $actual = 0;
        }
        return [
            'mode' => 'simulate',
            'observed_at' => time(),
            'local_day' => $day,
            'user_id' => $uid,
            'kind' => $kind,
            'object_id' => $object,
            'provider_id' => $provider,
            'eligibility' => $kind === 'checkin' ? 'verified' : 'unverified',
            'checks' => $checks,
            'requested_xp' => $requested,
            'after_quota' => $after_quota,
            'after_hold' => $after_hold,
            'hypothetical_xp' => $actual,
            'may_record' =>
                !$enabled || !$eligible || $existing || $overflow
                    ? false
                    : ($kind === 'checkin'
                        ? true
                        : null),
            'existing_event_id' => $existing ? (int) $existing['id'] : null,
            'quota' => $quota,
            'hold' => $hold,
            'gross_totals' => $totals,
            'limits' => $rules['activity_caps'],
            'rule_effects' => $detail,
            'source_boundary_ms' => $boundary,
            'note' => wp_xp_core_t(
                '仅模拟当前主体回执，不包含签到阶段、作者里程碑或任务派生奖励；实际记账会重新检查。',
            ),
        ];
    }
    public static function capabilities($nonce)
    {
        $allowed = self::permission($nonce);
        if ($allowed !== true) {
            return $allowed;
        }
        try {
            global $wpdb;
            $rules = WP_XP_Core_Settings::rules();
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Capability policy unavailable');
            }
            $rows = [];
            foreach (['checkin', 'visit', 'comment', 'article'] as $kind) {
                $rows[] = [
                    'id' => $kind,
                    'label' => WP_XP_Core_Achievements::step_label(['kind' => $kind]),
                    'registered' => true,
                    'enabled' => (bool) $rules['activities'][$kind],
                    'eligibility' => 'core',
                ];
            }
            $definitions = WP_XP_Core_Providers::definitions();
            $ids = array_unique(
                array_merge(array_keys($definitions), array_keys($rules['providers'])),
            );
            $tasks = WP_XP_Core_Achievements::definitions();
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Capability tasks unavailable');
            }
            foreach ($tasks as $task) {
                foreach ($task['steps'] as $step) {
                    if ($step['kind'] === 'provider_reward') {
                        $ids[] = $step['provider_id'];
                    }
                }
            }
            foreach (array_unique($ids) as $id) {
                $rows[] = [
                    'id' => $id,
                    'label' => $definitions[$id]['label'] ?? $id,
                    'registered' => isset($definitions[$id]),
                    'enabled' => (bool) ($rules['providers'][$id]['enabled'] ?? false),
                    'eligibility' => 'unverified',
                ];
            }
            return [
                'rows' => $rows,
                'note' => wp_xp_core_t(
                    '已注册表示接口已接入；运行资格待来源验证，此清单不调用来源回调。',
                ),
            ];
        } catch (Throwable $e) {
            return self::error('storage', wp_xp_core_t('暂时无法解释或模拟奖励，请重试。'), 503);
        }
    }
}
