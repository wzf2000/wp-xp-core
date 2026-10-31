<?php
/** Private standalone shortcodes and dynamic blocks share bounded request-local projections. */
if (
    PHP_SAPI !== 'cli' ||
    getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci' ||
    DB_NAME !== 'wp_xp_core_ci' ||
    wp_get_environment_type() !== 'local'
) {
    throw new RuntimeException('Dedicated synthetic database required');
}
global $wpdb;
$checks = [];
$verify = static function ($name, $value) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$admin = get_user_by('login', 'fixture-author');
wp_set_current_user($admin->ID);
$original = WP_XP_Core_Achievements::definitions();
update_option(WP_XP_Core_Achievements::OPTION, [], false);
WP_XP_Core_Achievements::clear_cache();
$users = [];
foreach (['one', 'two'] as $suffix) {
    $user = wp_insert_user([
        'user_login' => 'fixture-widget-' . $suffix,
        'user_email' => 'widget-' . $suffix . '@example.invalid',
        'user_pass' => wp_generate_password(32),
        'role' => 'subscriber',
    ]);
    $verify('Widget subject created ' . $suffix, !is_wp_error($user));
    $users[] = (int) $user;
}
$save = static fn($task) => WP_XP_Core_Achievements::save(
    $task,
    wp_create_nonce('wp_xp_core_achievements'),
    WP_XP_Core_Achievements::revision(),
);
$task = [
    'id' => '',
    'title' => 'Synthetic widget check-in',
    'description' => '',
    'icon' => '◇',
    'type' => 'automatic',
    'period' => 'once',
    'enabled' => true,
    'series' => 'Synthetic widget path',
    'steps' => [['kind' => 'checkin', 'count' => 3]],
    'reward_xp' => 0,
];
try {
    $saved = $save($task);
    $verify('Widget task saves', !is_wp_error($saved));
    $id = $saved['id'];
    $locked = $task;
    $locked['title'] = 'Synthetic locked task';
    $locked['type'] = 'review';
    $locked['steps'] = [];
    $locked['prerequisites'] = [['id' => $id, 'scope' => 'current']];
    $verify('Widget prerequisite task saves', !is_wp_error($save($locked)));
    $snapshot = static fn() => [
        $wpdb->get_results('SELECT * FROM ' . Reader_Experience::table() . ' ORDER BY id', ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A),
        WP_XP_Core_Achievements::definitions(),
    ];
    wp_set_current_user(0);
    foreach (WP_XP_Core_Widgets::TYPES as $type) {
        $html = do_shortcode('[xp_' . $type . ']');
        $verify(
            'Anonymous widget only offers login ' . $type,
            str_contains($html, 'wp-login.php') && !str_contains($html, 'Synthetic widget'),
        );
    }
    wp_set_current_user($users[0]);
    $before = $snapshot();
    $scans = 0;
    $count = static function ($sql) use (&$scans) {
        if (str_contains($sql, 'COUNT(DISTINCT CASE')) {
            $scans++;
        }
        return $sql;
    };
    add_filter('query', $count);
    try {
        $tasks = do_shortcode(
            '[xp_tasks limit="20" density="compact" accent="#123abc" title="Selected tasks"]',
        );
        $after_first = $scans;
        $path = do_shortcode('[xp_path limit="20" series="Synthetic widget path"]');
        $badges = do_shortcode('[xp_badges]');
    } finally {
        remove_filter('query', $count);
    }
    $verify(
        'Task, path and badge widgets share one personal scan',
        $after_first === 1 && $scans === 1,
    );
    $verify(
        'Current path displays locked prerequisites and bounded progress',
        str_contains($path, 'Synthetic widget check-in') &&
            str_contains($path, 'Synthetic locked task') &&
            str_contains($path, '未解锁') &&
            str_contains($tasks, '0 / 3'),
    );
    $verify(
        'Validated style and title render safely',
        str_contains($tasks, 'xp-widget--compact') &&
            str_contains($tasks, '--xp-growth-accent:#123abc') &&
            str_contains($tasks, 'Selected tasks'),
    );
    foreach (WP_XP_Core_Widgets::TYPES as $type) {
        $html = do_shortcode('[xp_' . $type . ']');
        $verify(
            'Widget links to full panel without requiring growth DOM ' . $type,
            str_contains($html, '打开完整成长面板') &&
                !str_contains($html, 'data-xp-panel') &&
                !str_contains($html, '<script'),
        );
        $verify(
            'Dynamic block registered ' . $type,
            WP_Block_Type_Registry::get_instance()->is_registered('wp-xp-core/' . $type),
        );
        $block = render_block([
            'blockName' => 'wp-xp-core/' . $type,
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
        $verify(
            'Dynamic block uses private server renderer ' . $type,
            str_contains($block, 'xp-widget'),
        );
    }
    $verify('Widget reads do not alter ledger, users or tasks', $snapshot() === $before);
    $verify(
        'Target user argument is rejected',
        str_contains(do_shortcode('[xp_tasks user_id="' . $users[1] . '"]'), '成长组件设置无效'),
    );
    $verify(
        'Invalid CSS is rejected',
        str_contains(do_shortcode('[xp_summary accent="url(javascript:bad)"]'), '成长组件设置无效'),
    );
    $verify(
        'Limit remains bounded',
        str_contains(do_shortcode('[xp_tasks limit="100"]'), '成长组件设置无效'),
    );
    $verify(
        'Unsafe title is rejected',
        str_contains(
            WP_XP_Core_Widgets::render('tasks', ['title' => '<script>alert(1)</script>']),
            '成长组件设置无效',
        ),
    );
    Reader_Experience::transaction(static function () use ($users): void {
        Reader_Experience::record(
            'checkin:' . $users[0] . ':' . Reader_Experience::day(),
            $users[0],
            'checkin',
            0,
            0,
            Reader_Experience::day(),
        );
    });
    $verify(
        'Committed event invalidates the widget projection',
        str_contains(do_shortcode('[xp_tasks limit="20"]'), '1 / 3'),
    );
    wp_set_current_user($users[1]);
    $verify(
        'Switching users cannot reuse private progress',
        str_contains(do_shortcode('[xp_tasks limit="20"]'), '0 / 3'),
    );
    wp_set_current_user($users[0]);
    $verify(
        'Switching back observes the original owner again',
        str_contains(do_shortcode('[xp_tasks limit="20"]'), '1 / 3'),
    );
    WP_XP_Core_Widgets::clear();
    $faulted = false;
    $fault = static function ($sql) use (&$faulted) {
        if (!$faulted && str_contains($sql, 'COUNT(DISTINCT CASE')) {
            $faulted = true;
            return 'SELECT * FROM synthetic_missing_widget_table';
        }
        return $sql;
    };
    $suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $fault);
    try {
        $failed = do_shortcode('[xp_tasks]');
    } finally {
        remove_filter('query', $fault);
        $wpdb->suppress_errors($suppressed);
    }
    $verify(
        'Widget read failures show a local error',
        $faulted && str_contains($failed, '成长内容暂时无法读取'),
    );
    $verify(
        'Failed projection is never cached as an empty task list',
        str_contains(do_shortcode('[xp_tasks limit="20"]'), '1 / 3'),
    );
    $verify(
        'Series filtering does not expose unrelated tasks',
        !str_contains(
            do_shortcode('[xp_path series="Absent series"]'),
            'Synthetic widget check-in',
        ),
    );
} finally {
    wp_set_current_user($admin->ID);
    update_option(WP_XP_Core_Achievements::OPTION, $original, false);
    WP_XP_Core_Achievements::clear_cache();
    WP_XP_Core_Widgets::clear();
}
echo wp_json_encode(['count' => count($checks), 'checks' => $checks]);
