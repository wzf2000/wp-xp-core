# Changelog

## 2.1.0

- 整合 2.1 预发行版的可配置活动、可选游戏与 bbPress 接入、任务预览／副本、私人通知源和管理诊断；默认开关、已有规则和用户数据保持。
- 修复个人数据导出遗漏活动每日额度快照的问题，保留零经验记录的额度、耗尽状态和原请求经验，方便解释奖励结果。
- 增加固定 2.0.1 与 2.1.0-rc.2 安装包升级回归，核对规则、来源边界、通知偏好、历史回执及重新启用后数据保留，后续奖励和撤销保持幂等。
- 整理中英文首页与指南中的活动入口、依赖说明和升级步骤，统一八个内部页签的导航说明。

Stable 2.1 integrates optional activities, trusted game and bbPress adapters, task tools, private notification sources and operational controls. Personal exports retain quota-exhaustion facts. Upgrade regressions cover the previous stable and RC packages, preserving policy boundaries, preferences and receipts. Existing data and default-disabled integrations remain unchanged; no migration or historical awards run.

## 2.1.0-rc.2

- 新增「活动与通知」后台页签，选择可信来源后设置固定经验、每日次数、可选最低分、私人通知文案与预览。
- 新增活动通知偏好与可扩展渠道接口；仅新账本提交后尝试投递，失败不影响经验，不自动重放。
- 可选接入支持公开结算门面的服务端验证游戏；旧游戏包提示升级适配，未安装时仍独立运行。
- 来源规则边界由服务器维护，资格变更或重新启用只接纳新活动；次数耗尽留下零经验幂等凭据，跨日重试不补差。

Adds a trusted activity directory, bounded reward rules, private activity messages and optional post-commit channel callbacks. Verified game receipts can opt in through the public server facade; existing data and default-disabled integrations remain preserved.

## 2.1.0-rc.1

- 新增任务只读预览、禁用的新任务副本，以及讨论、组合参与和审核模板。
- 新增服务端活动原额撤销接口、默认关闭的 bbPress 公开主题／回复适配。
- 新增私人通知源接口；可在经验面板选择可用的 Companion 通知源。
- 新增管理员手动性能采样与最长二十四小时的自动经验奖励暂停，保留零经验活动凭据，不追补差额。
- 保留已有规则、余额、历史事件和任务；不增加表、索引或定时任务。

Task preview/copy/templates, exact provider reversals, optional bbPress activity, private notification sources, bounded diagnostics and temporary automatic-reward holds. Existing data and defaults remain unchanged.

## 2.0.1

- 后台 “设置” 菜单统一保留 WP XP Core 一个入口，经验账本、成长中心、任务与成就、激励效果和配置工具通过插件页内页签访问。
- 保留原有页面地址、详情链接、权限与资源加载；切换页签时侧栏持续高亮 WP XP Core，不改变经验或规则。

## 2.0.0

Configure independent XP, personal growth, versioned tasks, achievements and activity standings without another points plugin. / 独立配置经验、个人成长、版本化任务、成就与活动榜单，无需其他积分插件。

- Include the management, growth, tasks, private notifications, configuration tools and optional integrations from the 2.0 prereleases. Existing events, balances, rules and earned snapshots are preserved; no historical awards or database migrations run. / 整合 2.0 预发布版的管理、成长、任务、私人通知、配置工具与可选接入；保留历史事件、余额、规则和成就快照，不补发或迁移数据。
- Stop accounting on failed deduplication, quota, milestone, comment-receipt or response-history reads, with transaction rollback instead of treating failures as empty results. / 去重、额度、里程碑、评论原记录或响应历史读取失败时中止记账并回滚，不再将读取失败解释为空记录。
- Share equivalent task-participation scans while keeping deduplicated participants and completions in SQL. / 相同任务参与条件共享查询，参与者与完成者仍由数据库去重统计。
- Check required JSON database functions before activation writes and include recipient-visible review feedback in personal exports. / 激活写入前检查所需 JSON 数据库函数，个人导出补齐用户可见审核反馈。
- Correct MO catalog offsets for the legacy WordPress translation reader; verify minimum WordPress compatibility and upgrades from 1.4.1 and 2.0.0-rc.3, and synchronize versioned bilingual guides. / 修正旧版 WordPress 翻译读取器所需的 MO 偏移；验证最低版本兼容性及从 1.4.1、2.0.0-rc.3 的保留数据升级，同步双语指南版本。

## 2.0.0-rc.3

Prerelease expanding configurable tasks, private growth feedback and plugin administration. / 扩展可配置任务、私人成长反馈与插件管理的预发布版。

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
