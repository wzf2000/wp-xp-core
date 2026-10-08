<?php
/** Plugin Name: Reader Experience
 * Description: Bounded experience events, ten levels and private activity history.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit();
require_once __DIR__ . '/config.php';
final class Reader_Experience
{
    const VERSION = '1.1.0';
    const BALANCE_META = 'reader_experience_balance';
    const MINS = [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000];
    private static bool $writing = false;
    public static function ready(): bool
    {
        global $wpdb;
        return reader_experience_configured() &&
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                    reader_experience_config('live_option'),
                ),
            ) === '1';
    }
    public static function table(): string
    {
        return $GLOBALS['wpdb']->prefix . reader_experience_config('table_suffix');
    }
    public static function day(): string
    {
        return wp_date('Y-m-d', null, new DateTimeZone('Asia/Shanghai'));
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
    public static function transaction(callable $fn)
    {
        global $wpdb;
        if (
            (int) $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s,10)', reader_experience_lock('event_lock')),
            ) !== 1
        ) {
            throw new RuntimeException('Experience busy');
        }
        $old = self::$writing;
        self::$writing = true;
        try {
            self::q('START TRANSACTION');
            $r = $fn();
            self::q('COMMIT');
            return $r;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_cache_flush_runtime();
            throw $e;
        } finally {
            self::$writing = $old;
            $wpdb->get_var(
                $wpdb->prepare('SELECT RELEASE_LOCK(%s)', reader_experience_lock('event_lock')),
            );
        }
    }
    public static function guard($check, $uid, $key)
    {
        return $key === self::BALANCE_META && !self::$writing ? false : $check;
    }
    public static function set_balance(int $uid, int $value): void
    {
        if (!self::$writing) {
            throw new RuntimeException('Experience transaction required');
        }
        wp_cache_delete($uid, 'user_meta');
        $value = max(0, $value);
        $existing = metadata_exists('user', $uid, self::BALANCE_META);
        $previous = get_user_meta($uid, self::BALANCE_META, true);
        $saved = update_user_meta($uid, self::BALANCE_META, $value);
        // WordPress returns false for both a failed write and an unchanged existing value.
        if ($saved === false && (!$existing || (string) $previous !== (string) $value)) {
            throw new RuntimeException('Experience write failed');
        }
        wp_cache_delete($uid, 'user_meta');
        if (
            !metadata_exists('user', $uid, self::BALANCE_META) ||
            (string) get_user_meta($uid, self::BALANCE_META, true) !== (string) $value
        ) {
            throw new RuntimeException('Experience write failed');
        }
    }
    public static function level(int $experience): int
    {
        $level = 1;
        foreach (self::MINS as $i => $min) {
            if ($experience >= $min) {
                $level = $i + 1;
            }
        }
        return $level;
    }
    public static function exists(string $key): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE event_key=%s', $key),
        );
    }
    public static function count_day(int $uid, string $kind, string $day): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
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
            self::set_balance($uid, (int) get_user_meta($uid, self::BALANCE_META, true) + $xp);
        }
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
        $xp = (int) get_user_meta($uid, self::BALANCE_META, true);
        $level = self::level($xp);
        return [
            'experience' => $xp,
            'level' => $level,
            'next' => $level < 10 ? self::MINS[$level] : null,
            'checked_in' => self::exists('checkin:' . $uid . ':' . self::day()),
            'visit_today' => self::count_day($uid, 'visit', self::day()),
            'comment_today' => self::count_day($uid, 'comment', self::day()),
            'history' => $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT kind,xp,local_day FROM ' .
                        self::table() .
                        ' WHERE user_id=%d AND xp<>0 ORDER BY id DESC LIMIT 12',
                    $uid,
                ),
                ARRAY_A,
            ),
        ];
    }
    public static function permission($r)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return new WP_Error('login_required', '请先登录。', ['status' => 401]);
        }
        if (!wp_verify_nonce($r->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('nonce', '登录验证已过期，请刷新页面。', ['status' => 403]);
        }
        if (!self::ready()) {
            return new WP_Error('maintenance', '经验系统维护中，请稍后重试。', ['status' => 503]);
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
                return new WP_Error('input', '请求参数无效。', ['status' => 400]);
            }
            $uid = get_current_user_id();
            $pid = (int) ($v['post_id'] ?? 0);
            if (
                in_array($action, ['ticket', 'visit', 'like'], true) &&
                (!is_int($v['post_id']) || !self::available_post($pid))
            ) {
                return new WP_Error('missing', '页面不可用。', ['status' => 404]);
            }
            if ($action === 'state') {
                return self::state($uid);
            }
            if ($action === 'ticket') {
                $now = time();
                return [
                    'issued' => $now,
                    'signature' => hash_hmac('sha256', "$uid:$pid:$now", wp_salt('nonce')),
                ];
            }
            if (
                $action === 'visit' &&
                (!is_int($v['issued']) ||
                    !is_string($v['signature']) ||
                    time() - $v['issued'] < 15 ||
                    time() - $v['issued'] > 3600 ||
                    !hash_equals(
                        hash_hmac('sha256', "$uid:$pid:" . $v['issued'], wp_salt('nonce')),
                        $v['signature'],
                    ))
            ) {
                return new WP_Error('dwell', '请在页面停留后再记录访问。', ['status' => 400]);
            }
            if ($action === 'like') {
                if (!function_exists('pagenest_companion_like')) {
                    return new WP_Error('like_unavailable', '点赞服务不可用。', ['status' => 503]);
                }
                $liked = pagenest_companion_like($uid, $pid);
                if (is_wp_error($liked)) {
                    return $liked;
                }
                $out = self::state($uid);
                $out['likes'] = $liked['count'];
                return $out;
            }
            return self::transaction(function () use ($action, $uid, $pid) {
                if (!self::ready()) {
                    throw new RuntimeException('Maintenance');
                }
                $day = self::day();
                if ($action === 'checkin') {
                    self::record("checkin:$uid:$day", $uid, 'checkin', 0, 2, $day);
                }
                if ($action === 'visit') {
                    if (self::count_day($uid, 'visit', $day) < 3) {
                        self::record("visit:$uid:$day:$pid", $uid, 'visit', $pid, 1, $day);
                    }
                    $p = get_post($pid);
                    if (
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
            return new WP_Error('unavailable', '暂时无法记录，请稍后重试。', ['status' => 503]);
        }
    }
    public static function liked(int $uid, int $pid, int $count): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            self::transaction(function () use ($uid, $pid) {
                if (!self::ready()) {
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
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'post' || $p->post_status !== 'publish') {
            return;
        }
        $n = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE object_id=%d AND kind=%s',
                $pid,
                $kind,
            ),
        );
        foreach (
            $kind === 'view' ? [[100, 5], [500, 10], [1000, 20]] : [[10, 5], [30, 10], [100, 20]]
            as [$threshold, $xp]
        ) {
            if ($n >= $threshold) {
                self::record(
                    "milestone:$kind:$pid:$threshold",
                    (int) $p->post_author,
                    'milestone_' . $kind,
                    $pid,
                    $xp,
                    self::day(),
                    ['threshold' => $threshold],
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
            $p->post_type !== 'post'
        ) {
            return;
        }
        try {
            self::transaction(
                fn() => self::record(
                    'article:' . $p->ID,
                    (int) $p->post_author,
                    'article',
                    (int) $p->ID,
                    20,
                    self::day(),
                ),
            );
        } catch (Throwable $e) {
            error_log('reader_experience publish failed: ' . $p->ID);
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
        try {
            self::transaction(function () use ($id, $c) {
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
                if (!$first) {
                    if ($eligible && self::count_day($uid, 'comment', self::day()) < 3) {
                        self::record('comment:' . $id, $uid, 'comment', $id, 2, self::day());
                    }
                    return;
                }
                if ($first['kind'] === 'comment_block') {
                    return;
                }
                $uid = (int) $first['user_id'];
                $eligible = $eligible && (int) $c->user_id === $uid;
                $net = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COALESCE(SUM(xp),0) FROM ' .
                            self::table() .
                            " WHERE object_id=%d AND user_id=%d AND kind IN ('comment','comment_adjust')",
                        $id,
                        $uid,
                    ),
                );
                $target = $eligible ? 2 : 0;
                if ($net !== $target) {
                    $seq = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            'SELECT COUNT(*) FROM ' .
                                self::table() .
                                " WHERE object_id=%d AND kind='comment_adjust'",
                            $id,
                        ),
                    );
                    self::record(
                        "comment-adjust:$id:$seq",
                        $uid,
                        'comment_adjust',
                        $id,
                        $target - $net,
                        self::day(),
                        ['original' => $first['id']],
                    );
                }
            });
        } catch (Throwable $e) {
            error_log('reader_experience comment failed: ' . $id);
        }
    }
    public static function deleting_comment(int $id): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            self::transaction(function () use ($id) {
                global $wpdb;
                $first = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM ' . self::table() . ' WHERE event_key=%s',
                        'comment:' . $id,
                    ),
                    ARRAY_A,
                );
                if (!$first) {
                    return;
                }
                $uid = (int) $first['user_id'];
                $net = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COALESCE(SUM(xp),0) FROM ' .
                            self::table() .
                            " WHERE object_id=%d AND user_id=%d AND kind IN ('comment','comment_adjust')",
                        $id,
                        $uid,
                    ),
                );
                if ($net) {
                    $seq = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            'SELECT COUNT(*) FROM ' .
                                self::table() .
                                " WHERE object_id=%d AND kind='comment_adjust'",
                            $id,
                        ),
                    );
                    self::record(
                        "comment-adjust:$id:$seq",
                        $uid,
                        'comment_adjust',
                        $id,
                        -$net,
                        self::day(),
                        ['deleted' => true],
                    );
                }
            });
        } catch (Throwable $e) {
            error_log('reader_experience comment removal failed');
        }
    }
    public static function legacy(): void
    {
        if (!self::ready()) {
            return;
        }
        remove_all_actions('wp_ajax_bigfa_like');
        remove_all_actions('wp_ajax_nopriv_bigfa_like');
        foreach (['wp_ajax_bigfa_like', 'wp_ajax_nopriv_bigfa_like'] as $name) {
            add_action($name, function () {
                wp_send_json_error(['message' => '请刷新页面并登录后点赞。'], 410);
            });
        }
        // Preserve the existing weekly event; replace payout callbacks with score-only rotation.
        remove_all_actions(reader_experience_config('weekly_hook'));
        add_action(reader_experience_config('weekly_hook'), [self::class, 'weekly']);
    }
    public static function weekly(): void
    {
        if (!self::ready()) {
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
        if (!self::ready()) {
            return '<p>经验系统维护中，请稍后再试。</p>';
        }
        $state = is_user_logged_in() ? self::state(get_current_user_id()) : null;
        $manifest = json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true);
        $text = $state
            ? '经验 ' .
                $state['experience'] .
                ' · Level ' .
                $state['level'] .
                ($state['next'] !== null
                    ? ' · 距下一级 ' . ($state['next'] - $state['experience'])
                    : ' · 已达最高等级')
            : '登录后签到、记录访问和查看经验。';
        return '<link rel="stylesheet" href="' .
            esc_url(plugins_url('assets/' . $manifest['css'], __FILE__)) .
            '"><section class="reader-experience-panel" aria-label="经验与签到"><h2>经验与等级</h2><p class="reader-experience-state">' .
            esc_html($text) .
            '</p>' .
            ($state
                ? '<button type="button" class="reader-experience-checkin"' .
                    ($state['checked_in'] ? ' disabled' : '') .
                    '>' .
                    ($state['checked_in'] ? '今日已签到' : '每日签到 +2') .
                    '</button><p class="reader-experience-message" role="status"></p><details><summary>最近经验记录</summary><ul class="reader-experience-history"></ul></details>'
                : '<a href="' .
                    esc_url(wp_login_url(reader_experience_panel_url())) .
                    '">登录</a>') .
            '</section>';
    }
    public static function admin_profile($user): void
    {
        if (self::ready()) {
            echo '<h2>经验与等级</h2><p>经验由发表、签到、访问、评论和文章里程碑获得，不能购买或转让。<a href="' .
                esc_url(reader_experience_panel_url()) .
                '">查看经验与签到</a></p>';
        }
    }
}
function reader_experience_account_panel($original)
{
    return Reader_Experience::ready() ? Reader_Experience::panel() : $original;
}
add_filter('site_tools_account_panel', 'reader_experience_account_panel');
function reader_experience_user_level($fallback, $uid)
{
    if (!Reader_Experience::ready() || !is_numeric($uid) || (int) $uid < 1) {
        return $fallback;
    }
    return 'Level ' .
        Reader_Experience::level(
            (int) get_user_meta((int) $uid, Reader_Experience::BALANCE_META, true),
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
                'title' => '经验签到',
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
