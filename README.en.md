<!-- wp-xp-core-version: 2.2.0-rc.1 -->

<p align="center"><img src="docs/assets/hero.svg" alt="WP XP Core — Experience for participation, levels for progress" width="100%"></p>

<p align="center">
  <a href="https://github.com/wzf2000/wp-xp-core/releases/latest"><img alt="GitHub Release" src="https://img.shields.io/github/v/release/wzf2000/wp-xp-core?color=4f46e5"></a>
  <a href="https://github.com/wzf2000/wp-xp-core/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/wzf2000/wp-xp-core/actions/workflows/ci.yml/badge.svg"></a>
  <img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b">
  <img alt="PHP 8.0+" src="https://img.shields.io/badge/PHP-8.0%2B-777bb4">
  <a href="LICENSE"><img alt="License: GPL-2.0-or-later" src="https://img.shields.io/badge/license-GPL--2.0--or--later-22c55e"></a>
</p>

<p align="center"><strong>Reward participation with experience. Show progress through configurable levels.</strong></p>
<p align="center"><a href="README.md">简体中文</a> · English</p>
<p align="center"><a href="#get-started">Get started</a> · <a href="docs/GUIDE.en.md">User guide</a> · <a href="docs/CONFIGURATION.en.md">Server configuration</a> · <a href="CHANGELOG.md">Changelog</a></p>

## What you can do

- **Reward everyday participation.** Set rewards for daily check-in, valid reading, article publication and eligible comments.
- **Encourage authors.** Add or remove view milestones per article, plus optional like milestones with PageNest Companion.
- **Create your growth curve.** Add or remove rows to configure minimum experience for 1–100 levels, without editing code.
- **Show personal progress.** The experience panel displays experience, level, experience needed for the next level, check-in and recent private history.
- **Keep an award trail.** Experience changes are recorded in an event ledger; repeated requests cannot claim the same reward twice.
- **Connect optional activities.** Select a trusted source in the dashboard and configure XP, daily limits, minimum scores and private messages. Core XP keeps working without companion plugins.

The plugin manages experience and levels independently of myCRED. Likes are an optional integration. When the like service is unavailable, its rules are disabled while other settings remain editable.

## Get started

This branch documents **2.2.0-rc.1**. Download the version actually published on [Releases](https://github.com/wzf2000/wp-xp-core/releases).

Back up existing sites and verify updates in an isolated environment first; use the documentation at your Release tag for version-specific behavior. On a new site, installing and activating creates the experience ledger and uses default rules. No server JSON profile or other experience plugin is required. Existing users start at zero XP; old articles and historical comments do not trigger automatic backfill.

1. Download `wp-xp-core-VERSION.zip` from [Releases](https://github.com/wzf2000/wp-xp-core/releases), rather than GitHub’s automatically generated **Source code** archives. Automatic first-install setup requires **1.4.0 or later**; use the release documentation for older versions.
2. Install and activate the ZIP through **Plugins → Add New Plugin → Upload Plugin** in WordPress.
3. Open **Settings → WP XP Core** to review or customize rewards and levels. Defaults work immediately.
4. Create a normal page titled “My experience”, add a **Shortcode** block containing `[reader_experience]`, and publish. See [Create an experience page](docs/GUIDE.en.md#create-an-experience-page).
5. Back in plugin settings, select that page under **添加经验面板** (Add experience panel) and click **保存面板页面** (Save panel page). Signed-in users can view XP, check in and read their recent history there.

“My experience” is an ordinary WordPress page; no account plugin is required. Experience content belongs to the currently signed-in user. Activation does not create or publish a page.

Existing sites can update with a new Release ZIP. Deactivating and reactivating preserves experience and settings. Optional official GitHub update checks default to off; enable them explicitly in Configuration tools. External server configuration is an optional [advanced integration](docs/CONFIGURATION.en.md); ordinary first-time installation needs no old-plugin migration work.

## Configure your experience rules

The settings page groups **daily activities, author milestones and level progression**. Configure activity switches, content scope, reading dwell time, rewards and daily limits, and add or remove milestone tiers and levels. The daily time zone can change only before the ledger has any events.

| Activity                          | Default rule                                               |
| --------------------------------- | ---------------------------------------------------------- |
| Daily check-in                    | +2, once per day                                           |
| Valid reading                     | At least 15 seconds, +1, at most 3 rewarded visits per day |
| First article publication         | +20 per article                                            |
| Eligible comment                  | +2 per comment, at most 3 rewards per day                  |
| Author view milestones            | 100 / 500 / 1000 views, awarding +5 / +10 / +20            |
| Author like milestones (optional) | 10 / 30 / 100 likes, awarding +5 / +10 / +20               |
| Level progression                 | 10 levels: 0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000   |

**Reward changes apply to events first recorded afterward; level threshold changes affect displayed levels immediately.** Changing thresholds leaves balances intact, and saving rules awards no experience. Each article can claim each milestone tier only once. Lowering a count may make an unclaimed tier eligible on the next qualifying activity.

For value ranges, comment eligibility, counting and examples, see [Rule settings](docs/GUIDE.en.md#set-experience-rules).

## Management and growth

Open **Settings → WP XP Core** for all administration. Switch between rules, the ledger, diagnostics, growth, tasks and achievements, insights, activities and notifications, and configuration using the tabs inside the plugin page.

- **Ledger and diagnostics:** filter events by user, source and date; adjust individual XP with a reason or reverse a manual adjustment; compare balances to the ledger without writes and handle explicitly recorded failed rewards.
- **Growth center:** customize level names, character icons, colors and descriptions. Personal pages show check-in calendars, consecutive and cumulative counts, progress and private history. Optional check-in stage rewards start disabled and count new check-ins only.
- **Tasks and achievements:** create once-only, daily, weekly or monthly tasks. Automatic tasks combine built-in activities and registered trusted sources; reviewed tasks accept a user's explanation. Achievements default to honors, with optional XP rewards and image badges. Reasoned administrative grants/revocations are supported, and earned badges retain their awarded snapshots.
- **Control rewards and public participation:** optional daily/weekly XP caps default off, users can leave standings, and administrators can exclude test accounts and inspect activity clues/equal-period trends.
- **Preview before operating:** check task conditions and version effects before saving, copy tasks into disabled drafts, pause a user's automatic rewards temporarily with a reason, and use diagnostics to sample queries, explain original receipts or simulate a hypothetical activity without writes.
- **Composable private growth widgets:** use five shortcodes or blocks for your summary, tasks, achievements, notifications or growth path. Choose a limit, density, title and accent color; actions remain in the full panel.
- **Private notifications and next goals:** review achievement, review and level messages with read/type preferences; task goals explain remaining counts, available content links and prerequisites.
- **Weekly/monthly boards and insights:** manually place `[xp_leaderboard period="week" limit="10"]` or its monthly variant to rank net activity XP. Personal panels show the current user’s position; administrators can review source aggregates and current task completion rates. New weeks and months do not reset XP balances.

See the [user guide](docs/GUIDE.en.md) for steps and change effects. These features do not backfill all historical activity, schedule cron jobs or provide a shop or spendable currency.

## Connect activities and notifications

Open **Settings → WP XP Core → Activities and notifications**, choose a detected source, then preview and save its reward and message rules. New sources start disabled. Enabling a source or changing eligibility applies to new activity without historical awards. Everyday changes need no code edits.

| Optional integration    | What you can do                                                                                        | Without the companion                                      |
| ----------------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| bbPress                 | Configure XP for eligible new public topics and replies                                                | Source unavailable; existing configuration retained        |
| Server-verified games   | Set fixed XP, daily limits and minimum scores with a game extension exposing the public settlement API | Core check-ins, reading and other rewards remain available |
| Companion notifications | Read your existing messages in the XP panel, preserving the original read state                        | Independent XP notifications remain available              |
| Extension channels      | Select registered channels for activity messages                                                       | No outbound messages; no built-in email or webhook channel |

Game extensions verify and store scores; this plugin accounts for XP separately. Browser-only games do not automatically become trusted reward sources. An extension author [connects the public API once](docs/CONFIGURATION.en.md#trusted-activity-providers), then administrators maintain values and text in the dashboard. Administrators may manually retry explicitly failed original messages for registered channels with a reason. Successful or uncertain deliveries cannot be resent. Retrying never awards XP or replays an activity. See the [activity guide](docs/GUIDE.en.md#configure-optional-activities-and-notifications) for steps and limits.

## Requirements

WordPress 6.0 and PHP 8.0 or later. See [Contributing](CONTRIBUTING.md) for the combinations actually tested in CI. The database must support InnoDB transactions and `JSON_VALID`, `JSON_EXTRACT`, `JSON_UNQUOTE`, `JSON_TYPE`. Activation checks these capabilities before writing and reports an error without changing unsupported storage.

## Documentation and help

| Your goal                                                      | Read                                                                      |
| -------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Set rewards, add levels or place the experience panel          | [User guide](docs/GUIDE.en.md)                                            |
| Investigate maintenance messages, missing XP or disabled likes | [Frequently asked questions](docs/GUIDE.en.md#frequently-asked-questions) |
| Configure server storage or integrate another plugin           | [Configuration and integration](docs/CONFIGURATION.en.md)                 |
| Review version changes                                         | [Changelog](CHANGELOG.md)                                                 |
| Contribute improvements                                        | [Contributing](CONTRIBUTING.md)                                           |
| Maintain GitHub releases                                       | [Releasing](docs/RELEASING.md)                                            |

Documentation on this branch describes 2.2.0-rc.1. For another version, use the documentation at its Release tag.

[Open an Issue](https://github.com/wzf2000/wp-xp-core/issues/new/choose) with your version, observed behavior and reproduction steps. Report security issues privately under the [security policy](SECURITY.md).

## Author and license

Maintained by [wzf2000](https://github.com/wzf2000), under **[GPL-2.0-or-later](LICENSE)**. Issues and Pull Requests are welcome.
