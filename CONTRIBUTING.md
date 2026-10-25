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

## Growth preview / 成长功能预览

`feature/xp-growth-rounds-1-4` prepares the unreleased `2.0.0-rc.1` candidate. Keep both READMEs and both GUIDE/CONFIGURATION language versions synchronized. Validate management permissions, append-only corrections, duplicate/rollback behavior, rule and task version boundaries, private response isolation and atomic derived rewards in isolated fixtures. Do not use real accounts to exercise adjustments, retries or review awards. Preparing this branch does not authorize a release or site deployment.

本分支准备尚未发行的 `2.0.0-rc.1` 候选。同步维护中英文 README、使用指南和配置文档；在隔离环境检查管理权限、追加式撤销、重复／回滚行为、规则与任务版本边界、私人响应隔离及派生奖励的原子性。不要用真实账号测试调分、重试或审核授予；准备候选不等于发行或部署授权。

## Optional synthetic scale workload / 可选合成规模检查

`tools/wp-integration.py --scale` adds bounded 10,000/50,000-row read workloads to the normal owned, empty local WordPress lab. It uses ten synthetic users, fifty tasks with eight steps and up to eight prerequisites, and records query counts plus elapsed milliseconds for personal progress, standings, sources, trends and diagnostics in the lab's `results.json`. The script requires the integration runner's temporary ownership marker, dedicated `wp_xp_core_ci` database, local environment and loopback host; an existing site cannot be adopted. Data is seeded directly as synthetic query fixtures, without claiming real reward semantics. Temporary task definitions are restored; the lab retains its generated subjects and ledger for inspection. This is opt-in and never runs as a scheduled job.

在现有合成安装命令后加 `--scale`，才会运行有界的 1 万／5 万行查询规模检查。保护条件包括临时目录所有权标记、专用数据库、local 环境及回环数据库连接；不能对现有站点补标记接管。记录查询数与本机耗时，不将热缓存或共享主机的绝对耗时解释为生产性能。没有测量依据时不新增索引或宣称提速。
