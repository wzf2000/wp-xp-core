<?php
/** Validated global policy. Settings never rewrite existing experience events. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Settings
{
    const OPTION = 'wp_xp_core_rules';
    private static ?array $snapshot = null;
    public static function begin(): void
    {
        self::$snapshot = self::rules();
    }
    public static function end(): void
    {
        self::$snapshot = null;
    }
    public static function defaults(): array
    {
        return [
            'checkin_xp' => 2,
            'visit_xp' => 1,
            'article_xp' => 20,
            'comment_xp' => 2,
            'visit_limit' => 3,
            'comment_limit' => 3,
            'view_100' => 5,
            'view_500' => 10,
            'view_1000' => 20,
            'like_10' => 5,
            'like_30' => 10,
            'like_100' => 20,
            'view_100_threshold' => 100,
            'view_500_threshold' => 500,
            'view_1000_threshold' => 1000,
            'like_10_threshold' => 10,
            'like_30_threshold' => 30,
            'like_100_threshold' => 100,
            'levels' => [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000],
        ];
    }
    public static function validate($input): array
    {
        $defaults = self::defaults();
        // Old complete policies gain thresholds in memory only; partial new policies are invalid.
        $legacy = array_filter(
            $defaults,
            fn($key) => !str_ends_with($key, '_threshold'),
            ARRAY_FILTER_USE_KEY,
        );
        if (
            is_array($input) &&
            !array_diff(array_keys($input), array_keys($legacy)) &&
            !array_diff(array_keys($legacy), array_keys($input))
        ) {
            $input = array_replace($defaults, $input);
        }
        if (
            !is_array($input) ||
            array_diff(array_keys($input), array_keys($defaults)) ||
            array_diff(array_keys($defaults), array_keys($input))
        ) {
            throw new InvalidArgumentException('设置字段不完整或包含未知字段。');
        }
        $out = [];
        foreach ($defaults as $key => $default) {
            if ($key === 'levels') {
                continue;
            }
            $threshold = str_ends_with($key, '_threshold');
            $max = $threshold ? 1000000000 : (str_ends_with($key, '_limit') ? 1000 : 1000000);
            $value = $input[$key];
            if (
                (!is_int($value) && !is_string($value)) ||
                !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
                strlen((string) $value) > 10 ||
                ($threshold && (int) $value < 1) ||
                (int) $value > $max
            ) {
                throw new InvalidArgumentException(
                    '经验须为 0–1000000，每日次数须为 0–1000，里程碑次数须为 1–1000000000 的整数。',
                );
            }
            $out[$key] = (int) $value;
        }
        foreach (self::tiers() as $kind => $slots) {
            $previous = 0;
            foreach ($slots as $slot) {
                $value = $out[$kind . '_' . $slot . '_threshold'];
                if ($value <= $previous) {
                    throw new InvalidArgumentException('每组里程碑次数必须严格递增。');
                }
                $previous = $value;
            }
        }
        $levels = $input['levels'];
        if (is_string($levels)) {
            $levels = preg_split('/[\s,，]+/u', trim($levels));
        }
        if (
            !is_array($levels) ||
            array_keys($levels) !== range(0, count($levels) - 1) ||
            count($levels) < 1 ||
            count($levels) > 100
        ) {
            throw new InvalidArgumentException('请填写 1–100 个等级门槛。');
        }
        $previous = -1;
        foreach ($levels as $i => $level) {
            if (
                (!is_int($level) && !is_string($level)) ||
                !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $level) ||
                strlen((string) $level) > 10 ||
                (int) $level > 1000000000 ||
                (int) $level <= $previous ||
                ($i === 0 && (int) $level !== 0)
            ) {
                throw new InvalidArgumentException(
                    '等级门槛必须从 0 开始、严格递增，且不超过 1000000000。',
                );
            }
            $previous = (int) $level;
            $levels[$i] = $previous;
        }
        $out['levels'] = $levels;
        return $out;
    }
    public static function rules(): array
    {
        if (self::$snapshot !== null) {
            return self::$snapshot;
        }
        try {
            return self::validate(get_option(self::OPTION, self::defaults()));
        } catch (InvalidArgumentException $e) {
            return self::defaults();
        }
    }
    public static function revision($raw): string
    {
        return hash('sha256', serialize($raw));
    }
    public static function save($input, $nonce, $revision)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '没有管理设置的权限。', ['status' => 403]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_xp_core_settings')) {
            return new WP_Error('nonce', '验证已过期，请刷新设置页。', ['status' => 403]);
        }
        global $wpdb;
        $lock = 'wp_xp_rules:' . hash('sha256', DB_NAME . ':' . $wpdb->prefix);
        // Serialize administrators, then compare against the exact value shown by their form.
        $lock = substr($lock, 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return new WP_Error('busy', '设置正被其他管理员修改，请重试。', ['status' => 409]);
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $old = get_option(self::OPTION, null);
            if (!is_string($revision) || !hash_equals(self::revision($old), $revision)) {
                return new WP_Error('conflict', '设置已被其他管理员更新，请刷新后重新修改。', [
                    'status' => 409,
                ]);
            }
            // Disabled controls are omitted by browsers. Preserve the stored policy under the same lock,
            // ignoring forged like fields as well, before validating the submitted complete policy.
            if (!self::likes_ready() && is_array($input)) {
                try {
                    $saved = self::validate($old);
                } catch (InvalidArgumentException $e) {
                    $saved = self::defaults();
                }
                foreach (self::tiers()['like'] as $slot) {
                    foreach (['like_' . $slot, 'like_' . $slot . '_threshold'] as $key) {
                        $input[$key] = $saved[$key];
                    }
                }
            }
            try {
                $rules = self::validate($input);
            } catch (InvalidArgumentException $e) {
                return new WP_Error('input', $e->getMessage(), ['status' => 400]);
            }
            if ($old === $rules) {
                return true;
            }
            if (!update_option(self::OPTION, $rules, false)) {
                return new WP_Error('storage', '设置未能保存，请重试。', ['status' => 503]);
            }
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
    public static function tiers(): array
    {
        return ['view' => [100, 500, 1000], 'like' => [10, 30, 100]];
    }
    public static function likes_ready(): bool
    {
        return function_exists('pagenest_companion_like') &&
            function_exists('pagenest_companion_feature') &&
            (bool) pagenest_companion_feature('likes');
    }
    public static function menu(): void
    {
        add_options_page('WP XP Core', 'WP XP Core', 'manage_options', 'wp-xp-core', [
            self::class,
            'page',
        ]);
    }
    public static function assets($hook): void
    {
        if ($hook !== 'settings_page_wp-xp-core') {
            return;
        }
        $manifest = json_decode(file_get_contents(__DIR__ . '/assets/assets.json'), true);
        wp_enqueue_style(
            'wp-xp-core-admin',
            plugins_url('assets/' . $manifest['admin_css'], __FILE__),
            [],
            Reader_Experience::VERSION,
        );
        wp_enqueue_script(
            'wp-xp-core-admin',
            plugins_url('assets/' . $manifest['admin_js'], __FILE__),
            [],
            Reader_Experience::VERSION,
            true,
        );
    }
    private static function field(string $key, string $label, array $rules): void
    {
        $limit = str_ends_with($key, '_limit');
        echo '<div class="xp-field"><label for="xp-' .
            esc_attr($key) .
            '">' .
            esc_html($label) .
            '</label><div class="xp-input-unit"><input type="number" min="0" max="' .
            ($limit ? '1000' : '1000000') .
            '" step="1" required id="xp-' .
            esc_attr($key) .
            '" name="rules[' .
            esc_attr($key) .
            ']" value="' .
            esc_attr((string) $rules[$key]) .
            '"><span>' .
            ($limit ? '次 / 日' : '经验') .
            '</span></div></div>';
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('没有管理设置的权限。');
        }
        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = self::save(
                wp_unslash($_POST['rules'] ?? []),
                $_POST['_wpnonce'] ?? '',
                $_POST['revision'] ?? '',
            );
        }
        echo '<div class="wrap wp-xp-core-admin"><header class="xp-header"><span class="xp-mark" aria-hidden="true">XP</span><div><h1>WP XP Core <span class="xp-version">' .
            esc_html(Reader_Experience::VERSION) .
            '</span></h1><p>经验规则 · 让每一次参与都有积累</p></div></header>';
        if ($result !== null) {
            echo '<div class="notice ' .
                (is_wp_error($result) ? 'notice-error' : 'notice-success') .
                '"><p>' .
                esc_html(is_wp_error($result) ? $result->get_error_message() : '设置已保存。') .
                '</p></div>';
        }
        $raw = get_option(self::OPTION, null);
        if ($raw !== null) {
            try {
                self::validate($raw);
            } catch (InvalidArgumentException $e) {
                echo '<div class="notice notice-warning"><p>已保存的设置无效，当前使用默认规则；请检查并重新保存。</p></div>';
            }
        }
        $rules = self::rules();
        echo '<div class="xp-layout"><form method="post" class="xp-form">';
        wp_nonce_field('wp_xp_core_settings');
        echo '<input type="hidden" name="revision" value="' . esc_attr(self::revision($raw)) . '">';
        echo '<section class="xp-card" aria-labelledby="xp-activity-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">01</span><div><h2 id="xp-activity-title">日常活动</h2><p>设置每次参与的奖励，以及每日可获得奖励的次数。</p></div></div><div class="xp-fields">';
        foreach (
            [
                'checkin_xp' => '每日签到',
                'article_xp' => '首次发表文章',
                'visit_xp' => '有效阅读',
                'comment_xp' => '有效评论',
                'visit_limit' => '每日阅读奖励次数',
                'comment_limit' => '每日评论奖励次数',
            ]
            as $key => $label
        ) {
            self::field($key, $label, $rules);
        }
        echo '</div><p class="xp-footnote">签到每日奖励一次；阅读仍需满足停留条件。次数为 0 时停发对应类别奖励。</p></section>';
        echo '<section class="xp-card" aria-labelledby="xp-milestones-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">02</span><div><h2 id="xp-milestones-title">作者里程碑</h2><p>设置每一档所需次数和奖励经验。</p></div></div>';
        foreach (self::tiers() as $kind => $slots) {
            $disabled = $kind === 'like' && !self::likes_ready();
            echo '<fieldset class="xp-milestone-group"' .
                ($disabled ? ' disabled' : '') .
                '><legend>' .
                ($kind === 'view' ? '浏览里程碑' : '点赞里程碑') .
                '</legend>';
            if ($kind === 'like') {
                echo '<p class="xp-help">' .
                    ($disabled
                        ? '未启用点赞服务：需要 PageNest Companion 及其点赞功能。此组暂不可编辑和发奖，已有设置会保留。'
                        : '点赞服务已启用：由 PageNest Companion 提供，按经验账本记录的有效点赞累计。') .
                    '</p>';
            }
            foreach ($slots as $i => $slot) {
                $key = $kind . '_' . $slot;
                echo '<div class="xp-milestone-row"><span class="xp-tier">第 ' .
                    ($i + 1) .
                    ' 档</span><div class="xp-field"><label for="xp-' .
                    esc_attr($key) .
                    '-threshold">所需' .
                    ($kind === 'view' ? '浏览' : '点赞') .
                    '次数</label><div class="xp-input-unit"><input id="xp-' .
                    esc_attr($key) .
                    '-threshold" name="rules[' .
                    esc_attr($key) .
                    '_threshold]" type="number" min="1" max="1000000000" step="1" required value="' .
                    esc_attr((string) $rules[$key . '_threshold']) .
                    '"><span>次</span></div></div>';
                self::field($key, '奖励经验', $rules);
                echo '</div>';
            }
            echo '</fieldset>';
        }
        echo '<p class="xp-footnote">每组次数须严格递增；每篇作品每档仅奖励一次，修改次数不会重置已领取档位。保存不发奖；未领取档位在下一次有效活动时按累计次数判断。</p></section>';
        echo '<section class="xp-card" aria-labelledby="xp-levels-title"><div class="xp-card-heading"><span class="xp-step" aria-hidden="true">03</span><div><h2 id="xp-levels-title">等级成长</h2><p>逐级设置最低经验，当前共 <span id="xp-level-count">' .
            count($rules['levels']) .
            '</span> 级。</p></div></div><div id="xp-levels" aria-describedby="xp-levels-help">';
        foreach ($rules['levels'] as $i => $level) {
            echo '<div class="xp-level-row"><label for="xp-level-' .
                $i .
                '">Level ' .
                ($i + 1) .
                '</label><div class="xp-input-unit"><input id="xp-level-' .
                $i .
                '" name="rules[levels][' .
                $i .
                ']" type="number" min="0" max="1000000000" step="1" required value="' .
                esc_attr((string) $level) .
                '"' .
                ($i === 0 ? ' readonly' : '') .
                '><span>经验</span></div><button type="button" class="button xp-level-remove" aria-label="移除 Level ' .
                ($i + 1) .
                '"' .
                ($i === 0 ? ' disabled' : '') .
                '>移除</button></div>';
        }
        echo '</div><button type="button" class="button xp-level-add" hidden>添加等级</button><p class="xp-level-message" role="status" aria-live="polite"></p><noscript><p>启用 JavaScript 后可添加或移除等级；现有门槛仍可编辑保存。</p></noscript><p id="xp-levels-help" class="xp-help">首级固定为 0，门槛须严格递增，最多 100 级。修改门槛会立即影响等级展示，不改变经验余额。</p></section>';
        echo '<div class="xp-save"><p>奖励调整仅影响之后首次记账的事件。</p>';
        submit_button('保存经验规则', 'primary', 'submit', false);
        echo '</div></form><aside class="xp-sidebar" aria-label="插件信息与帮助"><section class="xp-card xp-about"><span class="xp-eyebrow">关于插件</span><h2>WP XP Core</h2><p>独立的 WordPress 经验与等级系统。</p><dl><div><dt>版本</dt><dd>' .
            esc_html(Reader_Experience::VERSION) .
            '</dd></div><div><dt>作者</dt><dd><a href="https://github.com/wzf2000">wzf2000</a></dd></div><div><dt>许可证</dt><dd><a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html">GPL-2.0-or-later</a></dd></div></dl><a class="xp-repo-link" href="https://github.com/wzf2000/wp-xp-core">GitHub 源码仓库 <span aria-hidden="true">↗</span></a><p class="xp-help">私有仓库，访问需要相应授权。</p></section><section class="xp-card xp-guide"><h2>规则说明</h2><p>保存全站规则前，可先了解生效范围。</p><details><summary>奖励如何生效？</summary><p>不重算或补发历史经验。0 经验仍记录事件，避免日后重复领取；评论撤销与恢复使用首次奖励数值。</p></details><details><summary>数值可以设置多大？</summary><p>奖励为 0–1000000 的整数，每日次数为 0–1000。等级门槛最高为 1000000000。</p></details><details><summary>游戏也会奖励经验吗？</summary><p>游戏成绩与排行榜独立运行，不发放经验。</p></details></section></aside></div></div>';
    }
}
add_action('admin_menu', [WP_XP_Core_Settings::class, 'menu']);
add_action('admin_enqueue_scripts', [WP_XP_Core_Settings::class, 'assets']);
