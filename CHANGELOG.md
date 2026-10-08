# Changelog

## 1.4.1

- Preserve single-line community ranking levels when a theme forces table cells to wrap; keep the override scoped to the level column.
- Add a regression fixture with the theme’s important cell wrapping rule.

## 1.4.0

- Set up native experience storage on activation with built-in defaults, without server JSON or another points plugin. Preserve explicit external integrations and all existing data.
- Validate transactional storage and owned schemas, serialize installation and keep ordinary requests read-only. Preserve data on reactivation; support multisite per-site activation with isolated balances.
- Add manual experience-page setup guidance and a protected page selector with independent save/revision checks. No pages are created or published automatically.
- Keep community ranking levels, including Level 10 and Level 100, on one line with contained scrolling at narrow widths.
- Update both language versions of the installation and page setup guides.

## 1.3.1

- Prepare public contribution, issue reporting and security documentation with synchronized README guidance.
- Add read-only pull request checks, a stable required gate and a manually dispatched, verified release pipeline.
- Remove unrelated game guidance and private-repository labels from settings without changing runtime integrations.

## 1.3.0

- Configure view and like milestone counts while retaining stable claimed-tier identities and existing custom policies.
- Edit levels as addable/removable rows with scoped immutable admin JavaScript.
- Gate like settings and milestone recording on the available, enabled Companion provider; preserve disabled settings under save locking.

## 1.2.1

- Refine settings with responsive grouped cards, contextual guidance and a plugin information sidebar.
- Add author, repository, license and compatibility metadata; load content-hashed admin styles only on this settings page.
- Preserve all reward rules, stored data, validation and save protections.

## 1.2.0

- Add an administrator settings page for global rewards, daily caps and variable level thresholds, with nonce validation and concurrent-edit protection.
- Preserve defaults and historical ledger values; comment reversals/restorations use their original award.
- Show configured check-in rewards and calculated next levels without hardcoded frontend amounts.
- Provide synchronized Chinese and English READMEs in the installation package.

## 1.1.1

- Rename the plugin and installation package to WP XP Core (`wp-xp-core/wp-xp-core.php`).
- Preserve the existing experience policy, ledger, balances, options, runtime API and frontend integration identifiers.
- Update release tooling, CI and fixtures for the new package identity; no data migration is performed.

## 1.1.0

- Maintain independent balances in `reader_experience_balance` within the existing ledger transaction and lock.
- Compute the unchanged ten levels directly and provide the generic `site_tools_user_level` filter.
- Retire third-party balance, rank and reward hooks; reject the retired `rank_option` profile field.
- Retain authenticated history, Companion like events and score-only weekly rotation.
- Label explicit historical ledger reconciliation in activity history.
- Invalidate affected user metadata caches after committed weekly score rotation.

## 1.0.0

- Independent experience plugin with the existing ten-level event policy and account history.
- Shared external compatibility profile; safe defaults perform no installation or balance migration.
- PageNest Companion owns likes; durable like events feed idempotent experience milestones.
- Self-contained pinned format, asset, contract, browser, CI and immutable-package commands.
- Exact shared weekly lock and optional account-panel filter preserve integration ownership.
