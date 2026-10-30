<?php
/** Server-only activity providers; no REST route accepts identities or reward amounts. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Providers
{
    private static array $providers = [];
    public static function register(string $id, array $definition): bool
    {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
            isset(self::$providers[$id]) ||
            count(self::$providers) >= 20 ||
            array_diff(array_keys($definition), [
                'label',
                'authorize',
                'revoke_authorize',
                'description',
                'available',
                'fields',
                'facts',
            ]) ||
            !is_string($definition['label'] ?? null) ||
            mb_strlen($definition['label']) > 80 ||
            !is_callable($definition['authorize'] ?? null) ||
            (isset($definition['revoke_authorize']) &&
                !is_callable($definition['revoke_authorize']))
        ) {
            return false;
        }
        if (
            (isset($definition['available']) && !is_callable($definition['available'])) ||
            (isset($definition['facts']) && !is_callable($definition['facts'])) ||
            (isset($definition['description']) &&
                (!is_string($definition['description']) ||
                    mb_strlen($definition['description']) > 300)) ||
            (isset($definition['fields']) && $definition['fields'] !== ['score'])
        ) {
            return false;
        }
        if (isset($definition['fields']) && !is_callable($definition['facts'] ?? null)) {
            return false;
        }
        self::$providers[$id] = $definition;
        return true;
    }
    public static function definitions(): array
    {
        return self::$providers;
    }
    const BOUNDARIES = 'wp_xp_core_provider_boundaries';
    public static function available(string $id): bool
    {
        try {
            return isset(self::$providers[$id]) &&
                (!isset(self::$providers[$id]['available']) ||
                    call_user_func(self::$providers[$id]['available']) === true);
        } catch (Throwable $e) {
            return false;
        }
    }
    private static function boundary_rows(): array
    {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                self::BOUNDARIES,
            ),
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Provider boundaries unavailable');
        }
        if ($raw === null || $raw === false) {
            return [];
        }
        $rows = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
        if (!is_array($rows) || count($rows) > 20) {
            throw new RuntimeException('Provider boundaries invalid');
        }
        foreach ($rows as $id => $row) {
            if (
                !is_string($id) ||
                !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
                !is_array($row) ||
                array_diff(array_keys($row), ['starts_ms', 'generation']) ||
                !is_int($row['starts_ms'] ?? null) ||
                $row['starts_ms'] < 1 ||
                !is_int($row['generation'] ?? null) ||
                $row['generation'] < 1
            ) {
                throw new RuntimeException('Provider boundary invalid');
            }
        }
        return $rows;
    }
    public static function boundary(string $id): int
    {
        return (int) (self::boundary_rows()[$id]['starts_ms'] ?? 0);
    }
    public static function update_boundaries(array $before, array $after): void
    {
        if (!Reader_Experience::transaction_active()) {
            throw new RuntimeException('Boundary transaction required');
        }
        $raw = self::boundary_rows();
        $next = array_intersect_key($raw, $after['providers']);
        foreach ($after['providers'] as $id => $policy) {
            $old = $before['providers'][$id] ?? [];
            $qualify = static fn($value) => [
                'xp' => (int) ($value['xp'] ?? 0),
                'day_limit' => $value['day_limit'] ?? null,
                'min_score' => (int) ($value['min_score'] ?? 0),
            ];
            if (
                $policy['enabled'] &&
                (!isset($raw[$id]) ||
                    !(bool) ($old['enabled'] ?? false) ||
                    $qualify($old) !== $qualify($policy))
            ) {
                $next[$id] = [
                    'starts_ms' => (int) floor(microtime(true) * 1000),
                    'generation' => (int) ($raw[$id]['generation'] ?? 0) + 1,
                ];
            }
        }
        if ($next !== $raw) {
            Reader_Experience::touch_option(self::BOUNDARIES);
            if (!update_option(self::BOUNDARIES, $next, false)) {
                throw new RuntimeException('Provider boundary write failed');
            }
        }
    }
    /** Append an exact reversal of an existing receipt, including capped or held zero awards. */
    public static function revoke(
        string $provider,
        string $event,
        int $uid,
        int $object_id = 0,
        array $context = [],
    ) {
        return self::reverse($provider, $event, $uid, $object_id, $context);
    }
    /** Trusted adapters resolve the original recipient only after acquiring the ledger lock. */
    public static function revoke_registered_receipt(
        string $provider,
        string $event,
        int $object_id,
        array $context = [],
    ) {
        return self::reverse($provider, $event, null, $object_id, $context);
    }
    private static function reverse(
        string $provider,
        string $event,
        ?int $uid,
        int $object_id,
        array $context,
    ) {
        if (
            !isset(self::$providers[$provider]['revoke_authorize']) ||
            $event === '' ||
            strlen($event) > 191 ||
            ($uid !== null &&
                ($uid < 1 ||
                    !get_userdata($uid) ||
                    (is_multisite() && !is_user_member_of_blog($uid)))) ||
            $object_id < 0
        ) {
            return new WP_Error('input', wp_xp_core_t('活动提供者或目标无效。'));
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'), ['status' => 503]);
        }
        try {
            return Reader_Experience::transaction(function () use (
                $provider,
                $event,
                $uid,
                $object_id,
                $context,
            ) {
                if (!Reader_Experience::ready()) {
                    throw new RuntimeException('Unavailable');
                }
                global $wpdb;
                $key = 'provider:' . $provider . ':' . hash('sha256', $event);
                $original = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                        $key,
                    ),
                    ARRAY_A,
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Original unavailable');
                }
                if (!$original) {
                    return new WP_Error('missing', wp_xp_core_t('活动记录不存在。'), [
                        'status' => 404,
                    ]);
                }
                $uid = $uid ?? (int) $original['user_id'];
                if (
                    $uid < 1 ||
                    !get_userdata($uid) ||
                    (is_multisite() && !is_user_member_of_blog($uid))
                ) {
                    return new WP_Error('input', wp_xp_core_t('活动提供者或目标无效。'));
                }
                if (
                    call_user_func(
                        self::$providers[$provider]['revoke_authorize'],
                        $uid,
                        $object_id,
                        $context,
                    ) !== true
                ) {
                    return new WP_Error('ineligible', wp_xp_core_t('活动未通过服务端资格检查。'), [
                        'status' => 403,
                    ]);
                }
                $detail = json_decode($original['detail'], true);
                if (
                    (int) $original['user_id'] !== $uid ||
                    (int) $original['object_id'] !== $object_id ||
                    $original['kind'] !== 'provider_reward' ||
                    ($detail['provider'] ?? '') !== $provider ||
                    (int) $original['xp'] < 0
                ) {
                    return new WP_Error('conflict', wp_xp_core_t('活动编号归属冲突。'), [
                        'status' => 409,
                    ]);
                }
                $reverse_key = 'provider-revoke:' . $original['id'];
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT id,user_id,kind,object_id,xp FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $reverse_key,
                    ),
                    ARRAY_A,
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Reversal unavailable');
                }
                if ($existing) {
                    if (
                        (int) $existing['user_id'] !== $uid ||
                        $existing['kind'] !== 'provider_revoke' ||
                        (int) $existing['object_id'] !== (int) $original['id'] ||
                        (int) $existing['xp'] !== -(int) $original['xp']
                    ) {
                        throw new RuntimeException('Reversal conflict');
                    }
                    return ['event_id' => (int) $existing['id'], 'duplicate' => true];
                }
                wp_cache_delete($uid, 'user_meta');
                if (
                    (int) get_user_meta($uid, Reader_Experience::balance_meta(), true) <
                    (int) $original['xp']
                ) {
                    return new WP_Error('balance', wp_xp_core_t('经验余额不足，未执行部分撤销。'), [
                        'status' => 409,
                    ]);
                }
                if (
                    !Reader_Experience::record(
                        $reverse_key,
                        $uid,
                        'provider_revoke',
                        (int) $original['id'],
                        -(int) $original['xp'],
                        Reader_Experience::day(),
                        [
                            'provider' => $provider,
                            'provider_label' => self::$providers[$provider]['label'],
                            'original_event_id' => (int) $original['id'],
                        ],
                    )
                ) {
                    throw new RuntimeException('Reversal write failed');
                }
                $id = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT id FROM ' . Reader_Experience::table() . ' WHERE event_key=%s',
                        $reverse_key,
                    ),
                );
                if ($wpdb->last_error !== '' || !$id) {
                    throw new RuntimeException('Reversal receipt unavailable');
                }
                return ['event_id' => (int) $id, 'duplicate' => false];
            });
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('活动撤销未能记录，请重试。'), [
                'status' => 503,
            ]);
        }
    }
    public static function record(
        string $provider,
        string $event,
        int $uid,
        int $object_id = 0,
        array $context = [],
    ) {
        if (
            !isset(self::$providers[$provider]) ||
            $event === '' ||
            strlen($event) > 191 ||
            $uid < 1 ||
            $object_id < 0 ||
            !get_userdata($uid) ||
            (is_multisite() && !is_user_member_of_blog($uid))
        ) {
            return new WP_Error('input', wp_xp_core_t('活动提供者或目标无效。'));
        }
        if (!Reader_Experience::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中。'));
        }
        try {
            return Reader_Experience::transaction(function () use (
                $provider,
                $event,
                $uid,
                $object_id,
                $context,
            ) {
                $definition = self::$providers[$provider];
                $policy = WP_XP_Core_Settings::rules()['providers'][$provider] ?? [
                    'enabled' => false,
                    'xp' => 0,
                ];
                if (
                    !Reader_Experience::ready() ||
                    !self::available($provider) ||
                    call_user_func($definition['authorize'], $uid, $object_id, $context, $event) !==
                        true
                ) {
                    return new WP_Error('ineligible', wp_xp_core_t('活动未通过服务端资格检查。'), [
                        'status' => 403,
                    ]);
                }
                $key = 'provider:' . $provider . ':' . hash('sha256', $event);
                global $wpdb;
                $old = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT id,user_id,object_id FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $key,
                    ),
                    ARRAY_A,
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Provider lookup failed');
                }
                if ($old) {
                    if ((int) $old['user_id'] !== $uid || (int) $old['object_id'] !== $object_id) {
                        return new WP_Error('conflict', wp_xp_core_t('活动编号归属冲突。'), [
                            'status' => 409,
                        ]);
                    }
                    return ['event_id' => (int) $old['id'], 'duplicate' => true];
                }
                if (!$policy['enabled']) {
                    return new WP_Error('disabled', wp_xp_core_t('活动奖励未启用。'));
                }
                $facts = isset($definition['facts'])
                    ? call_user_func($definition['facts'], $uid, $object_id, $context)
                    : [];
                if (!is_array($facts) || array_diff(array_keys($facts), ['score', 'created_ms'])) {
                    return new WP_Error('ineligible', wp_xp_core_t('活动未通过服务端资格检查。'));
                }
                if (
                    isset($definition['facts']) &&
                    (!isset($facts['created_ms']) ||
                        (($definition['fields'] ?? []) === ['score'] && !isset($facts['score'])))
                ) {
                    return new WP_Error('ineligible', wp_xp_core_t('活动未通过服务端资格检查。'));
                }
                foreach ($facts as $value) {
                    if (!is_int($value) || $value < 0) {
                        return new WP_Error(
                            'ineligible',
                            wp_xp_core_t('活动未通过服务端资格检查。'),
                        );
                    }
                }
                if (
                    isset($facts['created_ms']) &&
                    (self::boundary($provider) === 0 ||
                        $facts['created_ms'] <= self::boundary($provider))
                ) {
                    return new WP_Error('historical', wp_xp_core_t('本活动早于当前规则启用时间。'));
                }
                if (
                    isset($policy['min_score']) &&
                    (!isset($facts['score']) || $facts['score'] < $policy['min_score'])
                ) {
                    return new WP_Error('threshold', wp_xp_core_t('本次分数未达到奖励条件。'));
                }
                $day = Reader_Experience::day();
                $limit = $policy['day_limit'] ?? null;
                $quota_exhausted = false;
                if ($limit === 0) {
                    return new WP_Error('quota', wp_xp_core_t('此活动今日奖励次数已用完。'));
                }
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
                        throw new RuntimeException('Provider quota unavailable');
                    }
                    $quota_exhausted = (int) $used >= $limit;
                }
                if (
                    !Reader_Experience::record(
                        $key,
                        $uid,
                        'provider_reward',
                        $object_id,
                        $quota_exhausted ? 0 : $policy['xp'],
                        $day,
                        [
                            'provider' => $provider,
                            'provider_label' => $definition['label'],
                            'activity_score' => $facts['score'] ?? null,
                            'provider_quota' => [
                                'limit' => $limit,
                                'exhausted' => $quota_exhausted,
                                'requested_xp' => $policy['xp'],
                            ],
                        ],
                    )
                ) {
                    throw new RuntimeException('Provider reward failed');
                }
                $recorded = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT id,user_id,object_id,kind FROM ' .
                            Reader_Experience::table() .
                            ' WHERE event_key=%s',
                        $key,
                    ),
                    ARRAY_A,
                );
                if (
                    $wpdb->last_error !== '' ||
                    !is_array($recorded) ||
                    (int) $recorded['id'] < 1 ||
                    (int) $recorded['user_id'] !== $uid ||
                    (int) $recorded['object_id'] !== $object_id ||
                    $recorded['kind'] !== 'provider_reward'
                ) {
                    throw new RuntimeException('Provider receipt unavailable');
                }
                return ['event_id' => (int) $recorded['id'], 'duplicate' => false];
            });
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('活动未能记录，请重试。'), [
                'status' => 503,
            ]);
        }
    }
}
function wp_xp_core_register_activity_provider(string $id, array $definition): bool
{
    return WP_XP_Core_Providers::register($id, $definition);
}
function wp_xp_core_record_activity(
    string $provider,
    string $event,
    int $user_id,
    int $object_id = 0,
    array $context = [],
) {
    return WP_XP_Core_Providers::record($provider, $event, $user_id, $object_id, $context);
}

function wp_xp_core_revoke_activity(
    string $provider,
    string $event,
    int $user_id,
    int $object_id = 0,
    array $context = [],
) {
    return WP_XP_Core_Providers::revoke($provider, $event, $user_id, $object_id, $context);
}
