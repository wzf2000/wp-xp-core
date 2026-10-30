# 贡献指南 / Contributing

欢迎修复问题、改进文档和提出功能建议。较大改动请先通过 Issue 讨论范围；安全问题请遵循 [安全政策](SECURITY.md)。提交 Pull Request 时说明问题、最终行为及实际验证结果。
Bug fixes, documentation improvements and feature proposals are welcome. Discuss substantial changes in an Issue first; follow the [security policy](SECURITY.md) for vulnerabilities. Explain the problem, resulting behavior and actual validation in each Pull Request.

## 本地开发 / Local development

使用 Git 工作副本、PHP 8.0+、Node.js 24 和 Python 3.12。依赖已固定在本仓库；贡献与测试无需生产站点凭据。原生安装在启用时创建账本；可选外部接入保留已有存储。安装测试使用合成夹具，不连接生产数据库。
Use a Git checkout, PHP 8.0+, Node.js 24 and Python 3.12. Development dependencies are pinned here; contribution and tests need no production credentials. Native activation creates its ledger; optional external integration retains existing storage. Installation fixtures are synthetic and never connect to production databases.

```sh
npm ci --ignore-scripts
python3 -m pip install -r requirements-dev.txt
npm run format
npm run format:check
npm test
npx playwright install --only-shell chromium
npm run test:frontend
npm run build
```

PHP、JS/CSS/JSON/YAML 和 Markdown 使用固定的 Prettier 工具，Python 使用 Black。格式检查会验证已生成资源哈希；浏览器测试使用合成数据并屏蔽外部请求，不操作真实用户经验。
Pinned Prettier tools format PHP, JS/CSS/JSON/YAML and Markdown; Black formats Python. Format checks verify generated resource hashes. Browser fixtures use synthetic data and block external requests; they do not change real balances.

`npm run identity:check` 是可选的维护者审计命令，可通过本地 `IDENTITY_RULES` JSON 字符串数组提供检查规则。公共 CI 不依赖私有变量或密钥。不要提交生产配置、凭据、数据库导出或个人明细。
`npm run identity:check` is an optional maintainer audit accepting a local `IDENTITY_RULES` JSON string array. Public CI requires no private variables or secrets. Never commit production configuration, credentials, database exports or personal records.

## 文档与兼容性 / Documentation and compatibility

`README.md` 为中文主版，`README.en.md` 为英文版。功能、配置、安装和发布信息变化时，在同一改动中更新双方，保留顶部语言切换链接。使用指南与配置说明也有 `.en.md` 对应版本，相关改动须同步两种语言。涉及规则或存储时说明历史事件、余额、幂等、权限和并发影响；不要在真实站点重放初始化或补偿脚本。
Maintain both READMEs together for behavior, configuration, installation and release changes, keeping reciprocal language links. The user guide and configuration reference also have `.en.md` counterparts; update both languages together. Document effects on historical events, balances, idempotency, permissions and concurrency for policy or storage changes. Never replay initialization or compensation scripts on a live site.

## Pull Request 与发布 / Pull Requests and releases

PR 与推送共享只读 CI，`Required checks` 汇总所有验证结果。格式检查、PHP 语法、运行测试、浏览器测试、真实 WordPress／MySQL 安装与并发测试，以及不可变安装包校验均须通过。安装包要求完整提交；本地未提交修改导致打包失败是预期保护。
Pull Requests and pushes share read-only CI. `Required checks` aggregates all verification results. Formatting, PHP syntax, runtime tests, browser tests, real WordPress/MySQL installation and concurrency tests, and immutable package verification must pass. Packaging requires a committed checkout; refusing uncommitted changes is intentional.

维护者发布见 [发布流程](docs/RELEASING.md)。贡献者不需要创建标签；手动 GitHub 发布工作流会安全地创建新标签和附件。发布与站点部署相互独立。
See [Releasing](docs/RELEASING.md) for maintainer steps. Contributors need not create tags; the manual release workflow creates new tags and verified attachments. Publishing does not deploy to a site.

## Growth and accounting changes / 成长与记账改动

Validate target permissions, append-only corrections, duplicate/rollback behavior, rule and task version boundaries, private response isolation and atomic rewards in isolated fixtures. Use synthetic accounts for adjustments, retries and review grants. Preparing a candidate does not authorize publication or site deployment.

规则与任务修改须验证权限、追加式补偿、幂等与回滚、版本边界、私人响应隔离和奖励原子性。只用合成账号测试调分、重试和审核，不对真实账户补奖；准备候选不代表发布或部署授权。

## Optional synthetic scale workload / 可选合成规模检查

`tools/wp-integration.py --scale` adds bounded 10,000/50,000-row read workloads to the normal owned, empty local WordPress lab. It uses ten synthetic users, fifty tasks with eight steps and up to eight prerequisites, and records query counts plus elapsed milliseconds for personal progress, standings, sources, trends and diagnostics in the lab's `results.json`. The script requires the integration runner's temporary ownership marker, dedicated `wp_xp_core_ci` database, local environment and loopback host; an existing site cannot be adopted. Data is seeded directly as synthetic query fixtures, without claiming real reward semantics. Temporary task definitions are restored; the lab retains its generated subjects and ledger for inspection. This is opt-in and never runs as a scheduled job.

在现有合成安装命令后加 `--scale`，才会运行有界的 1 万／5 万行查询规模检查。保护条件包括临时目录所有权标记、专用数据库、local 环境及回环数据库连接；不能对现有站点补标记接管。记录查询数与本机耗时，不将热缓存或共享主机的绝对耗时解释为生产性能。没有测量依据时不新增索引或宣称提速。

## Optional real bbPress integration / 可选真实 bbPress 验证

在专用空数据库及全新 `/tmp` 合成站运行既有安装检查时，可显式指定官方稳定版本：
When running the existing installation checks against an empty dedicated database and a fresh `/tmp` lab, opt in to a pinned official stable version:

```sh
WP_XP_TEST_DATABASE=wp_xp_core_ci python3 tools/wp-integration.py \
  --wp-cli /tmp/wp-cli.phar --lab /tmp/wp-xp-core-bbpress-ci \
  --core-version 7.1.3 --bbpress-version 2.6.21
```

沿用安装运行器的 `WP_XP_TEST_DB_HOST`、`WP_XP_TEST_DB_USER` 和 `WP_XP_TEST_DB_PASSWORD` 环境配置；只连接回环服务或已有的临时 Unix socket。该选项只接受明确的 `major.minor.patch` 稳定版本，不能与旧 ZIP 升级模式组合。运行器先完成无依赖的安装、权限、记账及并发检查，再从 WordPress 官方目录安装指定 bbPress，核对实际版本并执行 WP-CLI `plugin verify-checksums --strict`，验证成功后才激活。随后运行真实新建／可见性／撤销测试，以及奖励已插入但尚未提交时另一进程审核的竞争检查。版本、校验输出和断言保存在合成站 `results.json`；不接管现有站点或生产数据库。

Use the runner's existing `WP_XP_TEST_DB_HOST`, `WP_XP_TEST_DB_USER` and `WP_XP_TEST_DB_PASSWORD` environment settings, restricted to loopback or an existing temporary Unix socket. The option accepts an explicit stable `major.minor.patch` version and cannot be combined with old-ZIP upgrades. All dependency-free installation, permission, accounting and concurrency checks finish first. The runner then installs that bbPress version from the official WordPress directory, checks the installed version and runs WP-CLI `plugin verify-checksums --strict` before activation. It exercises real creation, visibility and reversal hooks, including moderation in another process while a reward is inserted but uncommitted. The lab's `results.json` records the version, checksum output and checks. Existing sites and production databases cannot be adopted.

公共 CI 仅在现有 WordPress 7.1.3／PHP 8.5／MySQL 8.0 组合增加 bbPress 2.6.21；其他组合继续不安装它，覆盖独立运行。此固定组合不代表所有 bbPress 历史版本、主题或其他社区插件均已验证；本地额外验证与公共 CI 实际运行结果分别记录。

Public CI adds bbPress 2.6.21 only to the existing WordPress 7.1.3 / PHP 8.5 / MySQL 8.0 combination. Other combinations keep bbPress absent to cover independent operation. This pinned combination does not imply coverage of every historical bbPress version, theme or community plugin; local checks and actual public CI runs are separate evidence.

## Optional real notification source / 可选真实通知源

同一全新合成站可在无配套依赖检查后，显式安装固定 Companion 官方 GitHub Release ZIP：
The same fresh synthetic lab can opt in to a pinned official Companion GitHub Release ZIP after its independent checks:

```sh
WP_XP_TEST_DATABASE=wp_xp_core_ci python3 tools/wp-integration.py \
  --wp-cli /tmp/wp-cli.phar --lab /tmp/wp-xp-core-notifications-ci \
  --core-version 7.1.3 --companion-version 1.12.0 \
  --companion-sha256 628a2844bb6369d249057fbac44a835e4a9f617123e087ac6f58238802c3d0c2
```

版本与 SHA-256 必须同时给出，不接受任意下载地址或旧站接管，不能与旧 ZIP 升级模式混用。安装前核对 ZIP 总哈希、安全路径、slug、版本、发行清单内每个文件及 PHP 版本头，激活后核对实际安装版本及状态。真实回归分别切换父评论与回复的作者，创建已批准合成评论并阻止邮件；对照 Companion 原投影、XP 直接调用及实际 JSON REST 列表／已读路由，检查跨用户 404 和关闭后 XP 独立可用。失败仅记录计数、布尔和错误码，不输出私人通知正文。公共 CI 只在现有 WordPress 7.1.3／PHP 8.5／MySQL 8.0 组合加 Companion 1.12.0，与固定 bbPress 检查共用该组合；其余组合保留不安装配套插件的覆盖。可将两组可选参数一起传给运行器。

Both version and SHA-256 are required. Arbitrary URLs, existing sites and old-ZIP upgrade combinations are rejected. Before installation, the runner validates the ZIP digest, safe paths, slug, version, every manifest payload digest and PHP version header; it then checks the installed version and active state. The real fixture switches between the parent-comment and reply authors, creates approved synthetic comments and suppresses mail. It compares Companion's projection, direct XP dispatch and actual JSON REST list/read routes, including cross-user 404 and independent XP behavior after disabling the source. Failure diagnostics contain only counts, booleans and error codes, without private notification content. Public CI adds Companion 1.12.0 only to the existing WordPress 7.1.3 / PHP 8.5 / MySQL 8.0 combination alongside bbPress; other combinations retain dependency-free coverage. Both optional argument groups may be used together.

## Localization / 翻译

Edit reviewed `languages/wp-xp-core-{locale}.po` entries and run `python3 tools/languages.py`; `--check` verifies MO/JSON bytes. Fixed PHP strings use `wp_xp_core_t` (gettext extraction keyword) or `__` with domain `wp-xp-core`; the small JS bridge uses `languages/js-strings.json`. Keep positional placeholders and user snapshots verbatim. Run the catalog/ZIP tests and verify both locales when changing UI text.

修改 PO 后离线编译 MO／JSON，同步 POT 与 JS 文案列表；保留位置占位符，不翻译用户文字或整段输出 HTML。新增语言文件必须通过实际安装 ZIP 的白名单与验证。

CI covers PHP 8.0/8.2/8.5 basic/browser fixtures and real WordPress/MySQL installation/concurrency on WordPress 6.0 + PHP 8.0, WordPress 6.8.3 + PHP 8.0/8.2 and WordPress 7.1.3 + PHP 8.5. Separate upgrade fixtures install verified official 1.4.1 and 2.0.0-rc.3 ZIPs, seed synthetic account state, replace only plugin files, and verify unchanged data plus idempotent future awards. These combinations define tested coverage, not every historical environment.

实际 CI 包含 WordPress 6.0 + PHP 8.0 最低组合、WordPress 6.8.3 + PHP 8.0／8.2 和 WordPress 7.1.3 + PHP 8.5 的安装／并发检查，以及 PHP 8.0／8.2／8.5 基础与浏览器检查。独立升级夹具从经校验的官方 1.4.1 与 2.0.0-rc.3 ZIP 建立合成旧站，只替换插件文件，检查数据保留及后续奖励幂等。没有将这些组合扩称为所有历史环境。

The database must provide InnoDB transactions and `JSON_VALID`, `JSON_EXTRACT`, `JSON_UNQUOTE` and `JSON_TYPE`. Activation probes those functions before making installation changes. Run SELECT-failure fixtures when changing accounting reads: an unreadable claim, quota or history must never become a successful empty result.

数据库须支持 InnoDB 事务及上述 JSON 函数；激活会先做只读能力探测。修改记账查询时运行 SELECT 故障夹具：去重、额度或响应历史读取失败必须中止并回滚，不能按空结果继续发奖。

## Optional verified-game activity fixture / 可选验证游戏活动

On an owned fresh integration lab with the candidate game pack, an explicit four-game profile and
`easy-games/v1` REST namespace, run `tests/wordpress-game-activities.php` through WP-CLI eval-file.
Set `WP_XP_GAME_FIXTURE_ROOT` to the maintained game source root; the test validates the generator's
engine hashes against the installed pack. Existing database/local/owner marker guards remain required.
The fixture simulates an engine seed and elapsed wall time only inside its synthetic game rows and
boundary; it exercises real replay/settlement, ownership, idempotency, quota, private messages and
post-commit failure isolation. It does not claim human play or production behavior and does not run
in dependency-free CI combinations.

仅在已配置候选游戏包的全新合成站执行上述可选夹具；显式传入维护源根并核对引擎哈希。
种子、局耗时和边界调整只属于合成测试，不修改生产，不冒称用户真实玩过整局。
