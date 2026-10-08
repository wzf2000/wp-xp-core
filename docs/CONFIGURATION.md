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

`reader_experience_balance` is the only running balance projection. The existing event ledger remains the source of changes; no cumulative or rank metadata is written. The ten minimum balances are `0, 5, 20, 60, 150, 300, 600, 1000, 1800, 3000`, and levels are computed on demand. Metadata writes outside the ledger transaction are rejected, including ordinary add, update and delete calls.

The `site_tools_user_level` filter receives `($fallback, $user_id)` and returns `Level N` when ready, or the original fallback otherwise. It reads no rank posts and has no third-party provider dependency. The plugin preserves the configured weekly schedule and rotates game weekly scores using the same scoring lock as verified settlement.

When upgrading from versions before 1.1.0, migrate existing balances exactly to `reader_experience_balance` and remove `rank_option` from the external experience profile. This release does not perform that migration. Storage, route aliases and shortcode aliases continue to be configured externally; keep historic aliases needed by existing content. Invalid retired profile fields keep the policy disabled.

## Package rename in 1.1.1

WP XP Core uses the new plugin basename `wp-xp-core/wp-xp-core.php`. The rename requires switching the active plugin entry, with the old entry disabled before the new one is loaded. Switch any server early-loading basename reference at the same time. Keep the existing profile, ledger, balance metadata, options and scheduled hook unchanged. No data migration or initialization runs as part of this rename. The retained `Reader_Experience` class, `reader_experience_*` functions, default REST namespace and shortcode, CSS selectors, JavaScript global and asset handles are compatibility interfaces rather than package names.
