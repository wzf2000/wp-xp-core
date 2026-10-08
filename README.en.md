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
- **Show personal progress.** The account panel displays experience, level, experience needed for the next level, check-in and recent private history.
- **Keep an award trail.** Experience changes are recorded in an event ledger; repeated requests cannot claim the same reward twice.

The plugin manages experience and levels independently of myCRED. Likes are an optional integration. When the like service is unavailable, its rules are disabled while other settings remain editable.

## Get started

**Version 1.3.1 is intended for WordPress sites with server administration access and an existing experience ledger.** It requires an external JSON profile and compatible storage, so uploading a ZIP is only part of setup. There is no first-run initialization wizard for a new site; activation does not create tables, migrate historical data or recalculate experience.

1. Download `wp-xp-core-VERSION.zip` from the [latest Release](https://github.com/wzf2000/wp-xp-core/releases/latest), rather than GitHub’s automatically generated **Source code** archives.
2. Ask your server administrator to review the existing ledger, balances, live option and JSON profile using [Server configuration](docs/CONFIGURATION.en.md). Disable the old experience owner before replacing it.
3. Install and activate the ZIP through **Plugins → Add New Plugin → Upload Plugin** in WordPress.
4. Add the configured shortcode to an account page. The default is `[reader_experience]`.
5. Open **Settings → WP XP Core**, adjust the rules and save. See the [user guide](docs/GUIDE.en.md) for steps and troubleshooting.

Existing installations can update using the next Release ZIP. No automatic updater is included. For the older Reader Experience directory switch and upgrade requirements, see [Compatibility and upgrades](docs/CONFIGURATION.en.md#compatibility-and-upgrades).

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
| Set rewards, add levels or place the account panel             | [User guide](docs/GUIDE.en.md)                                            |
| Investigate maintenance messages, missing XP or disabled likes | [Frequently asked questions](docs/GUIDE.en.md#frequently-asked-questions) |
| Configure server storage or integrate another plugin           | [Configuration and integration](docs/CONFIGURATION.en.md)                 |
| Review version changes                                         | [Changelog](CHANGELOG.md)                                                 |
| Contribute improvements                                        | [Contributing](CONTRIBUTING.md)                                           |
| Maintain GitHub releases                                       | [Releasing](docs/RELEASING.md)                                            |

Documentation on `main` describes the current source. For an installed release, use the documentation at its Release tag.

[Open an Issue](https://github.com/wzf2000/wp-xp-core/issues/new/choose) with your version, observed behavior and reproduction steps. Report security issues privately under the [security policy](SECURITY.md).

## Author and license

Maintained by [wzf2000](https://github.com/wzf2000), under **[GPL-2.0-or-later](LICENSE)**. Issues and Pull Requests are welcome.
