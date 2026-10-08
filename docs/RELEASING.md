# 发布流程 / Releasing

此流程只生成安装包或发布 GitHub Release，不连接或更新 WordPress 站点。插件仍需要站点管理员配置已有账本与外部 JSON，详见 [配置说明](CONFIGURATION.md)。
This workflow builds packages or publishes a GitHub Release; it never connects to or updates WordPress sites. Administrators still configure an existing ledger and external JSON profile; see [Configuration](CONFIGURATION.md).

1. 通过 Pull Request 更新版本、变更日志及中英文 README。版本必须一致：插件头、运行时常量、`package.json`、`package-lock.json` 根包。
   Update the version, changelog and both READMEs through a Pull Request. Keep the plugin header, runtime constant and root package/lock versions aligned.
2. 等待 `Required checks` 成功并合入 `main`。
   Wait for `Required checks` and merge into `main`.
3. 在 Actions 选择 **Manual WP XP Core Release**，分支选 `main`，填写不含 `v` 的精确版本。默认 `publish=false`，仅生成可下载附件。
   Dispatch **Manual WP XP Core Release** from `main` with the exact version without `v`. The default `publish=false` builds downloadable artifacts only.
4. 检查附件和固定的源提交；准备公开时，以 `publish=true` 重新运行。该次运行会重新执行 CI，并只发布本次运行产生且校验通过的附件。
   Review artifacts and the pinned source commit. Run again with `publish=true` when ready; that run repeats CI and publishes only its own verified artifacts.

版本格式支持 `X.Y.Z`、`X.Y.Z-alpha.N`、`X.Y.Z-beta.N` 和 `X.Y.Z-rc.N`。发布工作流拒绝已有标签或 Release；不要事先手动创建标签。相同版本串行执行，标签通过原子创建绑定到固定提交。
Versions accept `X.Y.Z`, `X.Y.Z-alpha.N`, `X.Y.Z-beta.N` and `X.Y.Z-rc.N`. Existing tags or releases are refused; do not pre-create tags. Runs for a version are serialized and atomically create the tag at the pinned commit.

CI 只有读取权限，并且检出时不保存凭据。发布作业单独取得写权限，不检出仓库、不执行安装包中的代码，只验证同一运行的附件；检查附件哈希、清单、源提交、ZIP 路径与文件集合。先创建草稿并上传 ZIP、校验和、清单、验证记录及说明；核对远端附件摘要后才公开。
CI is read-only and checkout retains no credentials. A separate publishing job has write permission, checks out no repository and executes no package code. It verifies same-run artifacts, hashes, manifests, source identity, ZIP paths and file sets. It creates a draft, uploads the ZIP, checksum, manifest, validation proof and notes, and checks remote digests before publication.

中途失败可能留下未公开草稿或已占用的标签。不要通过重跑覆盖它们；维护者须先检查已创建的提交和附件，再决定清理或使用新的版本号。
Failure may leave an unpublished draft or reserved tag. Reruns never overwrite them. Inspect the existing commit and assets before deciding whether to clean up or use a new version.

本地验证可在已提交工作副本执行 `npm run package` 和 `npm run package:check`。无远程仓库也能构建；`REPOSITORY` 可选。部署需单独执行站点自身的备份、变更与验收流程。
For local verification, run `npm run package` and `npm run package:check` from a committed checkout. A remote is optional, as is `REPOSITORY`. Site deployment follows its own backup, change and validation process.
