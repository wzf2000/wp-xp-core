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

## Localization / 翻译

Edit reviewed `languages/wp-xp-core-{locale}.po` entries and run `python3 tools/languages.py`; `--check` verifies MO/JSON bytes. Fixed PHP strings use `wp_xp_core_t` (gettext extraction keyword) or `__` with domain `wp-xp-core`; the small JS bridge uses `languages/js-strings.json`. Keep positional placeholders and user snapshots verbatim. Run the catalog/ZIP tests and verify both locales when changing UI text.

修改 PO 后离线编译 MO／JSON，同步 POT 与 JS 文案列表；保留位置占位符，不翻译用户文字或整段输出 HTML。新增语言文件必须通过实际安装 ZIP 的白名单与验证。

CI currently covers PHP 8.0/8.2/8.5 basic/browser fixtures and real WordPress/MySQL installation/concurrency on WordPress 6.8.3 + PHP 8.0/8.2 and WordPress 7.1.3 + PHP 8.5. The declared WordPress 6.0 minimum does not mean every historical combination was tested.

实际 CI 组合为 PHP 8.0／8.2／8.5 基础与浏览器检查，以及 WordPress 6.8.3 + PHP 8.0／8.2、WordPress 7.1.3 + PHP 8.5 的真实数据库安装／并发测试；不将最低版本声明当作所有历史组合的验收证据。
