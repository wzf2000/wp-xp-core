<?php
/** Synthetic ledger management checks, with no WordPress installation or production access. */
require __DIR__ . '/runtime.php';
$wpdb = new FixtureStorage();
$checks = 0;
$GLOBALS['capable'] = true;
$GLOBALS['logged'] = true;
$GLOBALS['nonce_action'] = 'wp_xp_core_manage';
function request_id(): string
{
    static $number = 0;
    return '00000000-0000-4000-8000-' . str_pad((string) ++$number, 12, '0', STR_PAD_LEFT);
}
function adjustment(int $xp, int $uid = 7): array
{
    return [
        'request_id' => request_id(),
        'user_id' => $uid,
        'xp' => $xp,
        'reason' => '纠正活动奖励',
    ];
}
function error_code($result): string
{
    return $result instanceof WP_Error ? $result->code : '';
}
$input = adjustment(20);
$result = WP_XP_Core_Management::adjust($input, 'fixture-nonce');
verify('adjustment appends an award', !($result instanceof WP_Error) && $result['event_id'] > 0);
$original = $result['event_id'];
verify(
    'adjustment records actor and reason',
    WP_XP_Core_Management::events()['items'][0]['detail']['actor'] === 7,
);
verify(
    'repeat request returns original result',
    WP_XP_Core_Management::adjust($input, 'fixture-nonce')['duplicate'],
);
verify(
    'repeat request awards only once',
    get_user_meta(7, Reader_Experience::balance_meta(), true) === '20',
);
$changed = array_replace($input, ['reason' => '另一个原因']);
verify(
    'same token different payload conflicts',
    error_code(WP_XP_Core_Management::adjust($changed, 'fixture-nonce')) === 'conflict',
);
verify(
    'nonce is action specific',
    error_code(WP_XP_Core_Management::adjust(adjustment(1), 'bad')) === 'nonce',
);
verify(
    'required reason',
    error_code(
        WP_XP_Core_Management::adjust(
            array_replace(adjustment(1), ['reason' => '   ']),
            'fixture-nonce',
        ),
    ) === 'input',
);
verify(
    'bounded reason',
    error_code(
        WP_XP_Core_Management::adjust(
            array_replace(adjustment(1), ['reason' => str_repeat('原', 301)]),
            'fixture-nonce',
        ),
    ) === 'input',
);
verify(
    'request token syntax',
    error_code(
        WP_XP_Core_Management::adjust(
            array_replace(adjustment(1), ['request_id' => 'anything']),
            'fixture-nonce',
        ),
    ) === 'input',
);
$GLOBALS['capable'] = false;
foreach (
    [
        fn() => WP_XP_Core_Management::events(),
        fn() => WP_XP_Core_Management::user_summary(7),
        fn() => WP_XP_Core_Management::diagnostics(),
        fn() => WP_XP_Core_Management::failures(),
        fn() => WP_XP_Core_Management::audit_history(),
        fn() => WP_XP_Core_Management::adjust(adjustment(1), 'fixture-nonce'),
    ]
    as $call
) {
    verify('management permission enforced within API', error_code($call()) === 'forbidden');
}
$GLOBALS['capable'] = true;
$GLOBALS['denied_users'] = [9];
verify(
    'target edit permission required',
    error_code(WP_XP_Core_Management::adjust(adjustment(1, 9), 'fixture-nonce')) === 'forbidden',
);
verify(
    'target query permission required',
    error_code(WP_XP_Core_Management::events(['user_id' => 9])) === 'forbidden',
);
$GLOBALS['denied_users'] = [];
verify(
    'insufficient balance refuses deduction',
    error_code(WP_XP_Core_Management::adjust(adjustment(-21), 'fixture-nonce')) ===
        'insufficient_balance',
);
$deduct = WP_XP_Core_Management::adjust(adjustment(-5), 'fixture-nonce');
$reverse = ['request_id' => request_id(), 'event_id' => $original, 'reason' => '撤销误发'];
verify(
    'reversal cannot clamp balance',
    error_code(WP_XP_Core_Management::reverse($reverse, 'fixture-nonce')) ===
        'insufficient_balance',
);
$undo_deduct = [
    'request_id' => request_id(),
    'event_id' => $deduct['event_id'],
    'reason' => '恢复扣减',
];
verify(
    'negative adjustment can be reversed',
    !(WP_XP_Core_Management::reverse($undo_deduct, 'fixture-nonce') instanceof WP_Error),
);
verify(
    'positive adjustment reversal succeeds',
    !(WP_XP_Core_Management::reverse($reverse, 'fixture-nonce') instanceof WP_Error),
);
verify(
    'duplicate reversal request is stable',
    WP_XP_Core_Management::reverse($reverse, 'fixture-nonce')['duplicate'],
);
verify(
    'second reversal of adjustment refused',
    error_code(
        WP_XP_Core_Management::reverse(
            array_replace($reverse, ['request_id' => request_id()]),
            'fixture-nonce',
        ),
    ) === 'conflict',
);
verify(
    'reversal leaves original row intact',
    (int) $wpdb->get_var(
        'SELECT xp FROM fixture_reader_experience_events WHERE id=' . $original,
    ) === 20,
);
verify('balance remains the ledger sum', WP_XP_Core_Management::user_summary(7)['consistent']);
verify(
    'event limit enforced',
    error_code(WP_XP_Core_Management::events(['limit' => 101])) === 'input',
);
verify(
    'date validity checked',
    error_code(WP_XP_Core_Management::events(['from' => '2026-02-30'])) === 'input',
);
$page = WP_XP_Core_Management::events(['limit' => 2]);
$next = WP_XP_Core_Management::events(['limit' => 2, 'cursor' => $page['next_cursor']]);
verify(
    'event pagination does not repeat rows',
    count($page['items']) === 2 && $page['items'][1]['id'] > $next['items'][0]['id'],
);
$private = WP_XP_Core_Management::personal_history(['limit' => 2]);
verify(
    'private history omits management details',
    !array_key_exists('detail', $private['items'][0]) &&
        !array_key_exists('user_id', $private['items'][0]),
);
verify(
    'private history rejects another account',
    error_code(WP_XP_Core_Management::personal_history(['user_id' => 8])) === 'forbidden',
);
$GLOBALS['deleted_users'] = [8];
$wpdb->query(
    "INSERT INTO fixture_reader_experience_events(event_key,user_id,kind,object_id,local_day,xp,created_at) VALUES('deleted:8',8,'article',8,'2026-01-01',3,1)",
);
Reader_Experience::transaction(fn() => Reader_Experience::set_balance(9, 0));
$diagnostic = WP_XP_Core_Management::diagnostics(7, 1);
verify(
    'diagnostic includes deleted users without balances',
    $diagnostic['items'][0]['user_id'] === 8 &&
        !$diagnostic['items'][0]['user_exists'] &&
        !$diagnostic['items'][0]['balance_present'] &&
        !$diagnostic['items'][0]['consistent'],
);
verify(
    'diagnostic paginates metadata only owners',
    WP_XP_Core_Management::diagnostics($diagnostic['next_cursor'], 1)['items'][0]['user_id'] === 9,
);
$GLOBALS['deleted_users'] = [];

// A failed concrete publish attempt, rather than a scan of historical content, enables retry.
$wpdb->failWrite = true;
Reader_Experience::published('publish', 'draft', get_post(800));
$wpdb->failWrite = false;
$failure = WP_XP_Core_Management::failures()[0];
verify(
    'publish failure persisted after ledger rollback',
    $failure['kind'] === 'article' &&
        $failure['xp'] === 20 &&
        !Reader_Experience::exists('article:800'),
);
verify('failure record hides raw storage error', $failure['last_error'] === '记录未完成');
$retry = [
    'request_id' => request_id(),
    'failure_id' => $failure['id'],
    'reason' => '重新记录发表奖励',
];
$GLOBALS['posts'][800] = (object) [
    'ID' => 800,
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_author' => 8,
];
verify(
    'retry validates current object status',
    error_code(WP_XP_Core_Management::retry($retry, 'fixture-nonce')) === 'ineligible',
);
unset($GLOBALS['posts'][800]);
$wpdb->failOptionName = WP_XP_Core_Management::FAILURE_OPTION;
verify(
    'retry resolution failure returns error',
    error_code(WP_XP_Core_Management::retry($retry, 'fixture-nonce')) === 'storage',
);
verify('retry resolution and award roll back together', !Reader_Experience::exists('article:800'));
$wpdb->failOptionName = null;
$rules = WP_XP_Core_Settings::defaults();
$rules['article_xp'] = 99;
update_option(WP_XP_Core_Settings::OPTION, $rules);
verify(
    'recorded retry succeeds',
    !(WP_XP_Core_Management::retry($retry, 'fixture-nonce') instanceof WP_Error),
);
verify(
    'retry keeps original award amount',
    (int) $wpdb->get_var(
        "SELECT xp FROM fixture_reader_experience_events WHERE event_key='article:800'",
    ) === 20,
);
verify(
    'repeat retry request does not issue again',
    WP_XP_Core_Management::retry($retry, 'fixture-nonce')['duplicate'],
);
verify(
    'unknown failure cannot trigger historical award',
    error_code(
        WP_XP_Core_Management::retry(
            array_replace($retry, [
                'request_id' => request_id(),
                'failure_id' => str_repeat('a', 32),
            ]),
            'fixture-nonce',
        ),
    ) === 'missing',
);
verify(
    'only manual changes can be reversed',
    error_code(
        WP_XP_Core_Management::reverse(
            [
                'request_id' => request_id(),
                'event_id' => WP_XP_Core_Management::retry($retry, 'fixture-nonce')['event_id'],
                'reason' => '测试边界',
            ],
            'fixture-nonce',
        ),
    ) === 'input',
);
$wpdb->failWrite = true;
$GLOBALS['comments'][801] = (object) [
    'comment_post_ID' => 800,
    'user_id' => 7,
    'comment_parent' => 0,
    'comment_approved' => '1',
    'comment_type' => 'comment',
];
Reader_Experience::comment(801);
$wpdb->failWrite = false;
$comment_failure = WP_XP_Core_Management::failures()[0];
verify('eligible comment failure persisted', $comment_failure['kind'] === 'comment');
$comment_retry = [
    'request_id' => request_id(),
    'failure_id' => $comment_failure['id'],
    'reason' => '重新记录评论奖励',
];
$GLOBALS['comments'][801]->comment_approved = '0';
verify(
    'unapproved comment cannot be retried',
    error_code(WP_XP_Core_Management::retry($comment_retry, 'fixture-nonce')) === 'ineligible',
);
$GLOBALS['comments'][801]->comment_approved = '1';
verify(
    'eligible comment retry succeeds',
    !(WP_XP_Core_Management::retry($comment_retry, 'fixture-nonce') instanceof WP_Error),
);

$GLOBALS['nonce_action'] = 'wp_xp_core_settings';
$before = get_option(WP_XP_Core_Settings::OPTION, null);
$rules['checkin_xp'] = 4;
$wpdb->failOptionName = WP_XP_Core_Management::AUDIT_OPTION;
verify(
    'audit failure prevents rule change',
    error_code(
        WP_XP_Core_Settings::save($rules, 'fixture-nonce', WP_XP_Core_Settings::revision($before)),
    ) === 'storage',
);
verify(
    'audit failure restores previous rule',
    get_option(WP_XP_Core_Settings::OPTION, null) === $before,
);
$wpdb->failOptionName = null;
verify(
    'rule and audit save together',
    WP_XP_Core_Settings::save($rules, 'fixture-nonce', WP_XP_Core_Settings::revision($before)) ===
        true,
);
$audit = WP_XP_Core_Management::audit_history()[0];
verify(
    'audit contains before after and actor',
    $audit['before'] === $before && $audit['after'] === $rules && $audit['actor'] === 7,
);
echo "$checks management checks passed\n";
