# Server configuration and integration

[简体中文](CONFIGURATION.md) · English · [Back to overview](../README.en.md) · [User guide](GUIDE.en.md)

This page is for integrators. **Ordinary first-time installation needs no external configuration: upload and activate a 1.4.0+ installation ZIP.** For page setup, see the [user guide](GUIDE.en.md). External JSON is only for advanced deployments with custom storage or existing integrations.

## Default installation

Without `PAGENEST_COMPATIBILITY_PROFILE_FILE`, the plugin uses built-in configuration. First activation creates the WordPress-prefixed `reader_experience_events` ledger and its own installation markers/live switch. It does not read myCRED data, import historical activity or bulk-initialize user balances. Users without a balance display zero XP and earn experience through subsequent eligible activity.

Activation requires InnoDB support, with WordPress user metadata, post metadata and options already using InnoDB. The plugin does not convert other tables. It verifies schema and unique event keys and serializes installation with a database lock; failed setup leaves rewards disabled. Deactivation and reactivation preserve the ledger, balances, rules and any existing maintenance switch, without recalculating or backfilling XP. Normal requests never initialize storage.

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

Saving requires `manage_options` and a valid nonce. A database-scoped advisory lock serializes settings saves; a revision hash rejects stale forms, and invalid values leave the previous option untouched. An event transaction snapshots one complete policy. Zero rewards retain idempotency markers; zero caps suppress that reward category. Author milestone counts and XP are configurable in three stable tiers per kind. Historical events are not recalculated: comment reversals and restorations use the original event amount. Level changes immediately affect display without rewriting balances. No individual account adjustment interface is provided.

The settings screen groups activity rules, author milestones and levels in responsive cards. Its information sidebar links to the author, license and source repository. The `admin_css` and `admin_js` asset manifest entries are content-hashed and enqueued only for `settings_page_wp-xp-core`; it adds no external fonts, scripts or network dependencies. Plugin metadata identifies the author and repository; `Update URI` does not implement an automatic updater.

### Milestone identity and level rows

The six `view_100_threshold`, `view_500_threshold`, `view_1000_threshold`, `like_10_threshold`, `like_30_threshold` and `like_100_threshold` fields store current counts. Their suffixes are stable tier identifiers, not the configured counts. Each group must strictly increase within 1–1000000000. Complete old policies without all six fields normalize in memory using defaults; partial new policies are invalid. No load-time migration or option write occurs.

Milestone keys remain `milestone:kind:post:originalSlot`, preserving claimed tiers across threshold changes. New events record the actual threshold in detail. Saving does not award XP or reset tiers. On the next qualifying event, unclaimed tiers use cumulative ledger counts, including previously recorded activity. Lowering a threshold can therefore qualify a previously unclaimed tier on that event.

Likes require both `pagenest_companion_like` and `pagenest_companion_feature`, with the `likes` feature enabled. Companion only loads these functions after its profile validates. Without this provider, the settings fieldset and runtime like awards are disabled. Under the settings save lock, omitted or forged like fields are replaced with the stored values; other rules remain editable. No independent like service is included.

Level inputs submit an ordered array. The first value is read-only zero; add/remove controls reindex rows, with a maximum of 100. Server-side validation remains authoritative. Without JavaScript existing rows remain editable, while adding or removing rows requires JavaScript.

## Ledger and display integration

`reader_experience_balance` is the only running balance projection. The existing event ledger remains the source of changes; no cumulative or rank metadata is written. The default ten minimum balances are `0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000`, and levels are computed on demand using the currently configured thresholds. Metadata writes outside the ledger transaction are rejected, including ordinary add, update and delete calls.

The `site_tools_user_level` filter receives `($fallback, $user_id)` and returns `Level N` when ready, or the original fallback otherwise. It reads no rank posts and has no third-party provider dependency. The plugin preserves the configured weekly schedule and rotates game weekly scores using the same scoring lock as verified settlement.

## Compatibility and upgrades

Version 1.4.0 adds native first-install setup. Sites with valid external configuration retain it during updates, with no table creation, recalculation or native initialization.

When upgrading from versions before 1.1.0, migrate existing balances exactly to `reader_experience_balance` and remove `rank_option` from the external experience profile. This release does not perform that migration. Storage, route aliases and shortcode aliases continue to be configured externally; keep historic aliases needed by existing content. Invalid retired profile fields keep the policy disabled.

WP XP Core uses the new plugin basename `wp-xp-core/wp-xp-core.php`. The rename requires switching the active plugin entry, with the old entry disabled before the new one is loaded. Switch any server early-loading basename reference at the same time. Keep the existing profile, ledger, balance metadata, options and scheduled hook unchanged. No data migration or initialization runs as part of this rename. The retained `Reader_Experience` class, `reader_experience_*` functions, default REST namespace and shortcode, CSS selectors, JavaScript global and asset handles are compatibility interfaces rather than package names.

Upgrading from 1.1.1 requires no data migration. Complete older custom policies gain default milestone counts in memory while retaining rewards and levels; loading does not save the option. The plugin’s `Update URI` identifies the repository but does not implement an automatic updater.

## Optional legacy integration

The configured `weekly_hook` and `weekly_lock` retain the existing shared weekly game-score reset integration for deployments that use it. This compatibility code is unchanged; it does not award experience and is not part of the settings UI. Configure the integration only for a site with the corresponding score metadata and scheduler.
