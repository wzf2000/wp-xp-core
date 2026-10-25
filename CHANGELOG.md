# Changelog

## 2.0.0-rc.3

Unreleased development candidate; the published rc.2 ZIP does not include these changes. / 尚未发行的开发候选，已公开 rc.2 安装包不包含以下改动。

- Add scoped/versioned tasks, monthly periods, prerequisites, optional XP/image badges and atomic reasoned grant/revocation. / 范围任务、月度周期、前置、可选经验和图片徽章、原子人工授予与撤销。
- Add private durable notifications, next goals, gross daily/weekly activity caps, standings privacy and bounded comparative/version diagnostics. Protect insufficient comment compensation with captured, revalidated administrator retry. / 私人通知、目标指引、活动上限、榜单隐私与统计；不足余额评论补偿整笔拒绝并可受控重试。
- Add previewed atomic configuration import/export, explicit templates, editor blocks, English/Chinese catalogs, compact keyboard-accessible personal tasks, WordPress personal-data export and explicit retention policy. / 配置预览与原子导入、模板、区块、中英翻译、紧凑可访问任务界面、分页个人数据导出和保留政策。
- Add default-off server provider policies and fixed-origin stable/prerelease GitHub checks; preserve existing APIs, history, earned snapshots and default behavior. No migration, new table/index, email or cron. / 默认关闭的活动提供者策略与固定官方更新渠道，保持旧接口与历史，不迁移、不加表／索引、邮件或定时任务。

## 2.0.0-rc.2

Prerelease adding activity leaderboards and read-only incentive insights.

- Add manually placed weekly/monthly activity boards with positive net XP, negative compensation, competition ranks and live pagination; exclude historical imports, reconciliation, manual adjustments and honor-only events. Weeks and months never reset balances.
- Add a nonce-protected current-user position lookup and JavaScript-enhanced personal panel. Public rows expose only display name, public author link, net XP and rank.
- Add administrator source aggregates with bounded date ranges, zero-XP participation and separately identified manual changes, plus current-version/current-cycle task completion rates based on participants and completers.
- Keep all statistics read-only over existing storage, without migrations, indexes, statistics caches, cron jobs or retrospective awards.

## 2.0.0-rc.1

Prerelease with experience management, growth profiles and honor-only achievements.

- Add administrator ledger queries, reasoned manual adjustments and reversals, read-only diagnostics, bounded configuration audit and controlled retries of explicitly failed initial article/comment awards.
- Add activity switches, content scope, configurable reading dwell/time zone, stable editable milestone lists and committed-event integration hooks.
- Add level profiles, personal check-in calendar and private paginated history, with optional future-only stage rewards committed with check-in.
- Add versioned multi-step automatic tasks and reviewed submissions, once/daily/weekly periods and persistent zero-XP achievement snapshots.
- Preserve historical events and balances; do not schedule cron jobs or backfill past activity.

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
