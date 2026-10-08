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
- **Encourage authors.** Configure three view milestones per article, plus three optional like milestones with PageNest Companion.
- **Create your growth curve.** Add or remove rows to configure minimum experience for 1–100 levels, without editing code.
- **Show personal progress.** The experience panel displays experience, level, experience needed for the next level, check-in and recent private history.
- **Keep an award trail.** Experience changes are recorded in an event ledger; repeated requests cannot claim the same reward twice.

The plugin manages experience and levels independently of myCRED. Likes are an optional integration. When the like service is unavailable, its rules are disabled while other settings remain editable.

## Get started

**Current source version: 1.4.1.** On a new site, installing and activating creates the experience ledger and uses default rules. No server JSON profile or other experience plugin is required. Existing users start at zero XP; old articles and historical comments do not trigger automatic backfill.

1. Download `wp-xp-core-VERSION.zip` from [Releases](https://github.com/wzf2000/wp-xp-core/releases), rather than GitHub’s automatically generated **Source code** archives. Automatic first-install setup requires **1.4.0 or later**; use the release documentation for older versions.
2. Install and activate the ZIP through **Plugins → Add New Plugin → Upload Plugin** in WordPress.
3. Open **Settings → WP XP Core** to review or customize rewards and levels. Defaults work immediately.
4. Create a normal page titled “My experience”, add a **Shortcode** block containing `[reader_experience]`, and publish. See [Create an experience page](docs/GUIDE.en.md#create-an-experience-page).
5. Back in plugin settings, select that page under **添加经验面板** (Add experience panel) and click **保存面板页面** (Save panel page). Signed-in users can view XP, check in and read their recent history there.

“My experience” is an ordinary WordPress page; no account plugin is required. Experience content belongs to the currently signed-in user. Activation does not create or publish a page.

Existing sites can update with a new Release ZIP. Deactivating and reactivating preserves experience and settings. No automatic updater is included. External server configuration is an optional [advanced integration](docs/CONFIGURATION.en.md); ordinary first-time installation needs no old-plugin migration work.

## Configure your experience rules

The settings page groups **daily activities, author milestones and level progression**. You can change rewards, reading/comment daily limits, milestone counts and level thresholds.

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

## Documentation and help

| Your goal                                                      | Read                                                                      |
| -------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Set rewards, add levels or place the experience panel          | [User guide](docs/GUIDE.en.md)                                            |
| Investigate maintenance messages, missing XP or disabled likes | [Frequently asked questions](docs/GUIDE.en.md#frequently-asked-questions) |
| Configure server storage or integrate another plugin           | [Configuration and integration](docs/CONFIGURATION.en.md)                 |
| Review version changes                                         | [Changelog](CHANGELOG.md)                                                 |
| Contribute improvements                                        | [Contributing](CONTRIBUTING.md)                                           |
| Maintain GitHub releases                                       | [Releasing](docs/RELEASING.md)                                            |

Documentation on `main` describes the current source. For an installed release, use the documentation at its Release tag.

[Open an Issue](https://github.com/wzf2000/wp-xp-core/issues/new/choose) with your version, observed behavior and reproduction steps. Report security issues privately under the [security policy](SECURITY.md).

## Author and license

Maintained by [wzf2000](https://github.com/wzf2000), under **[GPL-2.0-or-later](LICENSE)**. Issues and Pull Requests are welcome.
