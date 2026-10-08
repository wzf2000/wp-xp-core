# WP XP Core

[简体中文](README.md) · English

Independent WordPress experience system with configurable rewards and levels: daily check-in, reading dwell time, publishing, comments and author milestones. Version **1.3.1**, PHP **8.0+**, WordPress **6.0+**. No third-party points or rank provider is required.

## Install and use

1. Build the verified `wp-xp-core-1.3.1.zip` from a committed checkout and install through WordPress.
2. Have the server administrator configure the shared JSON profile outside the web root, pointing to the existing ledger and policy options. With no valid configuration, experience activity remains inactive; the settings page is still available.
3. Stop the previous experience owner before enabling this plugin. Retain all existing balances and event records.
4. Add the configured experience shortcode to the account page, then verify the frontend in an isolated test environment.

This plugin connects to an existing configured ledger. It does not install tables, initialize accounts or recalculate balances. Deployment status and server configuration are maintained outside this source tree; preparing source or a ZIP does not deploy the plugin.

## Customize experience rules

Open **Settings → WP XP Core**, or use **Settings** on the plugin row. Administrators with `manage_options` can edit global rules; individual account adjustments are not included.

| Rule                                     | Default                                       |
| ---------------------------------------- | --------------------------------------------- |
| Daily check-in                           | 2 XP, once per day                            |
| Valid reading                            | 1 XP, at most 3 rewards per day               |
| First article publication                | 20 XP                                         |
| Eligible comment                         | 2 XP, at most 3 rewards per day               |
| Author view milestones: 100 / 500 / 1000 | 5 / 10 / 20 XP                                |
| Author like milestones: 10 / 30 / 100    | 5 / 10 / 20 XP                                |
| Level minimums                           | 0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000 |

Reward values accept integers from 0 to 1,000,000. Reading and comment daily limits accept 0–1,000. Level minimums accept 1–100 strictly increasing integer thresholds starting at 0, each at most 1,000,000,000. Edit levels as rows, adding or removing entries with JavaScript; the first level remains 0. Views and likes each have three tiers with editable counts and rewards. Counts must be strictly increasing integers from 1 to 1,000,000,000 within each group.

Each article can earn each tier only once; changing counts does not reset claimed tiers. Saving awards nothing. Unclaimed tiers are evaluated against cumulative ledger counts on the next qualifying activity, so lowering a threshold can make an unclaimed tier eligible on that next activity.

New reward rules apply to events first recorded after the change. Historical rewards are never recalculated or reissued. A zero reward still records the event to prevent later duplicate rewards; a zero daily limit disables that category's rewards. Comment removal and restoration use the original award, even when current rules differ. Changing level thresholds immediately changes displayed levels without changing experience balances.

Defaults exactly preserve the previous policy. Loading the plugin or settings creates no settings option. Invalid saved data falls back to defaults with an administrator warning; invalid submissions preserve the previous settings. Saves require administrator capability and a valid nonce, and stale forms cannot overwrite newer settings. Settings are stored in `wp_xp_core_rules`; they do not replace the external storage/integration profile.

The responsive settings page groups daily activities, author milestones and level progression. Its sidebar provides the version, author [wzf2000](https://github.com/wzf2000), license and rule guidance. The [source repository](https://github.com/wzf2000/wp-xp-core) provides documentation, change history and contribution guidance. Admin styles and scripts load only on this plugin’s settings page.

## Frontend and integration

The frontend displays experience, the calculated level, the next threshold and recent private history. Balances use `reader_experience_balance` user metadata as a projection of the event ledger. Check-in and reading require authenticated requests; reading still requires 15 seconds of dwell time. Existing eligibility restrictions, daily event deduplication and transactional ledger writes remain in place.

PageNest Companion owns likes. WP XP Core receives the standard like event, records it once with zero XP and applies author milestones. The previous like REST aliases delegate to Companion. Disabling experience does not prevent Companion likes. Like milestones require an available Companion like interface and its likes feature enabled. Otherwise the group is disabled with an explanation, saving other rules preserves stored like settings, and runtime like recording and milestone awards are disabled.

The `site_tools_user_level` filter accepts the fallback and user ID and returns `Level N` when ready; it retains the fallback when unavailable. The `site_tools_account_panel` filter supplies the account panel when the policy is ready and preserves the caller's existing output otherwise.

## Upgrade from Reader Experience

Version 1.1.1 changed the plugin basename to `wp-xp-core/wp-xp-core.php`. Disable `reader-experience/reader-experience.php` before activating WP XP Core; do not load both copies. WordPress identifies these as different plugin entries, so replacing the old installation requires an explicit activation switch. Update server early-loading configuration to the new basename during the same switch. Retain the external compatibility profile and existing account data; the rename performs no database, balance or schedule migration.

The `Reader_Experience` class, `reader_experience_*` functions, storage and option names, the default `reader-experience/v1` REST namespace, `reader_experience` shortcode, asset handles, `ReaderExperience` JavaScript global and `reader-experience-*` CSS selectors remain stable compatibility interfaces.

Upgrading from 1.1.1 requires no data migration. Existing complete custom policies gain default milestone counts in memory without writes, retaining custom rewards and levels. Installations without saved rules continue using the original defaults.

## Development and release

[Configuration and safety](docs/CONFIGURATION.md) · [Development commands](CONTRIBUTING.md) · [Changelog](CHANGELOG.md) · [License](LICENSE)

Maintain the Chinese and English READMEs together whenever behavior, configuration, installation or release information changes, retaining reciprocal language links.

For local builds, commit the complete candidate, then run `npm run package` and `npm run package:check`. A GitHub remote or `REPOSITORY` value is optional. The manual GitHub release workflow below creates its own tags; do not pre-create a release tag.

Pinned development dependencies, formatters, asset build, contracts, storage runtime tests, browser checks and deterministic package guards are self-contained. The installation ZIP includes PHP, content-hashed JS/CSS and both READMEs, and excludes tests, tools and dependencies. CI runs PHP 8.0/8.2. The optional local identity scanner accepts external rules; public CI needs no secrets.

## Contribute

Use Issues for reproducible bugs or feature discussion, and Pull Requests for improvements. Read [Contributing](CONTRIBUTING.md) first. Report security issues privately under the [security policy](SECURITY.md); never post credentials, user data or exploit details publicly.

CI runs the same read-only checks for pushes and Pull Requests; contributors need no site credentials. Maintainers can manually run the release workflow from `main`: it builds artifacts by default and creates a GitHub Release only when publishing is explicitly selected. Releases never deploy to any site; see [Releasing](docs/RELEASING.md).
