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
            'levels' => [0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000],
        ];
    }
    public static function validate($input): array
    {
        $defaults = self::defaults();
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
            $max = str_ends_with($key, '_limit') ? 1000 : 1000000;
            $value = $input[$key];
            if (
                (!is_int($value) && !is_string($value)) ||
                !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
                strlen((string) $value) > 7 ||
                (int) $value > $max
            ) {
                throw new InvalidArgumentException(
                    '经验必须为 0–1000000 的整数，每日次数必须为 0–1000 的整数。',
                );
            }
            $out[$key] = (int) $value;
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
        try {
            $rules = self::validate($input);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('input', $e->getMessage(), ['status' => 400]);
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
    public static function menu(): void
    {
        add_options_page('WP XP Core', 'WP XP Core', 'manage_options', 'wp-xp-core', [
            self::class,
            'page',
        ]);
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
        echo '<div class="wrap"><h1>WP XP Core · 经验规则</h1>';
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
        echo '<p>奖励与次数仅影响之后首次记账的事件，不重算历史经验。修改等级门槛会立即改变等级展示，但不修改经验余额。0 经验仍记录事件；每日次数为 0 时不发放该类奖励。</p><p>浏览里程碑固定为 100 / 500 / 1000 次，点赞里程碑固定为 10 / 30 / 100 次，保证历史奖励不重复发放；下方可修改各档奖励。评论撤销与恢复始终使用首次奖励数值。</p><form method="post">';
        wp_nonce_field('wp_xp_core_settings');
        echo '<input type="hidden" name="revision" value="' .
            esc_attr(self::revision($raw)) .
            '"><table class="form-table">';
        $labels = [
            'checkin_xp' => '每日签到经验',
            'visit_xp' => '有效阅读经验',
            'article_xp' => '首次发表文章经验',
            'comment_xp' => '有效评论经验',
            'visit_limit' => '每日阅读奖励次数',
            'comment_limit' => '每日评论奖励次数',
            'view_100' => '100 次浏览奖励',
            'view_500' => '500 次浏览奖励',
            'view_1000' => '1000 次浏览奖励',
            'like_10' => '10 次点赞奖励',
            'like_30' => '30 次点赞奖励',
            'like_100' => '100 次点赞奖励',
        ];
        foreach ($labels as $key => $label) {
            echo '<tr><th><label for="xp-' .
                esc_attr($key) .
                '">' .
                esc_html($label) .
                '</label></th><td><input type="number" min="0" max="' .
                (str_ends_with($key, '_limit') ? '1000' : '1000000') .
                '" step="1" required id="xp-' .
                esc_attr($key) .
                '" name="rules[' .
                esc_attr($key) .
                ']" value="' .
                esc_attr((string) $rules[$key]) .
                '"></td></tr>';
        }
        echo '<tr><th><label for="xp-levels">各级最低经验</label></th><td><textarea id="xp-levels" name="rules[levels]" rows="4" class="large-text" required>' .
            esc_textarea(implode(', ', $rules['levels'])) .
            '</textarea><p class="description">按 Level 1 起依次填写，用逗号或空白分隔；首项必须为 0，严格递增，最多 100 级。</p></td></tr></table>';
        submit_button('保存经验规则');
        echo '</form></div>';
    }
}
add_action('admin_menu', [WP_XP_Core_Settings::class, 'menu']);
