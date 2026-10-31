<?php
/** Plugin Name: WP XP Core
 * Description: Independent experience and levels with configurable activity rewards, private growth and achievements.
 * Plugin URI: https://github.com/wzf2000/wp-xp-core
 * Author: wzf2000
 * Author URI: https://github.com/wzf2000
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Update URI: https://github.com/wzf2000/wp-xp-core
 * Text Domain: wp-xp-core
 * Domain Path: /languages
 * Version: 2.1.0
 */
defined('ABSPATH') || exit();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/management.php';
require_once __DIR__ . '/management-ui.php';
require_once __DIR__ . '/growth.php';
require_once __DIR__ . '/growth-ui.php';
require_once __DIR__ . '/achievements.php';
require_once __DIR__ . '/achievements-ui.php';
require_once __DIR__ . '/fairness.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/insights.php';
require_once __DIR__ . '/insights-ui.php';
require_once __DIR__ . '/configuration-tools.php';
require_once __DIR__ . '/configuration-ui.php';
require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/activity-notifications.php';
require_once __DIR__ . '/activities-ui.php';
require_once __DIR__ . '/game-activities.php';
require_once __DIR__ . '/reward-controls.php';
require_once __DIR__ . '/reward-inspector.php';
require_once __DIR__ . '/widgets.php';
require_once __DIR__ . '/notification-delivery.php';
require_once __DIR__ . '/notification-sources.php';
require_once __DIR__ . '/bbpress.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/modules/blocks.php';
register_activation_hook(__FILE__, [WP_XP_Core_Lifecycle::class, 'activate']);
final class Reader_Experience
{
    const VERSION = '2.1.0';
    const BALANCE_META = 'reader_experience_balance';
    const MINS = [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000];
    private static bool $writing = false;
    private static array $touched_users = [];
    private static array $touched_options = [];
    private static array $pending_events = [];
    public static function ready(): bool
    {
        global $wpdb;
        return reader_experience_configured() &&
            (!reader_experience_native() || WP_XP_Core_Lifecycle::installed()) &&
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                    reader_experience_config('live_option'),
                ),
            ) === '1';
    }
    public static function balance_meta(): string
    {
        return reader_experience_native() && function_exists('is_multisite') && is_multisite()
            ? $GLOBALS['wpdb']->prefix . self::BALANCE_META
            : self::BALANCE_META;
    }
    public static function table(): string
    {
        return $GLOBALS['wpdb']->prefix . reader_experience_config('table_suffix');
    }
    public static function day(): string
    {
        return wp_date('Y-m-d', null, new DateTimeZone(WP_XP_Core_Settings::rules()['timezone']));
    }
    public static function q(string $sql)
    {
        global $wpdb;
        $v = $wpdb->query($sql);
        if ($v === false) {
            throw new RuntimeException('Experience storage failed');
        }
        return $v;
    }
    public static function install(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            throw new RuntimeException('CLI only');
        }
        if (reader_experience_native()) {
            throw new RuntimeException('Activate WP XP Core to install native storage.');
        }
        global $wpdb;
        self::q(
            'CREATE TABLE IF NOT EXISTS ' .
                self::table() .
                ' (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,event_key varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id bigint unsigned NOT NULL,kind varchar(32) CHARACTER SET ascii NOT NULL,object_id bigint unsigned NOT NULL DEFAULT 0,local_day date NOT NULL,xp bigint NOT NULL,created_at bigint NOT NULL,detail text NULL,KEY user_day(user_id,local_day,kind),KEY object_kind(object_id,kind)) ENGINE=InnoDB ' .
                $wpdb->get_charset_collate(),
        );
        foreach ([self::table(), $wpdb->usermeta, $wpdb->postmeta] as $t) {
            if (
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                        $t,
                    ),
                ) !== 'InnoDB'
            ) {
                throw new RuntimeException('Transactional tables required');
            }
        }
    }
    public static function transaction_active(): bool
    {
        return self::$writing;
    }
    public static function touch_user(int $uid): void
    {
        if (!self::$writing) {
            throw new RuntimeException('Experience transaction required');
        }
        self::$touched_users[$uid] = $uid;
    }
    public static function touch_option(string $option): void
    {
        if (!self::$writing) {
            throw new RuntimeException('Experience transaction required');
        }
        self::$touched_options[$option] = true;
    }
    public static function transaction(callable $fn)
    {
        global $wpdb;
        if (self::$writing) {
            throw new RuntimeException('Nested experience transactions are not allowed');
        }
        if (
            (int) $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s,10)', reader_experience_lock('event_lock')),
            ) !== 1
        ) {
            throw new RuntimeException('Experience busy');
        }
        self::$touched_users = self::$touched_options = self::$pending_events = [];
        self::$writing = true;
        $events = [];
        try {
            self::q('START TRANSACTION');
            WP_XP_Core_Settings::begin();
            $result = $fn();
            self::q('COMMIT');
            $events = self::$pending_events;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            foreach (self::$touched_users as $uid) {
                wp_cache_delete($uid, 'user_meta');
            }
            foreach (array_keys(self::$touched_options) as $option) {
                wp_cache_delete($option, 'options');
            }
            WP_XP_Core_Management::clear_option_cache();
            wp_cache_flush_runtime();
            throw $e;
        } finally {
            WP_XP_Core_Settings::end();
            self::$writing = false;
            self::$pending_events = [];
            $wpdb->get_var(
                $wpdb->prepare('SELECT RELEASE_LOCK(%s)', reader_experience_lock('event_lock')),
            );
        }
        // Plugins may start a new transaction from here. Never report a committed award as failed
        // because an observer throws, and continue dispatching subsequent committed events.
        foreach ($events as $event) {
            self::committed_hook('wp_xp_core_event_committed', $event);
            if ($event['before_level'] !== $event['after_level']) {
                self::committed_hook('wp_xp_core_level_changed', $event);
            }
        }
        return $result;
    }
    private static function committed_hook(string $hook, array $event): void
    {
        try {
            do_action($hook, $event);
        } catch (Throwable $e) {
            error_log('reader_experience committed observer failed: ' . $hook);
        }
    }
    public static function guard($check, $uid, $key)
    {
        return $key === self::balance_meta() && !self::$writing ? false : $check;
    }
    public static function set_balance(int $uid, int $value): void
    {
        if (!self::$writing) {
            throw new RuntimeException('Experience transaction required');
        }
        self::$touched_users[$uid] = $uid;
        wp_cache_delete($uid, 'user_meta');
        if ($value < 0) {
            throw new RuntimeException('Negative experience balance');
        }
        $existing = metadata_exists('user', $uid, self::balance_meta());
        $previous = get_user_meta($uid, self::balance_meta(), true);
        $saved = update_user_meta($uid, self::balance_meta(), $value);
        // WordPress returns false for both a failed write and an unchanged existing value.
        if ($saved === false && (!$existing || (string) $previous !== (string) $value)) {
            throw new RuntimeException('Experience write failed');
        }
        wp_cache_delete($uid, 'user_meta');
        if (
            !metadata_exists('user', $uid, self::balance_meta()) ||
            (string) get_user_meta($uid, self::balance_meta(), true) !== (string) $value
        ) {
            throw new RuntimeException('Experience write failed');
        }
    }
    public static function level(int $experience): int
    {
        $level = 1;
        foreach (WP_XP_Core_Settings::rules()['levels'] as $i => $min) {
            if ($experience >= $min) {
                $level = $i + 1;
            }
        }
        return $level;
    }
    private static function scalar(string $sql)
    {
        global $wpdb;
        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Experience read failed');
        }
        return $value;
    }
    public static function exists(string $key): bool
    {
        global $wpdb;
        return (bool) self::scalar(
            $wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE event_key=%s', $key),
        );
    }
    public static function count_day(int $uid, string $kind, string $day): int
    {
        global $wpdb;
        return (int) self::scalar(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' .
                    self::table() .
                    ' WHERE user_id=%d AND kind=%s AND local_day=%s',
                $uid,
                $kind,
                $day,
            ),
        );
    }
    public static function record(
        string $key,
        int $uid,
        string $kind,
        int $obj,
        int $xp,
        string $day,
        array $detail = [],
    ): bool {
        global $wpdb;
        if (!self::$writing) {
            throw new RuntimeException('Transaction required');
        }
        if (self::exists($key)) {
            return false;
        }
        if (!get_userdata($uid)) {
            return false;
        }
        wp_cache_delete($uid, 'user_meta');
        $xp = WP_XP_Core_Reward_Controls::apply($uid, $kind, $xp, $detail);
        $xp = WP_XP_Core_Fairness::cap($uid, $kind, $xp, $day, $detail);
        $before = (int) get_user_meta($uid, self::balance_meta(), true);
        if (
            $before < 0 ||
            ($xp > 0 && $before > PHP_INT_MAX - $xp) ||
            ($xp < 0 && ($xp === PHP_INT_MIN || $before < -$xp))
        ) {
            throw new RuntimeException('Experience balance overflow');
        }
        WP_XP_Core_Activity_Notifications::snapshot($kind, $xp, $detail);
        $detail['rule_revision'] = WP_XP_Core_Settings::policy_revision();
        $detail['notification_schema'] = 1;
        $detail['level_transition'] = [
            'before' => self::level($before),
            'after' => self::level(max(0, $before + $xp)),
        ];
        self::q(
            $wpdb->prepare(
                'INSERT INTO ' .
                    self::table() .
                    '(event_key,user_id,kind,object_id,local_day,xp,created_at,detail) VALUES(%s,%d,%s,%d,%s,%d,%d,%s)',
                $key,
                $uid,
                $kind,
                $obj,
                $day,
                $xp,
                time(),
                wp_json_encode($detail),
            ),
        );
        if ($xp !== 0) {
            wp_cache_delete($uid, 'user_meta');
            self::set_balance($uid, (int) get_user_meta($uid, self::balance_meta(), true) + $xp);
        }
        $event = [
            'id' => (int) $wpdb->get_var(
                $wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE event_key=%s', $key),
            ),
            'key' => $key,
            'user_id' => $uid,
            'kind' => $kind,
            'object_id' => $obj,
            'xp' => $xp,
            'day' => $day,
            'detail' => $detail,
            'before_xp' => $before,
            'after_xp' => max(0, $before + $xp),
            'before_level' => self::level($before),
            'after_level' => self::level(max(0, $before + $xp)),
        ];
        if ($wpdb->last_error !== '' || $event['id'] < 1) {
            throw new RuntimeException('Experience event receipt unavailable');
        }
        self::$pending_events[] = $event;
        WP_XP_Core_Growth::apply($event);
        return true;
    }
    public static function available_post(int $id): bool
    {
        if ($id === 0) {
            return true;
        }
        $p = get_post($id);
        return $p &&
            in_array($p->post_type, ['post', 'page'], true) &&
            !post_password_required($p) &&
            ($p->post_status === 'publish' || current_user_can('read_post', $id));
    }
    public static function state(int $uid): array
    {
        global $wpdb;
        wp_cache_delete($uid, 'user_meta');
        $xp = (int) get_user_meta($uid, self::balance_meta(), true);
        $level = self::level($xp);
        $history = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT kind,xp,local_day FROM ' .
                    self::table() .
                    ' WHERE user_id=%d AND xp<>0 ORDER BY id DESC LIMIT 12',
                $uid,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '' || !is_array($history)) {
            throw new RuntimeException('Experience history unavailable');
        }
        return [
            'experience' => $xp,
            'level' => $level,
            'next' => WP_XP_Core_Settings::rules()['levels'][$level] ?? null,
            'checkin_xp' => WP_XP_Core_Settings::rules()['checkin_xp'],
            'activities' => WP_XP_Core_Settings::rules()['activities'],
            'dwell_seconds' => WP_XP_Core_Settings::rules()['dwell_seconds'],
            'timezone' => WP_XP_Core_Settings::rules()['timezone'],
            'rule_revision' => WP_XP_Core_Settings::policy_revision(),
            'checked_in' => self::exists('checkin:' . $uid . ':' . self::day()),
            'visit_today' => self::count_day($uid, 'visit', self::day()),
            'comment_today' => self::count_day($uid, 'comment', self::day()),
            'history' => $history,
        ];
    }
    public static function permission($r)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return new WP_Error('login_required', wp_xp_core_t('请先登录。'), ['status' => 401]);
        }
        if (!wp_verify_nonce($r->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('nonce', wp_xp_core_t('登录验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        if (!self::ready()) {
            return new WP_Error('maintenance', wp_xp_core_t('经验系统维护中，请稍后重试。'), [
                'status' => 503,
            ]);
        }
        return true;
    }
    public static function routes(): void
    {
        foreach (
            array_unique(
                array_merge(
                    [reader_experience_config('rest_namespace')],
                    reader_experience_config('rest_aliases'),
                ),
            )
            as $namespace
        ) {
            foreach (['state', 'ticket', 'checkin', 'visit', 'like'] as $action) {
                register_rest_route($namespace, '/' . $action, [
                    'methods' => 'POST',
                    'permission_callback' => [self::class, 'permission'],
                    'callback' => fn($r) => self::request($r, $action),
                ]);
            }
        }
    }
    public static function request($r, string $action)
    {
        try {
            $v = $r->get_json_params();
            $keys = match ($action) {
                'state', 'checkin' => [],
                'ticket', 'like' => ['post_id'],
                'visit' => ['post_id', 'issued', 'signature'],
            };
            if (
                !$r->is_json_content_type() ||
                strlen($r->get_body()) > 2000 ||
                !is_array($v) ||
                array_diff(array_keys($v), $keys) ||
                array_diff($keys, array_keys($v))
            ) {
                return new WP_Error('input', wp_xp_core_t('请求参数无效。'), ['status' => 400]);
            }
            $uid = get_current_user_id();
            $pid = (int) ($v['post_id'] ?? 0);
            if (
                in_array($action, ['ticket', 'visit', 'like'], true) &&
                (!is_int($v['post_id']) ||
                    !self::available_post($pid) ||
                    !WP_XP_Core_Settings::in_scope($pid))
            ) {
                return new WP_Error('missing', wp_xp_core_t('页面不可用。'), ['status' => 404]);
            }
            if ($action === 'state') {
                return self::state($uid);
            }
            if ($action === 'ticket') {
                $now = time();
                return [
                    'issued' => $now,
                    'dwell_seconds' => WP_XP_Core_Settings::rules()['dwell_seconds'],
                    'signature' => hash_hmac('sha256', "$uid:$pid:$now", wp_salt('nonce')),
                ];
            }
            if (
                $action === 'visit' &&
                (!is_int($v['issued']) ||
                    !is_string($v['signature']) ||
                    time() - $v['issued'] < WP_XP_Core_Settings::rules()['dwell_seconds'] ||
                    time() - $v['issued'] >
                        max(3600, WP_XP_Core_Settings::rules()['dwell_seconds'] + 300) ||
                    !hash_equals(
                        hash_hmac('sha256', "$uid:$pid:" . $v['issued'], wp_salt('nonce')),
                        $v['signature'],
                    ))
            ) {
                return new WP_Error('dwell', wp_xp_core_t('请在页面停留后再记录访问。'), [
                    'status' => 400,
                ]);
            }
            if ($action === 'like') {
                if (!function_exists('pagenest_companion_like')) {
                    return new WP_Error('like_unavailable', wp_xp_core_t('点赞服务不可用。'), [
                        'status' => 503,
                    ]);
                }
                $liked = pagenest_companion_like($uid, $pid);
                if (is_wp_error($liked)) {
                    return $liked;
                }
                $out = self::state($uid);
                $out['likes'] = $liked['count'];
                return $out;
            }
            return self::transaction(function () use ($action, $uid, $pid, $v) {
                if (!self::ready()) {
                    throw new RuntimeException('Maintenance');
                }
                $day = self::day();
                $rules = WP_XP_Core_Settings::rules();
                if (
                    $action === 'visit' &&
                    (!self::available_post($pid) || !WP_XP_Core_Settings::in_scope($pid))
                ) {
                    return new WP_Error('missing', wp_xp_core_t('页面不可用。'), ['status' => 404]);
                }
                if ($action === 'visit' && time() - $v['issued'] < $rules['dwell_seconds']) {
                    return new WP_Error('dwell', wp_xp_core_t('请在页面停留后再记录访问。'), [
                        'status' => 400,
                    ]);
                }
                if ($action === 'checkin' && WP_XP_Core_Settings::enabled('checkin')) {
                    self::record(
                        "checkin:$uid:$day",
                        $uid,
                        'checkin',
                        0,
                        $rules['checkin_xp'],
                        $day,
                    );
                }
                if ($action === 'visit') {
                    if (
                        WP_XP_Core_Settings::enabled('visit') &&
                        self::count_day($uid, 'visit', $day) < $rules['visit_limit']
                    ) {
                        self::record(
                            "visit:$uid:$day:$pid",
                            $uid,
                            'visit',
                            $pid,
                            $rules['visit_xp'],
                            $day,
                        );
                    }
                    $p = get_post($pid);
                    if (
                        WP_XP_Core_Settings::enabled('view') &&
                        $p &&
                        $p->post_type === 'post' &&
                        $p->post_status === 'publish' &&
                        (int) $p->post_author !== $uid
                    ) {
                        if (self::record("view:$uid:$day:$pid", $uid, 'view', $pid, 0, $day)) {
                            self::milestones($pid, 'view');
                        }
                    }
                }
                return self::state($uid);
            });
        } catch (Throwable $e) {
            error_log('reader_experience: ' . $e->getMessage());
            return new WP_Error('unavailable', wp_xp_core_t('暂时无法记录，请稍后重试。'), [
                'status' => 503,
            ]);
        }
    }
    public static function liked(int $uid, int $pid, int $count): void
    {
        if (
            !self::ready() ||
            !WP_XP_Core_Settings::likes_ready() ||
            !WP_XP_Core_Settings::enabled('like') ||
            !WP_XP_Core_Settings::in_scope($pid)
        ) {
            return;
        }
        try {
            self::transaction(function () use ($uid, $pid) {
                if (
                    !self::ready() ||
                    !WP_XP_Core_Settings::enabled('like') ||
                    !WP_XP_Core_Settings::in_scope($pid)
                ) {
                    return;
                }
                if (self::record("like:$uid:$pid", $uid, 'like', $pid, 0, self::day())) {
                    self::milestones($pid, 'like');
                }
            });
        } catch (Throwable $e) {
            error_log('reader_experience like event failed: ' . $pid);
        }
    }
    public static function milestones(int $pid, string $kind): void
    {
        global $wpdb;
        if (
            !isset(WP_XP_Core_Settings::rules()['milestones'][$kind]) ||
            !WP_XP_Core_Settings::enabled($kind) ||
            !WP_XP_Core_Settings::in_scope($pid) ||
            ($kind === 'like' && !WP_XP_Core_Settings::likes_ready())
        ) {
            return;
        }
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'post' || $p->post_status !== 'publish') {
            return;
        }
        $n = (int) self::scalar(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE object_id=%d AND kind=%s',
                $pid,
                $kind,
            ),
        );
        foreach (WP_XP_Core_Settings::rules()['milestones'][$kind] as $row) {
            if ($n >= $row['threshold']) {
                $id = $row['id'];
                self::record(
                    "milestone:$kind:$pid:$id",
                    (int) $p->post_author,
                    'milestone_' . $kind,
                    $pid,
                    $row['xp'],
                    self::day(),
                    ['threshold' => $row['threshold'], 'milestone_id' => $id],
                );
            }
        }
    }
    public static function published($new, $old, $p): void
    {
        if (
            !self::ready() ||
            $new !== 'publish' ||
            $old === 'publish' ||
            $p->post_type !== 'post' ||
            !WP_XP_Core_Settings::enabled('article') ||
            !WP_XP_Core_Settings::in_scope((int) $p->ID)
        ) {
            return;
        }
        $attempt = null;
        try {
            self::transaction(function () use ($p, &$attempt) {
                if (
                    !WP_XP_Core_Settings::enabled('article') ||
                    !WP_XP_Core_Settings::in_scope((int) $p->ID)
                ) {
                    return false;
                }
                $attempt = [
                    'event_key' => 'article:' . $p->ID,
                    'kind' => 'article',
                    'object_id' => (int) $p->ID,
                    'user_id' => (int) $p->post_author,
                    'xp' => WP_XP_Core_Settings::rules()['article_xp'],
                    'local_day' => self::day(),
                ];
                return self::record(
                    'article:' . $p->ID,
                    (int) $p->post_author,
                    'article',
                    (int) $p->ID,
                    $attempt['xp'],
                    $attempt['local_day'],
                );
            });
        } catch (Throwable $e) {
            error_log('reader_experience publish failed: ' . $p->ID);
            if ($attempt !== null) {
                WP_XP_Core_Management::capture_failure($attempt);
            }
        }
    }
    public static function comment(int $id): void
    {
        if (!self::ready()) {
            return;
        }
        $c = get_comment($id);
        if (!$c) {
            return;
        }
        $attempt = null;
        try {
            self::transaction(function () use ($id, $c, &$attempt) {
                global $wpdb;
                $p = get_post($c->comment_post_ID);
                $uid = (int) $c->user_id;
                $parent = $c->comment_parent ? get_comment($c->comment_parent) : null;
                $eligible =
                    $uid > 0 &&
                    $c->comment_approved === '1' &&
                    in_array($c->comment_type, ['', 'comment'], true) &&
                    $p &&
                    $p->post_status === 'publish' &&
                    $p->post_password === '' &&
                    in_array($p->post_type, ['post', 'page'], true) &&
                    (int) $p->post_author !== $uid &&
                    (!$parent || (int) $parent->user_id !== $uid);
                $first = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM ' . self::table() . ' WHERE event_key=%s',
                        'comment:' . $id,
                    ),
                    ARRAY_A,
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Comment reward receipt unavailable');
                }
                if (!$first) {
                    if (
                        $eligible &&
                        WP_XP_Core_Settings::enabled('comment') &&
                        WP_XP_Core_Settings::in_scope((int) $c->comment_post_ID) &&
                        self::count_day($uid, 'comment', self::day()) <
                            WP_XP_Core_Settings::rules()['comment_limit']
                    ) {
                        $attempt = [
                            'event_key' => 'comment:' . $id,
                            'kind' => 'comment',
                            'object_id' => $id,
                            'user_id' => $uid,
                            'xp' => WP_XP_Core_Settings::rules()['comment_xp'],
                            'local_day' => self::day(),
                        ];
                        self::record(
                            'comment:' . $id,
                            $uid,
                            'comment',
                            $id,
                            $attempt['xp'],
                            $attempt['local_day'],
                        );
                    }
                    return;
                }
                if ($first['kind'] === 'comment_block') {
                    return;
                }
                $result = self::reconcile_comment($id, false, [], $attempt);
                if (is_wp_error($result)) {
                    throw new RuntimeException('Comment compensation unavailable');
                }
            });
        } catch (Throwable $e) {
            error_log('reader_experience comment failed: ' . $id);
            if ($attempt !== null) {
                WP_XP_Core_Management::capture_failure($attempt);
            }
        }
    }
    /** Recompute an existing comment's full compensation under the ledger lock. */
    public static function reconcile_comment(
        int $id,
        bool $deleted = false,
        array $evidence = [],
        ?array &$attempt = null,
    ) {
        if (!self::transaction_active()) {
            throw new RuntimeException('Comment reconciliation requires the ledger lock');
        }
        global $wpdb;
        $first = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE event_key=%s',
                'comment:' . $id,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Original comment event unavailable');
        }
        if (!$first || $first['kind'] !== 'comment') {
            return new WP_Error('missing', wp_xp_core_t('未找到原评论奖励。'), ['status' => 404]);
        }
        $uid = (int) $first['user_id'];
        wp_cache_delete($id, 'comment');
        $c = $deleted ? null : get_comment($id);
        $p = $c ? get_post($c->comment_post_ID) : null;
        $parent = $c && $c->comment_parent ? get_comment($c->comment_parent) : null;
        $eligible =
            $c &&
            (int) $c->user_id === $uid &&
            $c->comment_approved === '1' &&
            in_array($c->comment_type, ['', 'comment'], true) &&
            $p &&
            $p->post_status === 'publish' &&
            $p->post_password === '' &&
            in_array($p->post_type, ['post', 'page'], true) &&
            (int) $p->post_author !== $uid &&
            (!$parent || (int) $parent->user_id !== $uid);
        $net = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(xp),0) FROM ' .
                    self::table() .
                    " WHERE object_id=%d AND user_id=%d AND kind IN ('comment','comment_adjust')",
                $id,
                $uid,
            ),
        );
        if ($wpdb->last_error !== '' || $net === null) {
            throw new RuntimeException('Comment subtotal unavailable');
        }
        $net = (int) $net;
        if ($net < 0 || $net > (int) $first['xp']) {
            return new WP_Error('inconsistent', wp_xp_core_t('评论奖励净额异常，请先检查账本。'), [
                'status' => 409,
            ]);
        }
        $delta = ($eligible ? (int) $first['xp'] : 0) - $net;
        if (!$delta) {
            return ['event_id' => (int) $first['id'], 'user_id' => $uid, 'delta' => 0];
        }
        $seq = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' .
                    self::table() .
                    " WHERE object_id=%d AND kind='comment_adjust'",
                $id,
            ),
        );
        if ($wpdb->last_error !== '' || $seq === null) {
            throw new RuntimeException('Comment adjustment sequence unavailable');
        }
        $key = 'comment-adjust:' . $id . ':' . (int) $seq;
        $attempt = [
            'event_key' => $key,
            'kind' => 'comment_adjust',
            'object_id' => $id,
            'user_id' => $uid,
            'xp' => $delta,
            'local_day' => self::day(),
        ];
        $allowed = WP_XP_Core_Management::balance_allows($uid, $delta);
        if ($allowed !== true) {
            $attempt['failure_reason'] = $allowed->get_error_code();
            return $allowed;
        }
        if (
            !self::record(
                $key,
                $uid,
                'comment_adjust',
                $id,
                $delta,
                self::day(),
                ['original' => (int) $first['id'], 'deleted' => !$c] + $evidence,
            )
        ) {
            throw new RuntimeException('Comment adjustment failed');
        }
        $event_id = $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE event_key=%s', $key),
        );
        if ($wpdb->last_error !== '' || !$event_id) {
            throw new RuntimeException('Comment adjustment receipt unavailable');
        }
        return ['event_id' => (int) $event_id, 'user_id' => $uid, 'delta' => $delta];
    }
    public static function deleting_comment(int $id): void
    {
        if (!self::ready()) {
            return;
        }
        $attempt = null;
        try {
            self::transaction(function () use ($id, &$attempt) {
                $result = self::reconcile_comment($id, true, [], $attempt);
                if (is_wp_error($result) && $result->get_error_code() !== 'missing') {
                    throw new RuntimeException('Comment removal compensation unavailable');
                }
            });
        } catch (Throwable $e) {
            error_log('reader_experience comment removal failed');
            if ($attempt !== null) {
                WP_XP_Core_Management::capture_failure($attempt);
            }
        }
    }
    public static function legacy(): void
    {
        if (!reader_experience_external() || !self::ready()) {
            return;
        }
        remove_all_actions('wp_ajax_bigfa_like');
        remove_all_actions('wp_ajax_nopriv_bigfa_like');
        foreach (['wp_ajax_bigfa_like', 'wp_ajax_nopriv_bigfa_like'] as $name) {
            add_action($name, function () {
                wp_send_json_error(['message' => wp_xp_core_t('请刷新页面并登录后点赞。')], 410);
            });
        }
        // Preserve the existing weekly event; replace payout callbacks with score-only rotation.
        remove_all_actions(reader_experience_config('weekly_hook'));
        add_action(reader_experience_config('weekly_hook'), [self::class, 'weekly']);
    }
    public static function weekly(): void
    {
        if (!reader_experience_external() || !self::ready()) {
            return;
        }
        global $wpdb;
        if (
            (int) $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s,10)', reader_experience_lock('weekly_lock')),
            ) !== 1
        ) {
            error_log('reader_experience weekly busy');
            return;
        }
        try {
            $week = wp_date('o-W', null, new DateTimeZone('Asia/Shanghai'));
            $users = self::transaction(function () use ($week) {
                global $wpdb;
                if (get_option(reader_experience_config('week_option')) === $week) {
                    return [];
                }
                $users = $wpdb->get_col(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('max_2048_score_weekly','max_2048_fib_score_weekly')",
                );
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Weekly score lookup failed');
                }
                self::q(
                    "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('max_2048_score_weekly','max_2048_fib_score_weekly')",
                );
                if (!update_option(reader_experience_config('week_option'), $week, false)) {
                    throw new RuntimeException('Weekly marker write failed');
                }
                return $users;
            });
            foreach ($users as $uid) {
                wp_cache_delete((int) $uid, 'user_meta');
            }
        } finally {
            $wpdb->get_var(
                $wpdb->prepare('SELECT RELEASE_LOCK(%s)', reader_experience_lock('weekly_lock')),
            );
        }
    }
    public static function assets(): void
    {
        if (!self::ready() || is_admin() || is_feed()) {
            return;
        }
        $dir = __DIR__ . '/assets/';
        $manifest = json_decode(file_get_contents($dir . 'assets.json'), true);
        wp_enqueue_style(
            'reader_experience',
            plugins_url('assets/' . $manifest['css'], __FILE__),
            [],
            null,
        );
        wp_enqueue_script(
            'reader_experience',
            plugins_url('assets/' . $manifest['js'], __FILE__),
            [],
            null,
            true,
        );
        if (class_exists('WP_XP_Core_I18n')) {
            WP_XP_Core_I18n::script('reader_experience');
        }
        $id = is_singular()
            ? (int) get_queried_object_id()
            : (is_home() || is_front_page()
                ? 0
                : null);
        wp_add_inline_script(
            'reader_experience',
            'window.ReaderExperience=' .
                wp_json_encode([
                    'endpoint' => rest_url(reader_experience_config('rest_namespace') . '/'),
                    'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
                    'postId' => $id,
                    'login' => wp_login_url(reader_experience_panel_url()),
                    'logged' => is_user_logged_in(),
                ]) .
                ';',
            'before',
        );
    }
    public static function panel(): string
    {
        try {
            if (self::ready()) {
                return WP_XP_Core_Growth_UI::panel();
            }
        } catch (Throwable $e) {
            error_log('reader_experience panel read failed');
        }
        return '<p>' . esc_html(wp_xp_core_t('经验系统维护中，请稍后再试。')) . '</p>';
    }

    public static function admin_profile($user): void
    {
        if (self::ready()) {
            echo '<h2>' .
                esc_html(wp_xp_core_t('经验与等级')) .
                '</h2><p>' .
                esc_html(
                    wp_xp_core_t('经验由发表、签到、访问、评论和文章里程碑获得，不能购买或转让。'),
                ) .
                '<a href="' .
                esc_url(reader_experience_panel_url()) .
                '">' .
                esc_html(wp_xp_core_t('查看经验与签到')) .
                '</a></p>';
        }
    }
}
function reader_experience_account_panel($original)
{
    return Reader_Experience::ready() ? Reader_Experience::panel() : $original;
}
add_filter('site_tools_account_panel', 'reader_experience_account_panel');
add_filter('plugin_action_links_wp-xp-core/wp-xp-core.php', function ($links) {
    if (current_user_can('manage_options')) {
        array_unshift(
            $links,
            '<a href="' .
                esc_url(admin_url('options-general.php?page=wp-xp-core')) .
                '">' .
                esc_html(wp_xp_core_t('设置')) .
                '</a>',
        );
    }
    return $links;
});
function reader_experience_user_level($fallback, $uid)
{
    if (!Reader_Experience::ready() || !is_numeric($uid) || (int) $uid < 1) {
        return $fallback;
    }
    return 'Level ' .
        Reader_Experience::level(
            (int) get_user_meta((int) $uid, Reader_Experience::balance_meta(), true),
        );
}
add_filter('site_tools_user_level', 'reader_experience_user_level', 10, 2);
if (!reader_experience_configured()) {
    return;
}
foreach (['update_user_metadata', 'add_user_metadata', 'delete_user_metadata'] as $hook) {
    add_filter($hook, [Reader_Experience::class, 'guard'], PHP_INT_MAX, 3);
}
add_action('init', [Reader_Experience::class, 'legacy'], PHP_INT_MAX);
add_action('rest_api_init', [Reader_Experience::class, 'routes']);
add_action('transition_post_status', [Reader_Experience::class, 'published'], 20, 3);
add_action('wp_insert_comment', fn($id) => Reader_Experience::comment((int) $id), 20);
add_action(
    'transition_comment_status',
    fn($new, $old, $c) => Reader_Experience::comment((int) $c->comment_ID),
    20,
    3,
);
add_action('delete_comment', fn($id) => Reader_Experience::deleting_comment((int) $id), 20);
add_action('user_register', function ($uid) {
    if (!Reader_Experience::ready()) {
        return;
    }
    Reader_Experience::transaction(function () use ($uid) {
        if (Reader_Experience::ready()) {
            Reader_Experience::set_balance((int) $uid, 0);
        }
    });
});
add_action('wp_enqueue_scripts', [Reader_Experience::class, 'assets']);
foreach (reader_experience_config('shortcodes') as $shortcode) {
    add_shortcode($shortcode, fn() => Reader_Experience::panel());
}
add_action(
    'admin_bar_menu',
    function ($bar) {
        if (Reader_Experience::ready()) {
            $bar->add_node([
                'id' => 'reader_experience-checkin',
                'title' => wp_xp_core_t('经验签到'),
                'href' => reader_experience_panel_url(),
            ]);
        }
    },
    110,
);
add_action('show_user_profile', [Reader_Experience::class, 'admin_profile']);
add_action('edit_user_profile', [Reader_Experience::class, 'admin_profile']);
add_filter(
    'rest_post_dispatch',
    function ($response, $server, $request) {
        if (reader_experience_route_matches($request->get_route())) {
            $response->header('Cache-Control', 'private, no-store');
            $response->header('Vary', 'Cookie');
        }
        return $response;
    },
    10,
    3,
);

add_action('pagenest_like_recorded', [Reader_Experience::class, 'liked'], 10, 3);
add_action('send_headers', function () {
    if (is_user_logged_in()) {
        nocache_headers();
    }
});
