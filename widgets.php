<?php
/** Small private server-rendered views; interactive actions stay in the full growth panel. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Widgets
{
    const TYPES = ['summary', 'tasks', 'badges', 'notifications', 'path'];
    private static int $owner = 0;
    private static array $snapshot = [];
    public static function clear(): void
    {
        self::$owner = 0;
        self::$snapshot = [];
    }
    public static function boot(): void
    {
        foreach (self::TYPES as $type) {
            add_shortcode(
                'xp_' . $type,
                static fn($attributes) => self::render(
                    $type,
                    is_array($attributes) ? $attributes : [],
                ),
            );
        }
        add_action('wp_xp_core_event_committed', [self::class, 'clear'], 99);
        add_action('set_current_user', [self::class, 'clear']);
        foreach (['added_user_meta', 'updated_user_meta', 'deleted_user_meta'] as $hook) {
            add_action(
                $hook,
                static function ($meta_id, $uid): void {
                    if ((int) $uid === self::$owner) {
                        self::clear();
                    }
                },
                10,
                2,
            );
        }
        $configuration_changed = static function ($option): void {
            if (
                in_array(
                    $option,
                    [
                        WP_XP_Core_Achievements::OPTION,
                        WP_XP_Core_Growth::OPTION,
                        WP_XP_Core_Settings::OPTION,
                    ],
                    true,
                )
            ) {
                self::clear();
            }
        };
        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, $configuration_changed);
        }
    }
    private static function data(string $type): array
    {
        $uid = get_current_user_id();
        if ($uid !== self::$owner) {
            self::clear();
            self::$owner = $uid;
        }
        if (!isset(self::$snapshot[$type])) {
            $value = match ($type) {
                'summary' => WP_XP_Core_Growth::summary($uid),
                'notifications' => WP_XP_Core_Notifications::listing(
                    ['limit' => 50],
                    wp_create_nonce('wp_rest'),
                ),
                default => WP_XP_Core_Achievements::personal(),
            };
            if (is_wp_error($value)) {
                throw new RuntimeException('Private widget unavailable');
            }
            self::$snapshot[$type] = $value;
        }
        return self::$snapshot[$type];
    }
    public static function attributes(): array
    {
        return [
            'limit' => ['type' => 'integer', 'default' => 5],
            'density' => [
                'type' => 'string',
                'enum' => ['compact', 'comfortable'],
                'default' => 'comfortable',
            ],
            'accent' => ['type' => 'string', 'default' => ''],
            'title' => ['type' => 'string', 'default' => ''],
        ];
    }
    public static function render(string $type, array $attributes = []): string
    {
        if (!is_user_logged_in()) {
            return '<p class="xp-widget-login"><a href="' .
                esc_url(wp_login_url(reader_experience_panel_url())) .
                '">' .
                esc_html(wp_xp_core_t('登录后查看我的成长')) .
                '</a></p>';
        }
        if (!current_user_can('read') || !Reader_Experience::ready()) {
            return '<p>' . esc_html(wp_xp_core_t('经验系统维护中，请稍后重试。')) . '</p>';
        }
        if (!headers_sent()) {
            nocache_headers();
            header('Vary: Cookie', false);
        }
        try {
            if (
                !in_array($type, self::TYPES, true) ||
                array_diff(array_keys($attributes), [
                    'limit',
                    'density',
                    'accent',
                    'title',
                    'series',
                ])
            ) {
                throw new InvalidArgumentException('Unsupported private widget attributes');
            }
            $limit = $attributes['limit'] ?? 5;
            if (
                (!is_int($limit) && !is_string($limit)) ||
                !preg_match('/^[1-9][0-9]?$/D', (string) $limit) ||
                (int) $limit > 20
            ) {
                throw new InvalidArgumentException('Invalid widget limit');
            }
            $density = $attributes['density'] ?? 'comfortable';
            $accent = $attributes['accent'] ?? '';
            $title = $attributes['title'] ?? '';
            $series = $attributes['series'] ?? '';
            if (
                !in_array($density, ['compact', 'comfortable'], true) ||
                !is_string($accent) ||
                ($accent !== '' &&
                    !preg_match('/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/D', $accent)) ||
                !is_string($title) ||
                mb_strlen($title) > 80 ||
                !is_string($series) ||
                mb_strlen($series) > 60 ||
                preg_match('/[<>\x00-\x1f]/u', $title . $series) ||
                ($type !== 'path' && $series !== '')
            ) {
                throw new InvalidArgumentException('Invalid widget style or title');
            }
            $defaults = [
                'summary' => wp_xp_core_t('我的成长概览'),
                'tasks' => wp_xp_core_t('我的任务'),
                'badges' => wp_xp_core_t('我的成就'),
                'notifications' => wp_xp_core_t('我的通知'),
                'path' => wp_xp_core_t('我的成长路径'),
            ];
            $html =
                '<section class="xp-widget xp-widget--' .
                esc_attr($density) .
                '"' .
                ($accent !== '' ? ' style="--xp-growth-accent:' . esc_attr($accent) . '"' : '') .
                '><h2>' .
                esc_html($title !== '' ? $title : $defaults[$type]) .
                '</h2>';
            if ($type === 'summary') {
                $data = self::data('summary');
                $html .=
                    '<p><strong>' .
                    (int) $data['experience'] .
                    '</strong> ' .
                    esc_html(wp_xp_core_t('经验')) .
                    ' · ' .
                    esc_html($data['level_profile']['name']) .
                    '</p><progress max="100" value="' .
                    (int) $data['progress_percent'] .
                    '" aria-label="' .
                    esc_attr(wp_xp_core_t('等级进度')) .
                    '"></progress><p>' .
                    esc_html(
                        $data['checked_in']
                            ? wp_xp_core_t('今日已签到')
                            : wp_xp_core_t('今日尚未签到'),
                    ) .
                    '</p>';
            } elseif ($type === 'notifications') {
                $items = array_slice(self::data('notifications')['items'], 0, (int) $limit);
                $html .= '<ul>';
                foreach ($items as $item) {
                    $html .=
                        '<li><strong>' .
                        esc_html($item['title']) .
                        '</strong> · ' .
                        esc_html($item['day']) .
                        '<p>' .
                        esc_html($item['message']) .
                        '</p><small>' .
                        esc_html($item['read'] ? wp_xp_core_t('已读') : wp_xp_core_t('未读')) .
                        '</small></li>';
                }
                $html .= '</ul>';
                if (!$items) {
                    $html .= '<p>' . esc_html(wp_xp_core_t('暂无通知。')) . '</p>';
                }
            } elseif ($type === 'badges') {
                $items = array_slice(self::data('personal')['badges'], 0, (int) $limit);
                $html .= '<ul>';
                foreach ($items as $item) {
                    $html .=
                        '<li>' .
                        esc_html($item['icon'] . ' ' . $item['title']) .
                        ' · ' .
                        esc_html($item['day']) .
                        '</li>';
                }
                $html .= '</ul>';
                if (!$items) {
                    $html .= '<p>' . esc_html(wp_xp_core_t('暂无已获成就。')) . '</p>';
                }
            } else {
                $items = self::data('personal')['tasks'];
                if ($series !== '') {
                    $items = array_values(
                        array_filter($items, static fn($task) => $task['series'] === $series),
                    );
                }
                $items = array_slice($items, 0, (int) $limit);
                $html .=
                    '<p class="xp-widget-meta">' .
                    esc_html(wp_xp_core_t('按当前任务版本与周期显示。')) .
                    '</p><ol>';
                foreach ($items as $task) {
                    $status = $task['earned']
                        ? wp_xp_core_t('已完成')
                        : ($task['revoked']
                            ? wp_xp_core_t('已撤销')
                            : ($task['available']
                                ? wp_xp_core_t('可继续')
                                : wp_xp_core_t('未解锁')));
                    $html .=
                        '<li><strong>' .
                        esc_html($task['title']) .
                        '</strong> · ' .
                        esc_html($status) .
                        '<p>' .
                        esc_html($task['condition_text']) .
                        '</p><small>v' .
                        (int) $task['version'] .
                        ' · ' .
                        esc_html($task['period_key']) .
                        '</small>';
                    if ($type === 'tasks') {
                        $html .= '<ul>';
                        foreach ($task['steps'] as $step) {
                            $html .=
                                '<li>' .
                                esc_html($step['label']) .
                                ' ' .
                                (int) $step['current'] .
                                ' / ' .
                                (int) $step['count'] .
                                '</li>';
                        }
                        $html .= '</ul>';
                    }
                    if ($type === 'path') {
                        foreach ($task['prerequisites'] as $prerequisite) {
                            $html .=
                                '<p>' .
                                esc_html(wp_xp_core_t('前置任务：')) .
                                esc_html($prerequisite['title']) .
                                ' · ' .
                                esc_html(
                                    $prerequisite['complete']
                                        ? wp_xp_core_t('已完成')
                                        : wp_xp_core_t('待完成'),
                                ) .
                                '</p>';
                        }
                    }
                    $html .= '</li>';
                }
                $html .= '</ol>';
                if (!$items) {
                    $html .= '<p>' . esc_html(wp_xp_core_t('暂无可展示的任务。')) . '</p>';
                }
            }
            return $html .
                '<p><a href="' .
                esc_url(reader_experience_panel_url()) .
                '">' .
                esc_html(wp_xp_core_t('打开完整成长面板')) .
                '</a></p></section>';
        } catch (InvalidArgumentException $e) {
            return '<p>' . esc_html(wp_xp_core_t('成长组件设置无效。')) . '</p>';
        } catch (Throwable $e) {
            return '<p>' . esc_html(wp_xp_core_t('成长内容暂时无法读取，请重试。')) . '</p>';
        }
    }
}
add_action('init', [WP_XP_Core_Widgets::class, 'boot']);
