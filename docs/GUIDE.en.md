# User guide

[简体中文](GUIDE.md) · English · [Back to overview](../README.en.md)

This administrator guide describes the **2.0.0-rc.2 prerelease**. For released versions, use the guide at the matching Release tag. From 1.4.0, new sites can install and activate with default rules, without server JSON configuration. See [Get started](../README.en.md#get-started) for installation.

## Create an experience page

An experience page is a normal WordPress page displaying the currently signed-in user’s experience. You do not need an existing account page or account plugin.

1. Open **Pages → Add New Page**, and enter **My experience** as the title, or choose another title.
2. Click the editor’s **+** button, search for **Shortcode**, and add that block.
3. Paste the following into its input:

   ```text
   [reader_experience]
   ```

4. Click **Publish** and confirm. Use a public page without password protection. Anonymous visitors see a login link, not another user’s experience history.
5. Open **Settings → WP XP Core → 添加经验面板** (Add experience panel), select the published page and click **保存面板页面** (Save panel page). This only selects a link destination; it does not create or publish a page.
6. Open that page and sign in to view XP, level, progress, check-in and recent records. The admin bar’s experience link and the login return link also point there.

You can add the page to your site’s navigation using WordPress’s menu/navigation editor. Without a selected page, links use the current page or home. Advanced external integrations retain their configured panel page and shortcode; see [Optional external configuration](CONFIGURATION.en.md#optional-external-configuration).

## Set experience rules

Open **Settings → WP XP Core**, or select **Settings** on the plugin row. Saving requires administrator capability `manage_options`.

### Daily activities

Enable or disable check-in, valid reading, publication and comments and set their XP, plus reading and comment daily reward limits. Rewards accept integers **0–1,000,000**; daily limits accept **0–1,000**.

- **Reward set to 0:** eligible events are still recorded, preventing repeat claims after a rule change.
- **Daily limit set to 0:** rewards for that category stop.
- **Check-in:** once per account per day.
- **Valid reading:** signed-in users must spend the configured dwell time (15 seconds by default) on an accessible page. Reading rewards are limited to once per account, page and day, subject to the configured daily limit.
- **Publication:** each public `post` article earns its reward once. Editing or republishing does not award it again.
- **Comments:** require a signed-in account and an approved comment on a public article or page. The commenter must not be the article’s author or replying to their own comment. Losing eligibility reverses the original reward; becoming eligible again restores the original amount.

Dwell time accepts **5–3600 seconds**. Days default to **Asia/Shanghai (UTC+8)**; the time zone can change only while the ledger contains no events.

Content scope accepts article/page IDs and category IDs. Empty lists include all content; otherwise either match is sufficient. Scope applies to reading, publication, comments and author milestones. Pages do not match article categories, and scope never grants access to restricted content. Disabling an activity stops new awards; existing comment reversals/restorations retain the original amount.

### Optional activity XP caps

Daily and weekly activity caps default to **0 (unlimited)** and accept 0–1000000000. They follow the experience time zone; weeks start Monday, and simultaneous caps use the smaller remaining allowance. Check-ins, reads, article publication, first comment rewards, author milestones, check-in stages and automatic/review task rewards share gross positive first-reward XP.

If the remaining allowance is smaller, only that amount is issued; reaching the cap still records a zero-XP event and consumes the original claim, without later backfill. Reversals do not reopen capacity. Restoring a comment's original actual reward is compensation, exempt from the cap and never counted twice. Manual adjustments/grants/reversals and historical imports are excluded. Enabling caps counts earlier first rewards in the same period without reclaiming them. Ledger detail records requested/actual XP, cap limits and truncation facts.

### Author milestones

Views and likes default to three tiers each, with **0–20 configurable tiers per group**. Edit the count and XP for each tier. Counts within a group must strictly increase from **1 to 1,000,000,000**.

View milestones count valid views recorded in the experience ledger: signed-in users reading public articles they did not author, once per user and article per day. They are not equivalent to all page views in analytics tools. Like milestones also count ledger events, rather than directly using the external provider’s total.

Each article claims each tier once. Removing and saving permanently retires its identifier; adding another creates a new tier rather than restoring the old one. Changing thresholds does not reset claimed tiers, and saving awards nothing. For example, lower tier one from 100 to 50: an article with 80 ledger views that has never claimed that tier qualifies on its next valid view.

Likes require **PageNest Companion** with an available like interface and its likes feature enabled. Otherwise the group is disabled with an explanation. Saving other settings preserves existing like rules.

### Level progression

Enter minimum experience as rows, adding or removing levels as needed. You can configure **1–100 levels**. Level one is fixed at **0**; subsequent thresholds must strictly increase and be at most **1,000,000,000**. Adding or removing rows requires JavaScript; existing rows remain editable without it.

For thresholds `0, 10, 30`, a user with 25 XP is Level 2 and needs 5 more XP for Level 3. Change the third threshold to 20 and that user immediately displays Level 3, while their balance stays at 25.

### Save and apply

Click **保存经验规则** (Save experience rules). Invalid input preserves previous settings. If another administrator has saved an update, reopen the page before editing again so an older form cannot overwrite newer rules.

Reward changes apply only to events first recorded afterward. Historical experience is not recalculated or reissued; comment reversals and restorations use the original amount. Level changes affect display immediately. Sites without saved rules use defaults. Invalid saved rules trigger an administrator notice and fall back to defaults. Opening the page does not create or save settings.

## Query and adjust experience

1. Open **事件账本** (Event ledger) from plugin navigation. Filter by user ID, event source and date, or search by username/display name and review the user's balance, level and events.
2. Enter the user ID, signed XP change and reason. A single adjustment accepts a nonzero integer from -1,000,000 to 1,000,000; deductions cannot exceed the balance. The operation appends an event with its administrator and reason.
3. To undo a mistake, provide the original manual-adjustment event ID and a reversal reason. Each manual adjustment can be reversed once. Reversal appends an opposite event; it never deletes the original or allows a negative balance.

Exports cover the current filter and page, not the entire ledger automatically. They include user IDs, activity sources, XP changes and dates, without management reasons; handle them as private site data.

**运行诊断** (Diagnostics) compares ledger totals to balances page by page without repairing them. Controlled retry covers explicitly captured first article/comment award failures and full comment compensation failures. Supply a reason; eligibility and limits are checked again. It does not scan old posts or comments, and an old failure may no longer qualify. Configuration audit history helps review rule changes.

## Configure growth

Open **成长中心** (Growth center) to set level names, character icons, colors and descriptions. Thresholds remain under Experience rules; profiles affect display only. Keep the existing shortcode and page. Each signed-in user sees their progress, the last 35 days of check-ins, consecutive/cumulative counts and filtered, paginated private history. Anonymous visitors cannot access personal records.

Extra check-in stage rewards start disabled. Choose consecutive or cumulative counting, add day thresholds and rewards, then save. Enabling or changing stage rules starts counting with the next new check-in, without historical awards. Each tier can be claimed once per rule generation. Breaking a streak resets its count but does not deduct earned XP. Check-in and any triggered stage awards commit together. Viewing the page or calendar awards nothing.

## Create tasks and achievements

Open **任务与成就** (Tasks and achievements), enter a title, description and character icon, then choose activation and a once-only, daily, weekly or monthly period. Periods follow the experience time zone; weeks start Monday and months start on the first. At most **50 tasks including disabled tasks** can be stored.

- **Automatic tasks:** define 1–8 steps using check-ins, reading, comments or publication, each targeting 1–1,000 events. All steps must be satisfied. Progress counts qualifying new ledger activity, not raw page views.
- **Reviewed tasks:** define no automatic steps. Users submit an explanation on their personal page; administrators approve or return it from the pending queue. Rejection requires feedback. Each user can submit at most five attempts per task/version/period, with only one pending at a time.

Each step can count events, distinct content or distinct local days. Comments count their parent post/page for distinct content. Check-ins support events or days. Optional post/page IDs and category IDs match either list; empty lists mean unrestricted. Pages do not match post categories. Content must still be published and unprotected; task scopes never grant access to private content.

Optional start/end dates include both boundary days in the experience time zone. Tasks outside their dates cannot complete, be claimed, accept submissions or be approved. Up to eight prerequisites must all be earned: choose the prerequisite's current version/current cycle, or lifetime honors from any version. Missing tasks and cycles are rejected. Prerequisites gate completion and submission; matching activity still accumulates from this task version's event boundary within its dates.

Tasks award honors by default. Set an optional completion reward of 0–1000000 XP; 0 keeps the honor free of XP. Automatic completion and review approval use the version's reward exactly once. Changing the reward creates a new version and never pays existing honors retroactively. Editing a title, description, character icon, badge image, category or series preserves the version and progress. Changing completion type, steps, period, dates, prerequisites or enabled state starts a new version using only subsequent events, without carrying old progress forward or backfilling history. Earned badges retain their awarded text and icon.

Select a public image from the media library or use a character icon. Deleted/unavailable images or attachments with private parents fall back to the character. Personal badges preserve the awarded title, icon, image reference, category, series, condition text and actual XP, and display the earned time. Later display edits do not rewrite these snapshots.

Under **人工授予与撤销** (Manual grants and revocation), provide a user ID, current task and reason for independently verified completion. Grants still check the task version, cycle, dates, prerequisites and target-user permissions. Administrative XP uses a separate source and is excluded from activity standings. Each user/task/version/cycle can be granted only once across all completion methods.

To revoke, find the original completion/grant event ID in the ledger and enter a reason. Revocation appends the original XP's inverse; insufficient balance leaves both XP and honor unchanged. Zero-XP honors can also be revoked. Revoked honors disappear from earned badges, stop satisfying prerequisites and cannot be granted again in the same version/cycle. Revoking a prerequisite blocks future completions without cascading into existing downstream honors. Original ledger events are retained.

Personal history filters include task completion rewards, task reward revocations, administrator grants and their revocations. Automatic/review rewards and their reversals enter activity standings; administrative grants and reversals appear separately only in source analysis.

## Read private notifications and next goals

The personal panel's **站内通知** (Site notifications) shows new achievement grants/revocations, review approvals/returns and genuine level increases. Ordinary reading XP does not produce a notification per event, and changing level settings does not create upgrade messages. Only the signed-in recipient sees the inbox. No email or cron job is created. Use **刷新通知** (Refresh) to retry, mark individual messages read, or mark everything through the observed boundary read. Notification preferences independently control achievements, reviews and levels; reenabling a type preserves its messages and read state.

Notifications recover from saved ledger facts. An inbox failure cannot roll back successful rewards. Reopening the panel neither awards XP/honors nor duplicates messages. Level messages use the event's original before/after levels, unaffected by later threshold edits. Older events without notification snapshots are not backfilled.

The task list explains the next goal, remaining count, distinct content/local-day modes, accessible target content/category links, and prerequisite names, completion and version/cycle scope. Private, protected and unpublished content names/links stay hidden. Following a target link does not bypass activity eligibility or immediately complete the task.

## Place weekly and monthly boards

Create a normal page, add a Shortcode block, and manually publish either shortcode as needed:

```text
[xp_leaderboard period="week" limit="10"]
[xp_leaderboard period="month" limit="10"]
```

`period` accepts `week` (default) or `month`. `limit` is the page size, defaulting to 10 with a range of 1–50. The plugin does not create or publish leaderboard pages. Anonymous visitors can view boards, which show only rank, display name, public author link and net XP for the period.

Weeks run from Monday through today and months from the first day through today, using the current experience time zone shown with the date range. Scores include check-in, reading, publication, comments, author milestones and check-in stage rewards. Reversals and restorations affect the net total according to their ledger dates. Historical imports, balance reconciliation, manual adjustments and zero-XP honors are excluded. Only existing users with positive net activity XP appear; deleted users and users who left the current multisite site are excluded. Changing weeks or months changes the reporting range without resetting balances.

Ties share competition ranks, such as 1, 1, 3. Results are live: new activity between pagination requests may change ranks and which users appear on a page. JavaScript enhances the signed-in growth panel with the current user’s weekly/monthly position. Users without positive qualifying net XP have no position. This lookup is restricted to the current account.

Uncheck **参与公开活动榜** (Participate in public standings) on the personal panel to opt out. Private XP, notifications and tasks are retained. Administrators can exclude test accounts by user ID on the insights page. Personal position explicitly reports nonparticipation; administrator re-inclusion preserves the user's own opt-out choice.

## Review incentive insights

Open **Settings → 激励效果** (Incentive insights), or use plugin navigation. Access requires `manage_options`.

**Experience sources** supports this week, this month or custom dates. Custom ranges include both endpoints, span at most 366 days and cannot end after today in the experience time zone. Each source shows positive XP, negative XP, net XP, event count and distinct participants. Eligible zero-XP activity counts toward events and participants. The total participant count is deduplicated across sources, so source counts cannot be added together.

Manual adjustments and their reversals have separate source rows and are included in totals, unlike the public activity boards. Historical imports, reconciliation and zero-XP honors are excluded. Historical events for deleted users remain in anonymized aggregates without individual details.

**Current task completion rates** cover only enabled tasks, their current version and current cycle, independently of the source date filter above. The denominator is the distinct union of users with matching activity (automatic tasks) or review submissions (reviewed tasks), plus users who earned the achievement. The numerator is distinct users who earned that version’s achievement in that cycle. This is neither an impression conversion rate nor the completion share of all registered users. With no participants, the display reads “尚无参与” (No participants), rather than 0%. Editing a task creates a new version; older participation and completion records are excluded from that version.

Boards, personal positions and insights are read-only. Viewing them awards no XP, resets no tasks and triggers no backfill, statistics tables or cron jobs.

Insights compare the selected range with the immediately preceding equal number of days, providing description rather than causal attribution. Activity clues list accounts with at least 20 first-reward events in a day or cap truncations, solely for human review, without automatic penalties.

Task-version history reports grants, revocations in the selected range, and how many of those grants remain effective. Unknown historic definitions never receive an invented participation denominator or retention measure. New grant snapshots record the version definition's creation time as the duration origin; this includes waiting/nonparticipation, not active effort. Old events without that evidence leave duration empty. The display is bounded to 100 recent versions.

Comment compensation always uses the original actual award. Insufficient balance leaves both ledger and projection unchanged and captures the server-observed failure. An administrator's reasoned retry rechecks the canonical original owner, current eligibility or permanent deletion, and the current subtotal, then applies the full delta. If approval has recovered while the original subtotal remains, retry resolves without another credit. No partial deductions, debt or history scans occur. The existing failure list retains at most 200 entries and is not a permanent audit log.

## Frequently asked questions

### Why does the panel say the system is under maintenance?

On a new site, first confirm successful plugin activation. If activation fails, follow its message to check database permissions and InnoDB support; the plugin does not convert other WordPress tables. Deactivation and reactivation preserve the ledger and settings rather than resetting XP.

For an advanced external integration, follow the [integration checklist](CONFIGURATION.en.md#integration-checklist) to check its profile and live switch. Invalid configuration does not silently fall back to a new empty ledger.

### Why did reading or commenting award no XP?

Check sign-in, zero rewards, daily limits, prior claims and the eligibility conditions above. Reading also requires the configured dwell time (15 seconds by default). Repeated requests do not award XP again; view counts and rewarded visits are different measures.

### Why are like settings disabled?

PageNest Companion’s like interface is unavailable, or its likes feature is off. Enable and configure that plugin, then reopen settings. WP XP Core does not include a like service.

### Why do old records keep their original amounts?

Existing events retain the amount recorded at the time. New rules apply only to events first recorded afterward. Raising a zero reward does not reissue a recorded event.

### Can I manually change a user's experience?

Yes. Open the event ledger, find the user and submit a signed adjustment with a reason. Follow the management steps above rather than editing balance metadata directly.

### How do I update?

Download the installation ZIP from [Releases](https://github.com/wzf2000/wp-xp-core/releases/latest), back up your site, and upload it through WordPress to replace the current version. Official GitHub update checks are optional and default to off; enable them explicitly in Configuration tools. Older Reader Experience installations also need the directory and early-loading changes described under [Compatibility and upgrades](CONFIGURATION.en.md#compatibility-and-upgrades).

### How do I report a problem?

Use [Issues](https://github.com/wzf2000/wp-xp-core/issues/new/choose) with plugin, WordPress and PHP versions, reproduction steps and expected/actual behavior. Remove account records, verification tokens and private server configuration from screenshots and logs. For security problems, use the [private reporting channel](../SECURITY.md).

## Configuration tools, templates and updates

Open **Settings → Configuration tools**. Export produces copyable JSON containing rules, growth settings and tasks only. It excludes users, ledger, balances, administrator audits, notification preferences, storage routing and installation state. Export is read-only. Review site-specific media, post and category IDs before moving configuration.

Choose an import mode and **Preview configuration** first. Same-site updates match existing task IDs; external templates remap tasks and prerequisites. Preview shows old/new rule and growth values, milestone changes, and tasks that are new, display-only modifications, new versions or preserved. **Apply previewed configuration** commits all changes together; any intervening relevant revision change rejects the entire application. Unlisted tasks remain. Reusing a template preserves earned claims and never backfills rewards. External milestones matching an existing threshold retain its local identity; retired identities cannot be reused. Unknown tasks receive local IDs; external versions, event boundaries and generations are rejected.

**Task templates** include Reading starter and Weekly participation, with zero-XP honors by default. Selecting a template only previews it; explicit application is required. Edit its presentation or conditions afterward in Tasks and achievements.

**GitHub update checks** default to off. Opt into stable releases or stable plus prereleases. Only the official `wzf2000/wp-xp-core` Release attachment `wp-xp-core-{version}.zip` is accepted, never GitHub Source code archives or arbitrary URLs. Version comparison prevents downgrading a current RC to an older stable release. Successful responses cache for at most six hours; failures for fifteen minutes. No cron job is added. Turning checks off ignores stale plugin update entries and performs no publication or deployment.

## Blocks, language and navigation

The editor’s **Widgets** category offers **My growth** and **XP activity standings** dynamic blocks. Both reuse existing shortcodes, which remain supported. The editor shows explanatory placeholders without loading private user data. The front-end panel uses the signed-in viewer; public standings respect opt-outs and exclusions.

Fixed product strings support Simplified Chinese and English through WordPress site/user locale and the standard `wp-xp-core` gettext catalogs. User names, task names/descriptions, submitted notes and historical snapshots remain verbatim. A small browser translation map avoids adding a translation library to reading pages.

The personal panel initially shows three actionable tasks. **Show remaining tasks (N)** reveals the rest; **View task** expands and focuses its target. Data refresh preserves the current page’s expansion state. Recent achievements likewise start with three entries; expansion shows only the remaining recent API records, not the complete history. Native buttons and labels, live status text, visible keyboard focus and `aria-expanded` support keyboard navigation.

## Personal data and retention

Administrators use **Tools → Export Personal Data**, with WordPress’s email verification flow, to export the recipient’s site-local XP events, task notes/achievement snapshots, balance, standings status and private notification preferences/read state. Each page reads at most fifty events. Other recipients, internal administrator reasons and unrestricted audit detail are excluded. Reads do not award XP or create notifications.

Deactivation, updates and deleting plugin files retain the ledger, balances, tasks, settings and notification state. Reinstallation does not clear accounts. There is no automatic expiry or one-click personal-ledger eraser. Site operators must document purpose and retention and respond to erasure requests through their own reviewed policy. Direct deletion or promised anonymization would require an accounting and identity design first. Export support does not claim comprehensive privacy compliance certification.

The optional updater is unavailable on multisite networks. Network administrators update the plugin manually; subsite preferences cannot affect network-shared update results.

After an update-channel change, cached candidates are immediately checked against the current channel, version and official asset URL; other plugins remain untouched. Initial and duplicate provider deliveries return the same ledger `event_id`, never a balance projection metadata ID.
