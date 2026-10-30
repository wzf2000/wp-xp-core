<!-- wp-xp-core-version: 2.1.0-rc.1 -->

# Server configuration and integration

[简体中文](CONFIGURATION.md) · English · [Back to overview](../README.en.md) · [User guide](GUIDE.en.md)

This document describes **2.1.0-rc.1**. Installed releases follow their tagged documentation.

This page is for integrators. **Ordinary first-time installation needs no external configuration: upload and activate a 1.4.0+ installation ZIP.** For page setup, see the [user guide](GUIDE.en.md). External JSON is only for advanced deployments with custom storage or existing integrations.

## Default installation

Without `PAGENEST_COMPATIBILITY_PROFILE_FILE`, the plugin uses built-in configuration. First activation creates the WordPress-prefixed `reader_experience_events` ledger and its own installation markers/live switch. It does not read myCRED data, import historical activity or bulk-initialize user balances. Users without a balance display zero XP and earn experience through subsequent eligible activity.

Activation requires InnoDB support, with WordPress user metadata, post metadata and options already using InnoDB. The plugin does not convert other tables. It verifies schema and unique event keys and serializes installation with a database lock; failed setup leaves rewards disabled. Deactivation and reactivation preserve the ledger, balances, rules and any existing maintenance switch, without recalculating or backfilling XP. Normal requests never initialize storage.

Incentive statistics require the database functions `JSON_VALID` and `JSON_EXTRACT`; MySQL 8.0 and MariaDB 11.4 have been verified. Check function support before using an older database; the plugin does not upgrade the database server.

Create an experience page manually with `[reader_experience]`. The separate `wp_xp_core_panel_page_id` option stores the administrator’s selected public, unpassworded page; it is independent of rewards. Without a selection, links use the current page or home. Administrators create and publish pages; the plugin never publishes content automatically.

Multisite supports activation separately on each site, not network-wide activation. Native balances are scoped by the site table prefix, as are ledgers and installation options. Integrators can use `Reader_Experience::balance_meta()` for the current balance key.

## Optional external configuration

Defining `PAGENEST_COMPATIBILITY_PROFILE_FILE` opts into advanced external integration. Its readable JSON file must be **outside the WordPress web root (`ABSPATH`)**, with `schema_version: 1` and an explicit `experience` object.

This mode retains existing configuration, ledger, balances and panel page; activation does not run the native installer. Missing, incomplete or invalid external configuration disables experience and **never falls back to an empty native ledger**. The constant is a retained shared interface; experience and levels do not require PageNest Companion. Its like service is required only for like milestones.

## Integration checklist

Only advanced external deployments need these checks; ordinary first-time installations can skip them:

1. Confirm WordPress 6.0+, PHP 8.0+, and back up affected configuration and storage.
2. Verify the event ledger, `reader_experience_balance` projection and matching balances, unique event keys and compatible fields. Ledger, user metadata and post metadata must use InnoDB.
3. Prepare the external profile and confirm the existing live option stores `1`. Readiness in this mode does not itself validate table structure.
4. Where another experience implementation exists, establish one owner and retain required route, shortcode and scheduling aliases.
5. Verify the integration in isolation before following your site’s deployment process.

A fresh native install refuses to adopt an unowned same-name ledger, existing experience balances or rules; data is preserved for explicit integration. This is not a myCRED migration feature.

## Profile fields

The `experience` object supports these ten fields; omitted fields use defaults from `config.php`:

| Field            | Purpose                                                                 |
| ---------------- | ----------------------------------------------------------------------- |
| `table_suffix`   | Existing event table, appended to the WordPress table prefix            |
| `live_option`    | Existing live policy flag                                               |
| `week_option`    | Existing weekly rotation marker                                         |
| `event_lock`     | Ledger transaction lock, scoped to the database                         |
| `weekly_lock`    | Exact shared weekly scoring lock, at most 64 ASCII bytes                |
| `rest_namespace` | Primary authenticated REST namespace                                    |
| `rest_aliases`   | Compatibility namespace list, same handlers and permission checks       |
| `shortcodes`     | Experience panel shortcode names                                        |
| `panel_page_id`  | Existing panel page ID; zero uses the current page or home              |
| `weekly_hook`    | Existing weekly scheduling hook; this plugin does not create a schedule |

Defaults use neutral names. Configure existing names before replacing an old owner. Unknown fields, invalid identifiers and malformed aliases are rejected. The weekly lock must match the scoring owner's lock exactly; event locks remain isolated by database.

Companion's `pagenest_like_recorded(user_id, post_id, count)` is consumed idempotently using the existing `like:user:post` event format with zero XP. A stored event suppresses duplicates. Companion persists its own like count and new-user record atomically; experience ledger writes remain a separate transaction. A later idempotent like retry can deliver an experience event whose previous delivery failed.

The `site_tools_account_panel` filter returns the existing experience panel when ready. The callback keeps the incoming value when the policy is unavailable. No other plugin needs to reference the implementation class. Retained like REST aliases delegate to Companion; disabling experience does not prevent Companion likes.

## Global rule settings

Administrators can open **Settings → WP XP Core** to configure rewards, reading/comment daily caps and 1–100 increasing level thresholds. Rules use `wp_xp_core_rules`; advanced external profiles continue to configure storage and integration. Reading never creates the option. Missing or invalid settings use the original defaults. See the [user guide](GUIDE.en.md) for ranges and defaults.

Saving requires `manage_options` and a valid nonce. A database-scoped advisory lock serializes settings saves; a revision hash rejects stale forms, and invalid values leave the previous option untouched. An event transaction snapshots one complete policy. Zero rewards retain idempotency markers; zero caps suppress that reward category. Milestone counts, rewards and tier lists are configurable. Historical events are not recalculated: comment reversals and restorations use the original event amount. Level changes immediately affect display without rewriting balances. Manual adjustments and reversals append ledger events rather than editing projections.

The settings screen groups activity rules, author milestones and levels in responsive cards. Its information sidebar links to the author, license and source repository. The `admin_css` and `admin_js` asset manifest entries are content-hashed and enqueued only for the corresponding plugin administration pages; it adds no external fonts, scripts or network dependencies. Plugin metadata identifies the author and repository; `Update URI` identifies the repository; optional official update checks default to off and require explicit activation in Configuration tools.

### Milestone identity and level rows

Policies add activity `activities` switches, content `scope`, `dwell_seconds` (5–3600) and `timezone`. Either `scope.post_ids` or `scope.category_ids` can match; both empty means unrestricted scope. Pages do not match article categories. Time zones cannot change once the ledger has any events. Activity switches do not block reversals/restorations of existing comment awards at their original amounts.

`milestones.view` and `milestones.like` are ordered lists of at most 20 tiers each, with stable `id`, actual `threshold` and `xp`. Removing and saving retires an identifier in `retired_milestones`; it cannot be reused. New tiers receive new identifiers. Legacy six-tier policies normalize in memory while preserving tier identity and prior claims, with no migration writes on reads.

Idempotency uses the article, kind and stable tier, not its editable count. Saving awards nothing. The next qualifying event evaluates unclaimed tiers against cumulative ledger counts, so a lowered threshold may qualify then. Removing and re-adding creates a new tier rather than restoring old claim state.

Likes require both `pagenest_companion_like` and `pagenest_companion_feature`, with the `likes` feature enabled. Companion only loads these functions after its profile validates. Without this provider, the settings fieldset and runtime like awards are disabled. Under the settings save lock, omitted or forged like fields are replaced with the stored values; other rules remain editable. No independent like service is included.

Level inputs submit an ordered array. The first value is read-only zero; add/remove controls reindex rows, with a maximum of 100. Server-side validation remains authoritative. Without JavaScript existing rows remain editable, while adding or removing rows requires JavaScript.

## Ledger and display integration

`reader_experience_balance` is the only running balance projection. The existing event ledger remains the source of changes; no cumulative or rank metadata is written. The default ten minimum balances are `0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000`, and levels are computed on demand using the currently configured thresholds. Metadata writes outside the ledger transaction are rejected, including ordinary add, update and delete calls.

The `site_tools_user_level` filter receives `($fallback, $user_id)` and returns `Level N` when ready, or the original fallback otherwise. It reads no rank posts and has no third-party provider dependency. The plugin preserves the configured weekly schedule and rotates game weekly scores using the same scoring lock as verified settlement.

## Management, growth and achievement storage

Management requires `manage_options`; writes also validate nonces, operation identity and target-user permissions. `manual_adjust` and `manual_reversal` append events with an actor and reason. Reversal only accepts an unreversed manual adjustment and cannot make a balance negative. Diagnostics compare ledger totals and projections without repairing either. Retry covers explicitly captured first article/comment attempts and full comment compensation failures, rechecking the canonical owner and current eligibility; it is not a historical award endpoint. `wp_xp_core_rule_audit` and `wp_xp_core_failed_events` each retain at most 200 entries, not a permanent complete audit trail.

`wp_xp_core_growth` stores level profiles and optional check-in stages. A new rule generation and event boundary limit stage rewards to subsequent check-ins; personal-page reads do not award XP. `wp_xp_core_achievements` stores versioned tasks, at most 50 including disabled tasks, with at most eight automatic steps. Once-only, daily, weekly and monthly periods bound progress; weeks start Monday and months start on the first. Display text/character icon edits preserve the version and event boundary; condition, period or activation changes create a new version. Automatic and reviewed tasks default to zero-XP honors and support optional completion XP. Earned achievements snapshot titles and icons rather than following later task edits.

Steps accept `kind`, `count`, `mode`, `post_ids` and `category_ids`. Legacy `kind/count` steps default to `events` and unrestricted content in memory, without saving. Modes are `events`, `objects` (distinct post/page; comments map to their parent content) and `days` (experience time zone). Check-ins reject content scope and `objects`. Each ID list holds at most 100 positive integers; either list may match. Content must remain published, a post/page and unprotected; comments must remain approved, belong to the event user, and exclude self-comments and replies to the same user.

Optional task `starts_on`/`ends_on` are valid `YYYY-MM-DD` dates or empty strings, inclusive in the experience time zone. Automatic completion, claims, submissions and approvals check these dates. `prerequisites` accepts up to eight `{id, scope}` objects referencing saved tasks. `current` requires the prerequisite's current version/current cycle honor; `lifetime` accepts an earned honor from any version. The save transaction rejects missing references, duplicates and cycles. Prerequisites gate completion/submission without changing this version's activity boundary. Reads never save and saving never backfills history.

Personal history, submissions and review status are isolated to the current user. Do not publicly cache authenticated personal responses. Extra check-in rewards start disabled; there is no cron, full-history scan or automatic backfill. Initial personal information is server-rendered, with JavaScript enhancing interaction and pagination.

`reward_xp` is an integer from 0–1000000, defaulting to 0. Changes create a new task version without paying old honors. `image_id` is 0 or a valid public image attachment ID; JPEG/PNG/GIF/WebP/AVIF are accepted, with a published, unprotected parent. Reads recheck visibility and fall back to the character. `category`/`series` allow 40/60 characters and are display metadata, preserving the version. Award events snapshot display metadata, conditions and actual XP; earned time comes from the original event.

Automatic/review completion uses `task_complete`; administrative grants use `task_grant`. Both share the `task:{id}:{version}:{period}:{user}` idempotency key and commit honor/XP in one transaction. `WP_XP_Core_Achievements::manage()` requires the management nonce, target-user permission, a reason and UUID request ID; grants also require the current task version/cycle, dates and prerequisites. Duplicate requests bind the normalized input digest, preventing target/amount changes. Rewards guard integer overflow; administrative XP is excluded from activity standings.

Revocation uses `task-revoke:{original event ID}`, appending `task_revoke`/`task_grant_revoke` with the original XP's inverse. Insufficient balance writes nothing. `achievement_admin` is a zero-XP request receipt outside source analysis. Reads, prerequisites, personal badges and completion rates consistently exclude revoked honors. Original keys remain consumed, preventing regrant in that version/cycle. Revoking a prerequisite never cascades into existing downstream honors. Legacy definitions without rewards and historic zero-XP honors remain readable/revocable without backfill or read-time writes.

## Caps, board participation and evaluation

`rules.activity_caps` is `{day: 0, week: 0}`, with integers 0–1000000000; 0 means unlimited. `WP_XP_Core_Fairness::FIRST_REWARD_KINDS` is separate from standings kinds and includes only gross actual positive first rewards: check-in, visit, article, comment, both milestones, streak, `task_complete` and `provider_reward`. Positive `comment_adjust` restoration, all reversals, manual events and imports are exempt and never consume capacity. `Reader_Experience::record()` computes gross totals under the same ledger lock/transaction, applies the smaller daily/weekly remainder, and snapshots requested/actual XP, limits, prior gross and truncation in `detail.activity_cap`. Zero-XP events remain idempotent without later backfill; existing events without cap metadata count their actual first-reward amount.

`record()` rejects negative resulting balances and integer overflow before INSERT; `set_balance()` rejects negative values. `reconcile_comment()` rechecks the original event, current comment/deletion, original actual XP and subtotal under the lock. Insufficient funds reject the whole compensation and capture a server-observed `comment_adjust` failure. Administrator retry verifies the canonical original owner and target permission before writing, then recomputes the full delta instead of replaying stored XP/keys. Compensation, failure resolution and management receipt commit together. Recovered approval with the original subtotal resolves as a noop; permanent deletions remain retryable. No partial compensation, negative balances, automatic collection or historic repair is introduced. Diagnostics treat absent balance metadata with a zero ledger total as consistent implicit zero, retaining `balance_present: false`; nonzero missing or duplicate projections remain errors. Reads never create a projection.

Personal board preferences use site-prefixed user meta `{prefix}wp_xp_core_public_board`, defaulting to participation. `POST /participation` reads; `POST /participation-settings` accepts only the current user's boolean `participating` and exact `revision`, retaining nonce, 4000-byte and private-cache requirements. Administrator option `wp_xp_core_board_exclusions` stores at most 1000 IDs with administrator nonce, target permission and revision checks. Public standings and personal position consistently exclude those accounts; `/position` adds `participation: active|opted_out|excluded`. Balances, private data and source totals remain intact.

Administrator `trends()` compares the preceding equal consecutive interval with daily net XP, without causal/retention claims. `anomalies()` returns at most 50 review clues for at least 20 first rewards per user/day or cap truncations, without penalties. `task_history()` returns at most 100 versions with observed grants, revocations and currently effective grants; `participants` stays null rather than inventing old denominators. New `version_created_at` snapshots define wall-clock time from definition creation to award; old events without evidence leave duration null.

Personal tasks batch valid/revoked honors and public targets within one call; prerequisites reuse only these local facts. Committed-event processing batches counters and updates local facts after awards, with no eligibility cache surviving its transaction. Single-task steps retain kind/id/date filters in WHERE for existing range indexes. Pending submissions apply JSON validity predicates before LIMIT so malformed historic rows cannot hide later valid items; original events are unchanged.

## Private inbox and goal interfaces

`notifications.php` derives a private inbox from trusted ledger events, with no independent reward writer or notification queue table. New events persist `notification_schema: 1` and `level_transition.before/after` in their original transaction. Upgrade messages require a strict increase; achievement/review messages use original event facts. Lost hooks or failed inbox queries can be recovered by rereading without affecting committed XP. Old events without snapshots are not backfilled. Reading neither saves nor awards anything.

The primary REST namespace and aliases expose `POST /notifications` and `POST /notification-settings`, requiring login, `read`, `X-WP-Nonce`, and `private, no-store`/`Vary: Cookie`. Both enforce the current user, reject target-user fields and limit bodies to 4000 bytes. Listing accepts `limit` (1–50) and optional `cursor` (`eventID:task|review|level`), preserving messages when one event has both an honor and a level transition. It returns `items`, `next_cursor`, observed `through` boundary, three boolean `preferences` and a `revision`.

Updates require the exact `revision` and either full `preferences: {task,review,level}`, up to 50 owned message `ids`, or a mark-all-read `through` boundary. These operations cannot be mixed. Read/preferences state uses the site-prefixed user meta key `{prefix}wp_xp_core_inbox`, reloaded under the ledger transaction lock with stale requests rejected as 409. At most 2000 individually read exceptions are retained beyond the read boundary; mark-all-read clears covered exceptions. The UI discards older responses and prevents stale reads replacing a pending mutation.

Personal task responses add public `scope_links`, count modes and prerequisite hints. Restricted content names/links are omitted; following a link does not qualify as activity by itself. All these views remain read-only.

## Leaderboards and insights interfaces

`[xp_leaderboard period="week" limit="10"]` is a separate, manually placed public entry point. `period` also accepts `month`; page sizes range from 1–50. The external `shortcodes` field does not rename it, and it creates no pages. Reporting uses the current experience time zone and ledger `local_day`, from Monday or month start through today, with positive net activity XP required. Ties use competition ranks such as 1, 1, 3, with user ID providing stable tie ordering without appearing in public rows. One SQL statement calculates the page, total and ranks within one database snapshot. Activity between offset pagination requests can still move results.

`WP_XP_Core_Insights::leaderboard()` accepts only `period`, `limit` (1–50) and `offset` (0–1,000,000). Public rows contain only `display_name`, `author_url`, `xp` and `rank`, accompanied by date, time-zone and pagination metadata. They contain no user ID, login name or private history. Deleted users and users no longer belonging to the current multisite site are excluded. Eligible kinds are `checkin`, `visit`, `article`, `comment`, `comment_adjust`, `milestone_view`, `milestone_like` and `streak_reward`, `task_complete` and `task_revoke`, including negative compensation. Rankings do not use balance projections.

Personal positions use `POST /position` in the primary `rest_namespace` and all `rest_aliases`, retaining the existing personal-interface permission checks. Requests require sign-in, `read` capability and a valid `X-WP-Nonce`. The JSON object accepts only an optional `period` field (`week` or `month`, default `week`), for example `{"period":"month"}`, with no target user ID or other fields and a body limit of 1,000 bytes. The server forces the current user. Response `position` has the public-row shape, or `null` when unranked. JavaScript enhances the growth panel using this endpoint. Reads generate no events; personal responses must not enter shared caches.

`WP_XP_Core_Insights::sources()` and `task_rates()` require `manage_options` and have no public statistics REST routes. Sources accept `week`, `month` or `custom`. Custom `from`/`to` values use `YYYY-MM-DD`, include both endpoints, span at most 366 days and cannot end after today. Automatic periods reject custom date fields. Sources include the activity kinds above plus separate `manual_adjust`, `manual_reversal`, `task_grant` and `task_grant_revoke` rows. Eligible zero-XP activity counts toward events and participants; total participants are deduplicated across sources. Deleted users’ historical events remain only in anonymized aggregates. Historical imports, reconciliation and administrative request receipts are excluded; zero-XP task honors still count toward events and participants.

Task rates read only enabled definitions, their current version and current cycle. The denominator is the distinct union of matching activity/review-submission users and completers. Exact task/version/cycle achievement events identify the numerator. A zero denominator returns `rate: null`; source date parameters do not affect task rates. User membership scope matches the public board. Statistics store no ranking metadata and create no tables, migrations, indexes, caches or cron jobs. They do not change balances, task progress or award behavior.

## Developer event contract

New events include `detail.rule_revision`. After the transaction commits and releases its event lock, `wp_xp_core_event_committed($event)` runs. If that event changed the user's calculated level, `wp_xp_core_level_changed($event)` also runs. Editing level settings creates no user events and does not broadcast a level hook for every user.

The event contains `id`, `key`, `user_id`, `kind`, `object_id`, `xp`, `day`, `detail`, `before_xp`, `after_xp`, `before_level` and `after_level`. Values describe that event's change; with several events in one transaction they need not equal a later balance read. Duplicates and rolled-back transactions dispatch neither hook.

Observer exceptions are logged without rolling back committed rewards or reporting a successful award as failed; dispatch continues for subsequent events. Native WordPress `do_action` means later listeners on the same hook invocation may not run after an earlier listener throws. Listeners should handle exceptions and remain idempotent. Do not use notifications for mandatory atomic awards: native derived rewards, such as check-in stages, run in the source transaction; writes from external listeners are separate transactions.

## Compatibility and upgrades

Version 1.4.0 adds native first-install setup. Sites with valid external configuration retain it during updates, with no table creation, recalculation or native initialization.

When upgrading from versions before 1.1.0, migrate existing balances exactly to `reader_experience_balance` and remove `rank_option` from the external experience profile. This release does not perform that migration. Storage, route aliases and shortcode aliases continue to be configured externally; keep historic aliases needed by existing content. Invalid retired profile fields keep the policy disabled.

WP XP Core uses the new plugin basename `wp-xp-core/wp-xp-core.php`. The rename requires switching the active plugin entry, with the old entry disabled before the new one is loaded. Switch any server early-loading basename reference at the same time. Keep the existing profile, ledger, balance metadata, options and scheduled hook unchanged. No data migration or initialization runs as part of this rename. The retained `Reader_Experience` class, `reader_experience_*` functions, default REST namespace and shortcode, CSS selectors, JavaScript global and asset handles are compatibility interfaces rather than package names.

Upgrading from 1.1.1 requires no data migration. Complete older custom policies gain default milestone counts in memory while retaining rewards and levels; loading does not save the option. The plugin’s `Update URI` identifies the repository with optional official checks defaulting to off; enable them explicitly in Configuration tools.

## Optional legacy integration

The configured `weekly_hook` and `weekly_lock` retain the existing shared weekly game-score reset integration for deployments that use it. This compatibility code is unchanged; it does not award experience and is not part of the settings UI. Configure the integration only for a site with the corresponding score metadata and scheduler.

## Portable configuration and blocks

`WP_XP_Core_Configuration::export($nonce)` exports only `schema:1`, `rules`, `growth` and `tasks`. The nonce action is `wp_xp_core_configuration`; `manage_options` is required. `preview($json, $mode, $nonce)` accepts at most 4 MiB (`MAX_BYTES`)/50 tasks, rejects unknown fields and internal versions, boundaries, generations, installation state or storage routing. Mode `update` matches same-site saved IDs; `template` remaps all foreign tasks and prerequisites. Unlisted local tasks remain. A local, nonexported map retains up to 1000 identities so repeated templates preserve claims. Template milestones matching existing thresholds retain local identities; retired IDs remain invalid.

Preview returns old/new field differences, task changes, all relevant revisions and an HMAC proof bound to the administrator, document, mode and plugin version. `apply($json, $mode, $revision, $proof, $nonce)` acquires the rules lock then the existing ledger lock, rechecks permissions and every revision, validates the dependency graph and future boundaries, then commits rules, growth, tasks, mappings and audit in one InnoDB transaction. Any failure rolls everything back. Callers cannot import rewards/events, balances, table names, REST routes, administrator identities or historical versions. Templates require explicit application and default to zero XP. Import never recalculates history or edits earned snapshots.

Dynamic blocks `wp-xp-core/growth` and `wp-xp-core/leaderboard` reuse `reader_experience` and `xp_leaderboard`; editor placeholders do not request private data. Shortcode compatibility remains.

## Trusted activity providers

Trusted server-side PHP plugins register a provider using `wp_xp_core_register_activity_provider('course', ['label' => 'Course completion', 'authorize' => $callback])`. The callback receives `(int $uid, int $object_id, array $context)` and must verify its own authoritative completion, ownership and eligibility, returning strictly `true`. Deliver verified activity with `wp_xp_core_record_activity('course', 'completion-123', $uid, $course_id, [])`; retain a stable source event ID for retry. There is no recipient/reward submission REST route.

Declarations contain only label and authorization callback, never reward XP. Administrators explicitly enable providers and save XP from 0–1000000 under XP rules; providers default to off. `rules.providers` maps at most 20 IDs to `{enabled, xp}`. Authorization runs under the ledger lock. Context is not stored. Fixed kind `provider_reward` uses a hash of the provider/event identity; reassigning an ID to a different user or object fails. Failed transactions can be retried. This first-reward source obeys daily/weekly gross caps and participates in source analysis, weekly/monthly activity standings and private history. It is not a task step and cannot trigger task reward loops. Ledger detail includes provider ID/label and standard reward/cap snapshots only.

## Translation, privacy and update interfaces

Text domain `wp-xp-core` uses packaged `languages/` catalogs for `zh_CN` and `en_US`. `wp_xp_core_t()` calls standard gettext for fixed strings. `tools/languages.py` compiles reviewed PO files into MO and JSON offline; `--check` verifies generated files. The small `WPXPI18n` JavaScript bridge translates fixed strings with positional placeholders, never user text or entire HTML output. Languages and `modules/` are included in packaging, independent release verification and real WordPress lab installation.

WordPress exporter `wp-xp-core` returns site-local facts for the email recipient: at most 50 events per page plus one sentinel, own balance/standings and notification state. Whitelisted fields omit administrator reason/actor and other recipients. No automatic eraser, cron or read-time writes exist. Deactivation and file deletion preserve data; see the retention policy in the guide. Anonymization or ledger removal requires a separate accounting design.

`wp_xp_core_updates` stores independent update preferences, default off, with administrator nonce/permission/revision checks; portable configuration does not import them. Checks use the fixed GitHub API’s latest 30 releases, at most 256 KiB, a five-second timeout and no redirects. Success caches for six hours; failures for fifteen minutes. Only correctly named official installation ZIPs from the fixed tag/path and at most 32 MiB are accepted. Stable excludes prereleases; the prerelease channel accepts newer stable or candidate versions. `version_compare` prevents downgrades. Turning checks off ignores stale update entries. No additional scheduled task is created; WordPress permissions and upgrade controls still govern installation.

The optional updater is unavailable on multisite networks. Network administrators update the plugin manually; subsite preferences cannot affect network-shared update results.

After an update-channel change, cached candidates are immediately checked against the current channel, version and official asset URL; other plugins remain untouched. Initial and duplicate provider deliveries return the same ledger `event_id`, never a balance projection metadata ID.

## Database requirements and read failures

Use InnoDB for transactional storage and a database providing `JSON_VALID`, `JSON_EXTRACT`, `JSON_UNQUOTE` and `JSON_TYPE`. Activation checks those functions before installation writes. If a check fails, ask the server administrator to provide a compatible database and retry activation; no tables are converted or adopted.

When a claim, daily quota, milestone, original comment receipt or response history cannot be read, the reward operation stops and rolls back. Restore database access before retrying the original action; do not edit the balance or replay initialization. Personal-data exports include review decisions, feedback and the associated submission ID, without internal administrator identities or reasons.

## Activity reversals and private notification sources

Activity-provider definitions may add an optional `revoke_authorize($user_id, $object_id, $context): bool`. The original `authorize` still controls new rewards. Call `wp_xp_core_revoke_activity($provider, $original_event, $user_id, $object_id, $context)` to append `provider_revoke` under the ledger transaction lock. It verifies provider and original ownership and reverses original actual XP, independent of the current reward policy. Its `object_id` references the original ledger event. A fixed key makes reversals idempotent. Insufficient balance returns `balance` (409) without a partial debit; database failure returns `storage` (503). Zero-XP receipts can also be reversed. Callers must provide trusted server context and must not forward browser-selected identities or amounts. A reversal does not re-enable the original reward.

The bbPress adapter uses `bbp_new_topic`, `bbp_insert_topic`, `bbp_new_reply` and `bbp_insert_reply`, requiring an actual enabled-time insertion in the same request. Replaying a historical creation hook does not reward it. Only directly changed topics/replies are reversed; parent changes never scan descendants. Capability detection after `plugins_loaded` avoids plugin file-order dependencies.

Register an optional inbox with `wp_xp_core_register_notification_source($id, $definition)`. The definition contains a string `label`, `available(int $uid): bool`, `listing(int $uid, string $cursor, int $limit)` and `mark(int $uid, string $id)` callbacks. XP validates login, `read` capability and REST nonce before supplying the current session user; requests cannot specify a user ID. At most ten sources may register with IDs matching `[a-z][a-z0-9_]{0,31}`; `xp` is reserved. Listing returns `items` (at most limit) and `next_cursor` (string or null). Each item has string `id/title/message/day` and boolean `read`. Cursors and IDs are at most 191 bytes; limit is an integer from 1 to 50. Sources must recheck ownership and visibility on each operation, bound their queries, omit secrets/admin reasons, and return `WP_Error` on failure. Mark callbacks validate original IDs and ownership. No cross-source transaction or combined cursor is provided.

Private POST actions are `notification-sources` (no fields), `notification-source-list` (source, optional cursor/limit) and `notification-source-read` (source/id), using the existing REST namespace and private-response policy. Companion appears only when its public projection/read functions are present and notifications are enabled. The adapter calls those functions instead of changing Companion REST output or writing its storage.

Reward holds use user metadata and the existing bounded administrative audit. Configuration import/export does not copy them. Persisted `reward_hold` event details contain only requested_xp, until and held; personal exports include this receipt without administrative reasons. No new table, index, cron, automatic sampling, historical recalculation or third-party request is introduced.

Items may include an optional `url` of at most 2048 bytes, restricted to HTTP/HTTPS. Sources must generate it only after checking current visibility.

The bbPress adapter resolves the original recipient under the ledger lock with `WP_XP_Core_Providers::revoke_registered_receipt()`. Author reassignment does not transfer XP or prevent reversal; trusted server deletion requires no logged-in actor.
