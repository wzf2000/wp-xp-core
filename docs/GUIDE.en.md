# User guide

[简体中文](GUIDE.md) · English · [Back to overview](../README.en.md)

This administrator guide describes the **2.0.0-rc.1 prerelease**. For released versions, use the guide at the matching Release tag. From 1.4.0, new sites can install and activate with default rules, without server JSON configuration. See [Get started](../README.en.md#get-started) for installation.

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

**运行诊断** (Diagnostics) compares ledger totals to balances page by page without repairing them. Controlled retry covers only explicitly recorded failures of first article/comment awards. Supply a reason; eligibility and limits are checked again. It does not scan old posts or comments, and an old failure may no longer qualify. Configuration audit history helps review rule changes.

## Configure growth

Open **成长中心** (Growth center) to set level names, character icons, colors and descriptions. Thresholds remain under Experience rules; profiles affect display only. Keep the existing shortcode and page. Each signed-in user sees their progress, the last 35 days of check-ins, consecutive/cumulative counts and filtered, paginated private history. Anonymous visitors cannot access personal records.

Extra check-in stage rewards start disabled. Choose consecutive or cumulative counting, add day thresholds and rewards, then save. Enabling or changing stage rules starts counting with the next new check-in, without historical awards. Each tier can be claimed once per rule generation. Breaking a streak resets its count but does not deduct earned XP. Check-in and any triggered stage awards commit together. Viewing the page or calendar awards nothing.

## Create tasks and achievements

Open **任务与成就** (Tasks and achievements), enter a title, description and character icon, then choose activation and a once-only, daily or weekly period. Periods follow the experience time zone; weeks start Monday. At most **50 tasks including disabled tasks** can be stored.

- **Automatic tasks:** define 1–8 steps using check-ins, reading, comments or publication, each targeting 1–1,000 events. All steps must be satisfied. Progress counts qualifying new ledger activity, not raw page views.
- **Reviewed tasks:** define no automatic steps. Users submit an explanation on their personal page; administrators approve or return it from the pending queue. Rejection requires feedback. Each user can submit at most five attempts per task/version/period, with only one pending at a time.

Achievements are honors and award no extra XP. Editing a task creates a new version whose progress counts only subsequent events, without carrying old progress forward or backfilling history. Earned badges retain their awarded text and icon; editing or disabling a task does not erase them. Finalize task content and periods before inviting users, since frequent edits restart current progress.

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

Download the installation ZIP from [Releases](https://github.com/wzf2000/wp-xp-core/releases/latest), back up your site, and upload it through WordPress to replace the current version. No automatic updater is included. Older Reader Experience installations also need the directory and early-loading changes described under [Compatibility and upgrades](CONFIGURATION.en.md#compatibility-and-upgrades).

### How do I report a problem?

Use [Issues](https://github.com/wzf2000/wp-xp-core/issues/new/choose) with plugin, WordPress and PHP versions, reproduction steps and expected/actual behavior. Remove account records, verification tokens and private server configuration from screenshots and logs. For security problems, use the [private reporting channel](../SECURITY.md).
