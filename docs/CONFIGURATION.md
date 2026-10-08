# Configuration and retained policy

Define `PAGENEST_COMPATIBILITY_PROFILE_FILE` in server configuration. It must point to a readable JSON file outside `ABSPATH`, with `schema_version: 1` and an explicit `experience` object. No profile, missing experience configuration or a validation error keeps `ready()` false; loading neither installs a ledger nor recomputes balances.

The ten fields are:

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

The `site_tools_account_panel` filter returns the existing experience panel when ready. The callback keeps the incoming value when the policy is unavailable. No other plugin needs to reference the implementation class.

## Independent storage and integration

`reader_experience_balance` is the only running balance projection. The existing event ledger remains the source of changes; no cumulative or rank metadata is written. The default ten minimum balances are `0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000`, and levels are computed on demand using the currently configured thresholds. Metadata writes outside the ledger transaction are rejected, including ordinary add, update and delete calls.

The `site_tools_user_level` filter receives `($fallback, $user_id)` and returns `Level N` when ready, or the original fallback otherwise. It reads no rank posts and has no third-party provider dependency. The plugin preserves the configured weekly schedule and rotates game weekly scores using the same scoring lock as verified settlement.

When upgrading from versions before 1.1.0, migrate existing balances exactly to `reader_experience_balance` and remove `rank_option` from the external experience profile. This release does not perform that migration. Storage, route aliases and shortcode aliases continue to be configured externally; keep historic aliases needed by existing content. Invalid retired profile fields keep the policy disabled.

## Package rename in 1.1.1

WP XP Core uses the new plugin basename `wp-xp-core/wp-xp-core.php`. The rename requires switching the active plugin entry, with the old entry disabled before the new one is loaded. Switch any server early-loading basename reference at the same time. Keep the existing profile, ledger, balance metadata, options and scheduled hook unchanged. No data migration or initialization runs as part of this rename. The retained `Reader_Experience` class, `reader_experience_*` functions, default REST namespace and shortcode, CSS selectors, JavaScript global and asset handles are compatibility interfaces rather than package names.

## Global rules in 1.2.0

Administrators can open **Settings → WP XP Core** to configure rewards, reading/comment daily caps and 1–100 increasing level thresholds. The validated `wp_xp_core_rules` option is separate from the external deployment profile; reads never create it. Missing or invalid settings use the original defaults. See the [Chinese README](../README.md) or [English README](../README.en.md) for ranges and defaults.

Saving requires `manage_options` and a valid nonce. A database-scoped advisory lock serializes settings saves; a revision hash rejects stale forms, and invalid values leave the previous option untouched. An event transaction snapshots one complete policy. Zero rewards retain idempotency markers; zero caps suppress that reward category. Author milestone counts and XP are configurable in three stable tiers per kind. Historical events are not recalculated: comment reversals and restorations use the original event amount. Level changes immediately affect display without rewriting balances. No individual account adjustment interface is provided.

The settings screen groups activity rules, author milestones and levels in responsive cards. Its information sidebar links to the author, license and access-controlled source repository. The `admin_css` and `admin_js` asset manifest entries are content-hashed and enqueued only for `settings_page_wp-xp-core`; it adds no external fonts, scripts or network dependencies. Plugin metadata identifies the author and repository; `Update URI` does not implement an automatic updater.

## Editable milestones and level lists in 1.3.0

The six `view_100_threshold`, `view_500_threshold`, `view_1000_threshold`, `like_10_threshold`, `like_30_threshold` and `like_100_threshold` fields store current counts. Their suffixes are stable tier identifiers, not the configured counts. Each group must strictly increase within 1–1000000000. Complete old policies without all six fields normalize in memory using defaults; partial new policies are invalid. No load-time migration or option write occurs.

Milestone keys remain `milestone:kind:post:originalSlot`, preserving claimed tiers across threshold changes. New events record the actual threshold in detail. Saving does not award XP or reset tiers. On the next qualifying event, unclaimed tiers use cumulative ledger counts, including previously recorded activity. Lowering a threshold can therefore qualify a previously unclaimed tier on that event.

Likes require both `pagenest_companion_like` and `pagenest_companion_feature`, with the `likes` feature enabled. Companion only loads these functions after its profile validates. Without this provider, the settings fieldset and runtime like awards are disabled. Under the settings save lock, omitted or forged like fields are replaced with the stored values; other rules remain editable. No independent like service is included.

Level inputs submit an ordered array. The first value is read-only zero; add/remove controls reindex rows, with a maximum of 100. Server-side validation remains authoritative. Without JavaScript existing rows remain editable, while adding or removing rows requires JavaScript.
