<p align="center"><img src="docs/assets/hero.svg" alt="WP XP Core — 为参与积累经验，让成长有迹可循" width="100%"></p>

<p align="center">
  <a href="https://github.com/wzf2000/wp-xp-core/releases/latest"><img alt="GitHub Release" src="https://img.shields.io/github/v/release/wzf2000/wp-xp-core?color=4f46e5"></a>
  <a href="https://github.com/wzf2000/wp-xp-core/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/wzf2000/wp-xp-core/actions/workflows/ci.yml/badge.svg"></a>
  <img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b">
  <img alt="PHP 8.0+" src="https://img.shields.io/badge/PHP-8.0%2B-777bb4">
  <a href="LICENSE"><img alt="License: GPL-2.0-or-later" src="https://img.shields.io/badge/license-GPL--2.0--or--later-22c55e"></a>
</p>

<p align="center"><strong>为站点参与积累经验，用可配置的等级呈现成长。</strong></p>
<p align="center">简体中文 · <a href="README.en.md">English</a></p>
<p align="center"><a href="#开始使用">开始使用</a> · <a href="docs/GUIDE.md">使用指南</a> · <a href="docs/CONFIGURATION.md">服务器配置</a> · <a href="CHANGELOG.md">更新记录</a></p>

## 你能用它做什么

- **奖励日常参与。** 每日签到、有效阅读、发表文章与合格评论都可获得经验，各活动奖励由管理员设置。
- **鼓励作者创作。** 为文章配置三档浏览里程碑；接入 PageNest Companion 后，还可配置三档点赞里程碑。
- **设计自己的成长曲线。** 用可增删列表设置 1–100 级的最低经验，无需改代码。
- **让用户看见进度。** 账户面板展示当前经验、等级、距离下一级的经验、签到按钮与近期私人记录。
- **保留奖励依据。** 以事件账本记录经验变化，重复请求不会重复领取同一奖励。

经验与等级由插件独立维护，无需 myCRED。点赞服务是可选集成；不可用时，后台会禁用点赞规则，其余设置仍可编辑。

## 开始使用

**当前版本为 1.3.1，面向能管理服务器、已有经验账本的 WordPress 站点。** 插件需要 Web 根目录外的 JSON 配置与兼容存储；上传 ZIP 后仍需完成服务器接入。当前没有面向全新站点的一键初始化向导，启用不会自动建表、迁移历史数据或重算经验。

1. 从 [最新 Release](https://github.com/wzf2000/wp-xp-core/releases/latest) 下载 `wp-xp-core-版本号.zip`，不要使用 GitHub 自动生成的 **Source code** 压缩包。
2. 请服务器管理员按[配置说明](docs/CONFIGURATION.md)核对已有账本、余额、运行选项与 JSON 配置；替换旧经验插件时，先停用旧副本。
3. 在 WordPress **插件 → 安装插件 → 上传插件** 中安装 ZIP 并启用。
4. 在账户页加入配置指定的短代码；默认是 `[reader_experience]`。
5. 打开 **设置 → WP XP Core**，调整规则并保存。详细操作与排障见[使用指南](docs/GUIDE.md)。

已安装的站点可用新 Release ZIP 更新。自动更新器尚未提供；旧版 Reader Experience 的目录切换与升级要求见[兼容与升级](docs/CONFIGURATION.md#兼容与升级)。

## 配置你的经验规则

设置页将规则分为 **日常活动、作者里程碑、等级成长**。可以单独设置奖励、阅读／评论每日次数、里程碑触发次数与每级门槛。

| 活动                   | 默认规则                                                  |
| ---------------------- | --------------------------------------------------------- |
| 每日签到               | +2，每日一次                                              |
| 有效阅读               | 停留至少 15 秒，+1，每日最多奖励 3 次                     |
| 首次发表文章           | 每篇 +20                                                  |
| 合格评论               | 每条 +2，每日最多奖励 3 次                                |
| 作者浏览里程碑         | 100／500／1000 次，分别 +5／10／20                        |
| 作者点赞里程碑（可选） | 10／30／100 次，分别 +5／10／20                           |
| 等级成长               | 默认 10 级：0、5、20、60、150、300、600、1000、1800、3000 |

**奖励变更用于之后首次记账的事件；等级门槛变更会即时影响等级展示。** 修改门槛不会改动余额，保存设置也不会补发奖励。每篇文章每档里程碑只领取一次；降低次数后，尚未领取的档位可能在下一次有效活动时达标。

取值范围、评论资格、计数方式及示例见[规则设置指南](docs/GUIDE.md#设置经验规则)。

## 文档与帮助

| 你想做什么                         | 阅读入口                            |
| ---------------------------------- | ----------------------------------- |
| 设置奖励、添加等级、放置账户面板   | [使用指南](docs/GUIDE.md)           |
| 排查维护提示、未获得经验、点赞禁用 | [常见问题](docs/GUIDE.md#常见问题)  |
| 接入服务器配置、存储或其他插件     | [配置与集成](docs/CONFIGURATION.md) |
| 查看版本变化                       | [更新记录](CHANGELOG.md)            |
| 参与开发或提交改进                 | [贡献指南](CONTRIBUTING.md)         |
| 维护 GitHub 发行                   | [发布流程](docs/RELEASING.md)       |

`main` 中的文档反映当前源码。使用已发布版本时，请以对应 Release 标签内的文档为准。

遇到问题可[提交 Issue](https://github.com/wzf2000/wp-xp-core/issues/new/choose)，附上版本、现象与复现步骤。安全问题请按[安全政策](SECURITY.md)私下报告。

## 作者与许可

由 [wzf2000](https://github.com/wzf2000) 维护，采用 **[GPL-2.0-or-later](LICENSE)**。欢迎通过 Issue 和 Pull Request 参与改进。
