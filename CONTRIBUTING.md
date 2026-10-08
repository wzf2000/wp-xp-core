# 贡献指南 / Contributing

欢迎修复问题、改进文档和提出功能建议。较大改动请先通过 Issue 讨论范围；安全问题请遵循 [安全政策](SECURITY.md)。提交 Pull Request 时说明问题、最终行为及实际验证结果。
Bug fixes, documentation improvements and feature proposals are welcome. Discuss substantial changes in an Issue first; follow the [security policy](SECURITY.md) for vulnerabilities. Explain the problem, resulting behavior and actual validation in each Pull Request.

## 本地开发 / Local development

使用 Git 工作副本、PHP 8.0+、Node.js 24 和 Python 3.12。依赖已固定在本仓库；贡献与测试无需生产站点凭据。当前插件接入预先配置的外部 JSON 和已有账本，不是自动建表的通用安装器。
Use a Git checkout, PHP 8.0+, Node.js 24 and Python 3.12. Development dependencies are pinned here; contribution and tests need no production credentials. The plugin connects to a preconfigured external JSON profile and an existing ledger; it does not automatically provision tables.

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

PR 与推送共享只读 CI，`Required checks` 汇总所有验证结果。格式检查、PHP 语法、运行测试、浏览器测试和不可变安装包校验均须通过。安装包要求完整提交；本地未提交修改导致打包失败是预期保护。
Pull Requests and pushes share read-only CI. `Required checks` aggregates all verification results. Formatting, PHP syntax, runtime tests, browser tests and immutable package verification must pass. Packaging requires a committed checkout; refusing uncommitted changes is intentional.

维护者发布见 [发布流程](docs/RELEASING.md)。贡献者不需要创建标签；手动 GitHub 发布工作流会安全地创建新标签和附件。发布与站点部署相互独立。
See [Releasing](docs/RELEASING.md) for maintainer steps. Contributors need not create tags; the manual release workflow creates new tags and verified attachments. Publishing does not deploy to a site.
