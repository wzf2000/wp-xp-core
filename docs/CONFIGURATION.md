<!-- wp-xp-core-version: 2.2.0 -->

# 服务器配置与集成

简体中文 · [English](CONFIGURATION.en.md) · [返回首页](../README.md) · [使用指南](GUIDE.md)

本页面向集成开发者，描述 **2.2.0**；使用已发布版本时以其标签文档为准。**普通首次安装不需要外部配置：上传 1.4.0+ 安装包并启用即可。** 页面操作见[使用指南](GUIDE.md)。外部 JSON 只用于需要自定义存储或保留已有接入的高级部署。

## 默认安装

没有定义 `PAGENEST_COMPATIBILITY_PROFILE_FILE` 时，插件使用内置配置。首次启用会建立带 WordPress 表前缀的 `reader_experience_events` 账本，并保存自己的安装标记及运行开关；不读取 myCRED 数据，不导入历史活动，不为已有用户批量写入余额。没有余额的用户按 0 经验展示，之后符合条件的活动正常记账。

激活要求数据库支持 InnoDB，且 WordPress 用户元数据、文章元数据和选项表已经使用 InnoDB。插件不会转换其他表的存储引擎。新安装会校验结构与唯一事件键，并通过数据库锁串行化安装；失败不会启用奖励。停用、重新启用保留账本、余额、规则和已有维护开关，不重算或补发经验。普通页面请求不执行初始化。

激励效果统计需要数据库提供 `JSON_VALID` 和 `JSON_EXTRACT` 函数；已验证 MySQL 8.0 与 MariaDB 11.4。较旧数据库需先确认函数支持，不会自动升级数据库。

经验页面从后台手动新建，使用 `[reader_experience]` 短代码。`wp_xp_core_panel_page_id` 保存管理员从设置中选择的公开无密码页面，独立于奖励规则；未指定时，链接使用当前页面或首页。页面创建／发布由管理员完成，插件不会自动发布正文。

多站点支持逐站启用，不支持网络统一启用。原生模式的余额键按子站表前缀隔离，账本和安装选项也各归对应子站；对外读取可使用 `Reader_Experience::balance_meta()` 获取当前键。

## 可选外部配置

定义 `PAGENEST_COMPATIBILITY_PROFILE_FILE` 即选择高级外部接入。其 JSON 必须位于 **Web 根目录（`ABSPATH`）之外**且可读取，顶层包含 `schema_version: 1` 和显式 `experience` 对象。

此模式保持原有配置、账本、余额与面板页，激活不运行原生安装器。外部配置缺失、无经验对象或校验失败时保持不可用，**不会回退到原生空账本**。该常量是保留的共享接口；经验与等级不要求安装 PageNest Companion，只有点赞里程碑需要可用的 Companion 点赞服务。

## 接入核对

仅高级外部部署需要以下核对，普通首次安装可跳过：

1. 确认 WordPress 6.0+、PHP 8.0+，备份受影响配置与存储。
2. 核对事件账本、`reader_experience_balance` 投影及二者一致性，确认事件键唯一、字段兼容。账本、用户与文章元数据表需使用 InnoDB。
3. 准备外部配置，确认既有运行选项存储值为 `1`。该模式的就绪判断本身不校验表结构。
4. 若已有同类经验实现，明确唯一运行者，保留现有页面需要的路由、短代码与调度别名。
5. 在隔离环境验证接入，再执行站点自身的发布流程。

新安装检测到未归属的同名账本、已有经验余额或规则时会拒绝接管，保留数据供显式接入；这不是 myCRED 迁移功能。

## 配置字段

`experience` 对象支持以下十个字段。未填写字段使用 `config.php` 中的默认值；替换旧系统时应明确核对已有名称。

| 字段             | 用途                                            |
| ---------------- | ----------------------------------------------- |
| `table_suffix`   | 已有事件表后缀，自动加 WordPress 表前缀         |
| `live_option`    | 已有经验运行状态选项                            |
| `week_option`    | 已有周轮换状态选项                              |
| `event_lock`     | 账本事务锁，按数据库隔离                        |
| `weekly_lock`    | 共享周榜结算锁的精确名称，最多 64 个 ASCII 字节 |
| `rest_namespace` | 主 REST 命名空间，带登录与权限校验              |
| `rest_aliases`   | 兼容命名空间列表，共用处理器与权限校验          |
| `shortcodes`     | 经验面板短代码名称列表                          |
| `panel_page_id`  | 面板页面 ID；0 使用当前页面或首页               |
| `weekly_hook`    | 已有每周调度钩子；插件不创建定时任务            |

默认名称为通用标识。未知字段、无效标识符与错误别名会被拒绝；`weekly_lock` 必须与成绩结算方的锁完全一致，事件锁按数据库隔离。顶层可用共享字段及具体校验范围以 `config.php` 为准。

## 全站规则设置

后台 **设置 → WP XP Core** 管理活动奖励、阅读／评论每日次数和 1–100 级门槛。取值范围、默认规则与操作步骤见[使用指南](GUIDE.md#设置经验规则)。

规则保存在独立选项 `wp_xp_core_rules` 中；高级部署的外部配置继续负责存储与集成。读取不会创建选项，缺失或无效时使用默认值。保存需要 `manage_options` 和有效 nonce；数据库隔离的建议锁串行化保存，版本摘要拒绝过期表单，无效输入保留原设置。每个事件事务使用一份完整规则快照。

零奖励保留幂等事件，零每日次数停止对应奖励。历史事件不重算，评论撤销与恢复始终使用首次金额；等级变更即时影响展示，不改余额。人工调整与撤销通过管理入口追加账本事件，不直接改投影。

后台按卡片分组并适配窄屏，信息侧栏包含作者、许可与源码入口。`admin_css`／`admin_js` 使用内容哈希，仅在对应插件管理页加载；不引入外部字体、脚本或网络依赖。

### 里程碑标识与等级列表

规则增加 `activities` 活动开关、`scope` 内容范围、`dwell_seconds`（5–3600）与 `timezone`。`scope.post_ids` 和 `scope.category_ids` 任意命中即可；两者为空表示不限，页面不匹配文章分类。已有事件后禁止改时区，避免日键重解释。活动关闭不阻止既有评论奖励按原金额撤销／恢复。

`milestones.view`／`milestones.like` 为每组最多 20 个的有序档位列表，条目包含稳定 `id`、实际 `threshold` 和 `xp`。移除保存后，标识进入 `retired_milestones`，不能复用；新增档位获得独立标识。旧六档规则在内存规范化，保留原档位身份与已领取状态，读取不触发迁移写入。

幂等键仍按文章、类型与稳定档位区分，而不使用可修改的次数。保存不发奖；下一次有效事件按累计账本次数判断尚未领取档位，降低门槛可能使其在该事件达标。删除再添加属于新档位，不能作为恢复旧领取状态的方式。

点赞需同时存在 `pagenest_companion_like` 与 `pagenest_companion_feature`，且 `likes` 功能开启。Companion 配置校验通过后才加载这些函数。服务不可用时，后台点赞字段和运行时点赞奖励禁用；保存锁内会将缺失或伪造的点赞字段替换为已保存值，其余规则仍可编辑。插件不包含独立点赞服务。

等级提交为有序数组，第一个值为只读 0，增删控件重排索引，最多 100 项。服务端校验始终生效；没有 JavaScript 时仍可编辑已有行，增删需要 JavaScript。

## 账本与展示集成

`reader_experience_balance` 是唯一现用余额投影，事件账本是增减依据。不写累计或等级元数据；按当前门槛即时计算等级。默认十级最低经验为 `0、5、20、60、150、300、600、1000、1800、3000`。事务外的普通元数据添加、更新或删除会被拒绝。

| 接口                                              | 行为                                                         |
| ------------------------------------------------- | ------------------------------------------------------------ |
| `pagenest_like_recorded(user_id, post_id, count)` | 以 `like:user:post` 零经验事件幂等记录点赞，再判断作者里程碑 |
| `site_tools_account_panel`                        | 就绪时提供经验面板，否则保留传入内容                         |
| `site_tools_user_level($fallback, $user_id)`      | 就绪时返回 `Level N`，否则保留原值                           |

Companion 原子保存点赞总数与新用户记录，经验记账另属独立事务；后续幂等点赞重试可重投递之前失败的经验事件。集成方无需引用插件实现类，等级读取不依赖旧等级文章或第三方提供者。旧点赞 REST 别名转交 Companion 处理；停用经验系统不影响 Companion 的点赞服务。

## 管理、成长与成就存储

管理入口要求 `manage_options`，写操作还检查 nonce、操作身份与目标用户权限。`manual_adjust` 和 `manual_reversal` 追加事件，包含原因和操作人；撤销仅针对未撤销的人工调整，不能令余额为负。诊断只读比较账本与投影，不作原地修复。失败列表保留明确捕获的首次文章／评论奖励及全额评论补偿失败，重试重新校验原事件归属与当前资格，不是历史补发接口。`wp_xp_core_rule_audit` 和 `wp_xp_core_failed_events` 分别有最多 200 项的保留上限，不是永久完整操作日志。

`wp_xp_core_growth` 保存等级展示资料与可选签到阶段规则。阶段奖励通过新一代规则及起始事件边界限定，只累计之后的新签到；读取个人页面不写奖励。`wp_xp_core_achievements` 保存版本化任务，最多 50 项（含停用项），自动任务最多八步。任务支持一次性／每日／每周／每月周期，周一开始一周，每月一日开始。展示文字与字符图标修改保留版本及起始事件边界，条件、周期或启用变化建立新版本。自动任务与人工审核默认授予零经验荣誉，可选完成经验。成就记录保存名称和图标快照；修改任务不改写已获得成就。

步骤字段为 `kind`、`count`、`mode`、`post_ids`、`category_ids`；旧 `kind/count` 步骤读取时仍按 `events` 计数且不限范围，不自动保存。`mode` 接受 `events`（事件）、`objects`（不同文章／页面，评论映射父内容）或 `days`（经验时区自然日）。签到不接受内容范围或 `objects`。两项 ID 列表各最多 100 项、正整数、任意命中；所有内容计数要求当前公开无密码的文章／页面，评论还须当前批准、保持事件用户归属，且不能是作者自评或回复自己的评论。

任务可选 `starts_on`／`ends_on` 使用有效 `YYYY-MM-DD` 或空字符串，经验时区且包含首尾；自动完成、领取、提交与批准均检查。`prerequisites` 最多八项 `{id, scope}`，`id` 引用已保存任务；`current` 要求前置任务当前版本、当前周期的成就，`lifetime` 接受任意版本的已获荣誉。保存事务内检查缺失引用、重复与循环。条件限制完成／提交，不改变本版活动累计起点。读取不写入，保存不补历史。

个人历史、任务提交及审核状态按当前用户隔离；不要将带登录信息的个人响应用于公共缓存。额外签到奖励默认关闭，无 cron、全历史扫描或自动补发流程。前端初始个人信息由服务端生成，交互和分页由 JavaScript 增强。

提供者步骤使用 `{kind: "provider_reward", provider_id: "course", mode: "events"|"objects"|"days", count: 1..1000}`，旧四类步骤的结构保持。`provider_id` 必须来自已注册来源，已保存任务可保留原有但暂缺的来源；新增任务或新模板不得引入未知来源。预览、保存和配置导入共用校验。提供者步骤拒绝 `post_ids`／`category_ids` 范围；对象计数只统计该来源的 `object_id>0`，不把它当作 WordPress 文章。

进度与任务参与统计复用同一 SQL 资格谓词：用户、来源、`start_event_id`、日期／周期，以及不存在对应 `provider_revoke` 的原始 `provider_reward`。0 XP 有效回执计入；重复事件和已撤销原记录不计入未领取进度。来源是否加载不改变已有账本资格，读取失败须报错，不能视为空进度。提交后仅匹配相同来源的新回执触发任务处理，撤销使下一次读取反映有效活动；已获得的成就不级联撤销。无新存储、迁移、旧奖励重算或前端任意事件入口。

`reward_xp` 为 0–1000000 整数，缺失按 0；变更建立新任务版本，已有成就不补奖。`image_id` 为 0 或有效的公开图片 attachment ID；只接受 JPEG/PNG/GIF/WebP/AVIF，父内容须公开无密码，读取时重新检查并回退字符。`category`／`series` 分别最多 40／60 字符，属于展示资料，不改变版本。授予事件保留展示与条件快照、原 XP，获取时间取原事件时间。

自动／审核使用 `task_complete`，人工授予使用 `task_grant`；共享 `task:{id}:{version}:{period}:{user}` 幂等键，成就与奖励在一个事务提交。管理员 `WP_XP_Core_Achievements::manage()` 要求有效管理 nonce、目标用户权限、原因和 UUID 请求编号；授予还要求当前任务版本与周期、日期和前置条件。重复请求绑定规范化输入摘要，不能改目标或金额。所有奖励检查整数溢出；人工授予经验排除活动榜。

撤销使用 `task-revoke:{原事件ID}`，分别追加 `task_revoke`／`task_grant_revoke` 及原 XP 的反向金额；余额不足不写任何事件。`achievement_admin` 是零经验操作回执，不进入活动来源。读取、前置条件、个人徽章与完成率统一排除已撤销荣誉；原键保留，不允许同版本周期重新授予，也不自动级联撤销后续成就。旧无奖励定义与历史零经验荣誉仍可读、可撤销，不做历史写入。

## 上限、榜单参与与评估

`rules.activity_caps` 为 `{day: 0, week: 0}`，整数范围 0–1000000000，0 不限。`WP_XP_Core_Fairness::FIRST_REWARD_KINDS` 独立于榜单来源，仅累计首次活动正向实际 XP：签到、阅读、文章、评论、两种里程碑、签到阶段、`task_complete` 与 `provider_reward`。`comment_adjust` 的正向恢复与所有反向补偿、人工、迁移均不计入或受限。`Reader_Experience::record()` 在同一锁与事务内读取当期 gross 总额，按更小的日／周余额截断，再保存 `detail.activity_cap` 请求额、实际额、限额、既发 gross 与截断标识。0 XP 事件仍幂等，后续不补齐；旧事件无元数据也按原实际发放计本期额度。

`record()` 在 INSERT 前拒绝负余额与整数溢出，`set_balance()` 拒绝负值。评论补偿通过 `reconcile_comment()` 在同锁内重新检查原事件、当前评论或删除、原实际 XP 和净额；不足则整笔拒绝并捕获 `comment_adjust` 失败。管理员 retry 先验证 canonical 原事件与失败归属和目标权限，再重算 delta，不能重放保存金额／键；补偿、失败解决与管理回执同事务。恢复批准但原净额仍在时 resolve/noop；永久删除也可受控重试。不建立负余额、部分补偿或自动追债，旧漂移须由只读诊断定位，不自动修复。缺余额 meta 且账本合计为 0 属于隐式零一致，仍返回 `balance_present: false`；非零缺失与重复 meta 继续报错，读取不补投影。

本人榜单偏好位于 `{prefix}wp_xp_core_public_board` 用户元数据，缺省参与；`POST /participation` 读取，`POST /participation-settings` 仅接受当前用户的布尔 `participating` 与准确 `revision`，沿用 nonce、4000 字节与私有缓存要求。管理员 `wp_xp_core_board_exclusions` 保存最多 1000 个排除 ID；保存要求管理 nonce、目标权限和版本校验。公开榜和本人位置统一排除，`/position` 增加 `participation: active|opted_out|excluded`；不改变余额、私人记录或来源统计。

管理员 `trends()` 使用前一等长连续时段及按日净额，不宣称因果／留存；`anomalies()` 最多 50 条，条件为同日首次活动至少 20 次或上限截断，只作线索。`task_history()` 最多 100 个版本，汇总可靠事件授予／撤销和当前有效数量；`participants` 恒为 null，未知旧定义不补分母。新授予 `version_created_at` 是定义创建到获得的墙上时间起点，旧快照缺失时耗时留空。

个人任务在一次调用内批量读取有效／已撤销荣誉与公开目标，前置判断只复用该调用局部事实；提交后处理按同一快照批量计数，授予后更新局部事实，事务外不缓存资格。单任务步骤把 kind/id/date 条件留在 WHERE，使用现有范围索引。待审提交在 LIMIT 前用 JSON 字段有效性谓词过滤，旧不完整行不会隐藏后面的合法待审，也不修改原事件。

## 私人通知与目标接口

`notifications.php` 从可信账本事件派生收件箱，没有独立发奖或通知队列表。新事件在原事务保存 `notification_schema: 1` 和 `level_transition.before/after`；升级消息只接受 after 严格大于 before。成就和审核消息使用原事件快照。钩子丢失或通知查询失败后可重新读取，不影响已提交经验；旧无通知快照事件不补消息，读取不保存、不奖励。

主命名空间及兼容别名提供 `POST /notifications` 和 `POST /notification-settings`，沿用登录、`read`、`X-WP-Nonce` 和 `private, no-store`／`Vary: Cookie`。仅当前用户，无目标用户字段，请求体最多 4000 字节。查询接受 `limit`（1–50）和可选 `cursor`（`事件ID:task|review|level`）；同一事件的成就与升级消息可分页而不遗漏。返回 `items`、`next_cursor`、当前观察边界 `through`、三类布尔 `preferences` 和 `revision`。

更新要求准确 `revision`，可提交完整 `preferences: {task,review,level}`，或最多 50 个当前用户消息 `ids`，或 `through` 标记全部已读，不能混合这些操作。偏好和已读状态存于当前子站前缀的用户元数据键 `{prefix}wp_xp_core_inbox`，在账本事务锁内重新读取并比较版本，过期请求返回 409。逐条已读最多保留 2000 条边界之后的例外；全部已读会清理覆盖范围。前端按请求顺序丢弃旧响应，保存期间阻止旧读取覆盖新状态。

任务个人响应增加公开 `scope_links`、计数模式和前置提示；不返回受限内容的名称／链接，也不把链接访问当作有效活动。所有这些读取保持只读。

## 榜单与效果统计接口

`[xp_leaderboard period="week" limit="10"]` 是独立的手动公开入口，`period` 还支持 `month`，每页 1–50 人。它不由外部配置中的 `shortcodes` 字段改名，也不会创建页面。统计使用当前规则的经验时区与账本 `local_day`；周一／月初至今日的活动净经验必须大于 0。同分按 1、1、3 排名，同分记录按用户编号稳定排序，但该编号不进入公开行。单次 SQL 在同一数据库快照内计算该页、总人数与名次；offset 翻页之间的新活动仍可能移动结果。

`WP_XP_Core_Insights::leaderboard()` 仅接受 `period`、`limit`（1–50）、`offset`（0–1000000）。公开行只返回 `display_name`、`author_url`、`xp`、`rank`，另有日期、时区和分页信息；不返回用户编号、登录名或个人记录。已删除用户及多站点中不再属于当前子站的用户不进入公开榜。参与来源是 `checkin`、`visit`、`article`、`comment`、`comment_adjust`、`milestone_view`、`milestone_like`、`streak_reward`、`task_complete`、`provider_reward`、`task_revoke`，含负补偿；不使用余额投影排名。

个人位置使用主 `rest_namespace` 及全部 `rest_aliases` 下的 `POST /position`，沿用原个人接口权限检查。要求登录、`read` 权限及有效 `X-WP-Nonce`；JSON 对象仅支持可选的 `period` 字段（`week` 或 `month`，默认 `week`），例如 `{"period":"month"}`；不接受目标用户编号或其他字段，正文上限 1000 字节。服务端强制读取当前用户，响应的 `position` 是公开行格式或无排名时的 `null`。成长面板用 JavaScript 调用此接口；读取不产生事件，私人响应不可进入共享缓存。

`WP_XP_Core_Insights::sources()` 与 `task_rates()` 仅供具备 `manage_options` 的管理员调用，不注册公开统计 REST 路由。来源接受 `week`、`month` 或 `custom`；自选 `from`／`to` 使用 `YYYY-MM-DD`，包含首尾、最多 366 天且不晚于今日。自动时段不接受自选日期字段。来源包含上述活动及独立的 `manual_adjust`、`manual_reversal`、`task_grant`、`task_grant_revoke`，零 XP 有效活动计入事件与人数；总人数跨来源去重，历史删除用户的记录只保留在脱敏汇总中。历史迁移、校准与管理操作回执不在统计范围内；零经验任务荣誉仍计入事件与人数。

任务统计只读取当前启用定义、当前版本和当前周期。分母是匹配活动／审核提交用户与完成者的去重并集；分子按准确的任务、版本、周期成就事件识别完成者。零分母返回 `rate: null`，不受来源日期参数影响。用户成员范围与公开榜一致。统计不保存排名元数据，不建表、迁移、索引、缓存或 cron，不改变余额、任务进度及奖励行为。

## 开发者事件契约

成功写入的新事件携带 `detail.rule_revision`。事务提交且事件锁释放后，调用 `wp_xp_core_event_committed($event)`；若该事件的前后等级不同，还调用 `wp_xp_core_level_changed($event)`。修改等级设置本身不生成用户事件，也不会逐用户触发等级钩子。

`$event` 包含 `id`、`key`、`user_id`、`kind`、`object_id`、`xp`、`day`、`detail`、`before_xp`、`after_xp`、`before_level` 和 `after_level`。它描述该事件记账时的变化，多个事件同批提交时不等于稍后重新读取的最终余额。重复事件与回滚事务不分发这些钩子。

观察者异常会记录错误，但不回滚已提交奖励，也不将成功记账报为失败；后续事件仍继续分发。由于采用原生 WordPress `do_action`，同一次钩子分发中某个监听器抛异常后，排在它后面的监听器可能不执行。监听器应自行处理异常、保持幂等，不应依赖这些通知实现必需的原子奖励。插件原生派生奖励（例如签到阶段奖励）与源事件在同一事务内处理；外部监听器若再写入，属于新的独立事务。

## 兼容与升级

1.4.0 为新站提供原生安装；已使用有效外部配置的站点更新时保持原接入，不执行建表、数据重算或原生初始化。

**从 1.1.1 升级：** 无需数据迁移。已有完整自定义规则在内存补齐默认里程碑次数，保留奖励和等级，不自动保存。没有自定义规则时继续使用默认值。`Update URI` 标识源码仓库，可选官方更新检查默认关闭，在配置工具显式启用。

**从旧 Reader Experience 切换：** 1.1.1 将入口改为 `wp-xp-core/wp-xp-core.php`。先停用 `reader-experience/reader-experience.php`，再启用新入口，避免同时加载。WordPress 将两者视为不同插件，服务器提前加载引用也须同时切换。保留原配置、账本、余额、选项与定时钩子；改名不执行初始化或数据迁移。

**从 1.1.0 之前的存储迁移：** 需将已有余额精确迁移至 `reader_experience_balance`，并从外部配置移除已退役 `rank_option`。当前版本不自动执行该迁移。保留历史页面需要的路由与短代码别名；已退役配置字段会使经验系统禁用。

`Reader_Experience` 类、`reader_experience_*` 函数、默认 REST 命名空间与短代码、CSS 选择器、JavaScript 全局对象和资源句柄属于兼容接口，不是待替换的插件名称。

## 可选旧系统集成

`weekly_hook`／`weekly_lock` 保留已有每周游戏成绩轮换，使用与成绩结算相同的锁；它不发经验，也不属于设置界面。仅在对应成绩元数据与调度已存在的站点接入。该兼容实现保持不变。

## 便携配置与编辑器

`WP_XP_Core_Configuration::export($nonce)` 仅导出 `schema:1`、`rules`、`growth`、`tasks`；nonce 为 `wp_xp_core_configuration`，要求 `manage_options`。`preview($json, $mode, $nonce)` 最多接受 4 MiB（`MAX_BYTES`）、50 项任务，拒绝未知字段及内部 version／start_event_id／generation／安装或存储状态。`mode` 为 `update`（同站已知 ID）或 `template`（外站全量重映射），不删除未列出的本站任务。本站维护最多 1000 项非导出映射，使模板重复应用不会创建重复发奖身份。模板模式的里程碑同门槛匹配本站身份，退休身份继续拒绝。

预览返回逐字段旧／新差异、任务变化、各配置 revision 和绑定当前管理员、文档、模式与插件版本的 HMAC `proof`。`apply($json, $mode, $revision, $proof, $nonce)` 先取规则锁再取既有账本锁，锁内重新鉴权并核对全部 revision，校验图和未来边界，再以同一 InnoDB 事务写规则、成长、任务、映射和审计；任何失败回滚所有写入。调用方不能提交奖励事件、余额、表名、REST 路由、管理员账号或历史版本。模板只有显式应用才创建任务，默认 reward_xp 为 0。导入不会重算、补历史或修改已获快照。

`wp-xp-core/growth` 和 `wp-xp-core/leaderboard` 为动态区块，复用 `reader_experience` 与 `xp_leaderboard` 短代码。编辑器脚本不读取私人 API；旧短代码契约保留。

## 可信活动提供者

仅受信 PHP 插件调用，无对应用户提交 REST 路由：

```php
add_action('init', function () {
    wp_xp_core_register_activity_provider('course', [
        'label' => '课程完成',
        'authorize' => function (int $uid, int $object_id, array $context): bool {
            // 在服务端查验自己的完成记录、归属与当前资格。
            return my_course_completion_is_verified($uid, $object_id, $context);
        },
    ]);
});
// 在服务端验证完成后，以稳定来源事件 ID 投递；失败可用相同 ID 重试。
wp_xp_core_record_activity('course', 'completion-123', $uid, $course_id, []);
```

注册项须有 label 与 authorize，可选字段见下方活动与通知接口；不能在调用中指定 XP 或任意事件 kind。管理员在经验规则中显式启用提供者并保存 XP（0–1000000），默认关闭。规则 `providers` 为至多 20 个标识到 `{enabled, xp}` 的映射。回调必须严格返回 `true`，在锁内检查；context 不保存到账本。固定 `provider_reward` 使用提供者与稳定事件 ID 哈希幂等，复用 ID 改用户或对象拒绝；错误回滚且可重试。该来源属于首次活动，纳入日／周 gross 上限、来源统计、周／月活动榜和私人记录；可以作为按来源区分的自动任务步骤；任务完成奖励本身不会反向触发提供者任务循环。记录只保存提供者标识／名称及标准奖励／上限快照。

## 管理员奖励解释接口

`WP_XP_Core_Reward_Inspector::inspect($input, $nonce)` 要求管理权限、`wp_xp_core_manage` nonce、系统就绪及目标用户权限。`{mode: "receipt", event_id}` 只读取原事件与白名单奖励快照；不返回 context、私人通知正文或管理员原因，不套用当前规则。`{mode: "simulate", user_id, kind, object_id, provider_id?, event?, score?}` 只模拟四类内置活动或 `provider_reward` 的新主体回执，活动日期使用当前经验时区。稳定扩展事件编号用于读取去重；分数仅是假设输入。内置对象按目标用户、归属与范围核对，真实请求和扩展权威资格仍待验证。

输出包含 `checks`（通过／不满足／待验证／已有回执／零经验）、规则请求额、次数限制后、暂停后及 `hypothetical_xp`；`may_record` 为 false（已知不新增）、true（签到当前条件允许）或 null（存在未验证资格）。扩展的 `authorize`、`facts`、`available` 与通知 `deliver` 均不调用；清单 `capabilities($nonce)` 只检查注册元数据和已保存配置／任务引用。结果是当前非锁定观察，不承诺之后实际发奖。

暂停 `Reward_Controls::evaluate()` 与经验上限 `Fairness::evaluate()` 为复用的纯计算，`Fairness::totals()` 只读当前 gross；真实 `apply()`／`cap()` 的事务要求保持。模拟不调用记账、保存或事务入口；查询失败返回 storage 错误，不当作空额度。原始暂停、额度、余额、任务、配置与历史规则均不改变。

`Configuration::templates()` 为每个已注册来源生成稳定身份的 events／days 月度任务模板；默认 `enabled:false`、`reward_xp:0`，不改变来源政策。沿用既有模板预览、HMAC proof、revision 和显式 apply；新模板来源缺失时校验拒绝，不暗建来源、不补旧经验。

## 翻译、隐私与更新接口

Text Domain 为 `wp-xp-core`，catalog 位于 `languages/`，支持 `zh_CN` 和 `en_US`；`wp_xp_core_t()` 对固定文案调用标准 gettext。`tools/languages.py` 离线编译已审核 PO 为 MO 和 JSON，`--check` 验证生成一致性。JS 通过小型 `WPXPI18n` 桥获取 gettext 文案与带位置占位符的格式串，不转换用户内容或整个 HTML。语言文件与 `modules/` 均列入发布 ZIP、独立发布校验和真实 WP 安装白名单。

WP 隐私 exporter `wp-xp-core` 按邮箱归属导出本站最多 50 条事件／页（再读一条确定结束），加本人余额、榜单状态与通知偏好。仅白名单事实，排除管理员 reason／actor 和他人字段；不存在自动 eraser、cron 或读时写入。停用与删除插件文件不删除数据；保留政策见指南，匿名化／清账需另行设计。

`wp_xp_core_updates` 是独立默认关闭的更新偏好，后台 nonce／权限／revision 校验；不随便携配置导入。检查固定 GitHub API 最近 30 个 Release，响应上限 256 KiB、超时五秒、不跟随重定向，成功缓存六小时、失败十五分钟。只接受官方 tag 与对应准确命名、固定域路径且不超过 32 MiB 的安装 ZIP；稳定渠道排除预发布，预发布渠道可选更高稳定版或候选，以 `version_compare` 拒绝降级。关闭后忽略残留更新项，不添加定时任务。更新仍由 WordPress 常规权限与升级流程控制。

多站点网络不提供本轮可选更新器；该网络的插件由网络管理员手动更新，子站偏好不会影响网络共享的插件更新结果。

切换更新渠道后，读取缓存会立即排除不符合当前渠道、版本或官方附件地址的本插件候选，不触及其他插件更新。活动提供者首次与重复投递均返回同一个真实账本 `event_id`，不使用余额投影的元数据行号。

## 数据库要求与读取失败

事务存储须使用 InnoDB，数据库须提供 `JSON_VALID`、`JSON_EXTRACT`、`JSON_UNQUOTE`、`JSON_TYPE`。激活会在安装写入前探测这些函数；失败时请服务器管理员提供兼容数据库，再重试激活。插件不会转换或接管表。

去重、每日额度、里程碑、评论原奖励或响应历史读取失败时，相关奖励操作中止并回滚。恢复数据库访问后重试原活动，不手改余额或重跑初始化。个人数据导出包含审核决定、反馈及关联提交编号，不包含内部管理员身份或管理原因。

## 活动撤销与私人通知源

`wp_xp_core_register_activity_provider()` 的定义可增加可选 `revoke_authorize($user_id, $object_id, $context): bool`。原 `authorize` 继续控制新奖励；撤销不会依赖当前奖励开关或金额。调用 `wp_xp_core_revoke_activity($provider, $original_event, $user_id, $object_id, $context)`，插件在同一账本事务锁内检查提供者、原事件归属及实际金额，追加 `provider_revoke`，其 `object_id` 指向原账本事件。固定撤销键保证重复请求不会再次扣减。余额不足返回 `balance`（409），不落部分事件；数据库失败返回 `storage`（503），不得把失败当成撤销成功。零经验原凭据也可撤销。服务端调用者负责提供可信上下文，不能把浏览器提交的身份或金额直接转发。此 API 不重新发放已撤销的奖励。

bbPress 使用 `bbp_new_topic`、`bbp_insert_topic`、`bbp_new_reply`、`bbp_insert_reply`，并要求同一请求确有启用后的新对象插入；直接重放旧对象钩子不发奖。只对当前变化的主题／回复尝试撤销，不扫描父级后代。公开函数与钩子能力检测在 `plugins_loaded` 后执行，其他插件先后顺序不构成硬依赖。

注册通知源：

```php
wp_xp_core_register_notification_source('example', [
    'label' => 'Example inbox',
    'available' => static fn(int $uid): bool => example_inbox_enabled(),
    'listing' => static fn(int $uid, string $cursor, int $limit) => example_private_items($uid, $cursor, $limit),
    'mark' => static fn(int $uid, string $id) => example_mark_owned_item($uid, $id),
]);
```

XP 先验证登录、`read` 权限和 REST nonce，再把当前会话用户传给回调，不接受请求中的用户编号。最多十个可选源，ID 为 `[a-z][a-z0-9_]{0,31}`；`xp` 保留。列表返回 `items`（不多于 limit）和 `next_cursor`（字符串或 null）；每条只使用字符串 `id/title/message/day` 与布尔 `read`。游标／ID 最长 191 字节，limit 为 1–50 的整数。来源须每次检查对象归属和可见性，使用有界查询，不返回管理原因、秘密字段或任意 HTML；失败返回 `WP_Error`。已读回调自行验证原来源编号与归属，返回结果或错误。通用接口不提供跨源事务或跨源游标。

新增私人 POST action 为 `notification-sources`（空参数）、`notification-source-list`（source、可选 cursor/limit）及 `notification-source-read`（source/id），沿现有 REST namespace 和私人响应策略。Companion 适配只在其公开读取／已读能力可用且通知开启时显示，使用原公开函数，不通过 REST 过滤器改写其响应或直写其表。

暂停状态为用户元数据，审计沿用现有管理审计保留上限。它不进入配置导出／导入，不复制到其他用户。`reward_hold` 事件字段仅包含 requested_xp、until、held；个人数据导出包含该凭据，不包含管理原因。不会新增表、索引、cron、后台自动采样、历史重算或第三方网络请求。

通知项可带可选 `url`，最长 2048 字节，仅允许 HTTP／HTTPS；链接仍须由来源按当前可见性生成。

bbPress 内部通过 `WP_XP_Core_Providers::revoke_registered_receipt()` 在账本锁内解析原获奖者，管理员改作者后仍撤销原凭据，不转移经验；可信删除钩子无需当前登录 actor。

## 可配置可信活动与渠道契约

provider 定义兼容原 `label/authorize/revoke_authorize`，可选增加 `description`、`available(): bool`、`fields: ['score']`、`facts($uid,$object_id,$context): array`。`authorize` 会收到可选第四参数原始 event 字符串；旧三参数用户函数保持兼容。可信 facts 必须返回整数 `created_ms`，声明 score 时还必须有非负整数 `score`；仅允许这两个字段，不持久化原始 context。声明 score 必须同时提供 facts 回调。无 facts 的旧 provider 保持其原资格校验责任，不声称能推断任意外部事件的发生时间。

`rules.providers[id]` 可带 `day_limit`（0 停止，旧缺省／null 不限）、`min_score`、`inbox`、`template` 和 `channels`（至多十个稳定渠道 ID）。边界存于独立服务器 option，与规则在同一事务保存；导入／导出不携带该 option 或回调。首次启用、资格／奖励变化、重新启用会重建边界。通知文案变化不重启，旧缺省与等价显式值也不重启。新增 facts 的旧启用来源若缺边界，会拒绝记账；管理员原样保存规则即可建立起点，不自动初始化或扫历史。每日 COUNT 与写账共用锁内取得的自然日；零经验耗尽凭据不恢复额度，重复事件不消耗新次数。

注册其他渠道：

```php
wp_xp_core_register_notification_channel('example', [
    'label' => 'Example channel',
    'available' => static fn(): bool => example_channel_ready(),
    'deliver' => static function (int $uid, array $message) {
        // message: event_id, kind, title, message. Verify any destination in this extension.
        return example_deliver_activity($uid, $message); // true or WP_Error
    },
]);
```

最多十个渠道，稳定 ID 不可覆盖。回调只在新活动账本提交后尝试；当前用户 activity 偏好读取失败时不投递。回调异常／失败不影响经验，不自动重试，也不由相同事件重放。旧结果行只保留事件 ID、渠道、时间与受限错误码，继续只读兼容。新投递状态与显式重试的 revision、次数、操作人及原因保留见下方 “通知投递与显式重试”，不保存秘密、消息正文或原始异常；不要把这些内容放入错误码。此接口不包含既有 task/review/level 消息，不包含邮件、webhook 或网络实现。

游戏适配只调用 `site_tools_game_activity_catalog()` 与 `site_tools_game_settled_receipt($run,$uid)`，使用 `site_tools_register_game_settled_observer()` 优先接收提交后事件。`event` 必须与权威 run 相同且 object_id 为 0，避免换 event 绕过去重。最多二十项 provider，超额游戏明确显示未注册；未装新游戏包不会影响插件独立使用。

## 私人成长组件接口

`WP_XP_Core_Widgets::render($type, $attributes)` 与短代码 `xp_summary`／`xp_tasks`／`xp_badges`／`xp_notifications`／`xp_path`、动态区块 `wp-xp-core/{summary,tasks,badges,notifications,path}` 共用渲染。要求当前用户 `read`、服务就绪及私人缓存响应；匿名仅登录入口。无目标用户参数。属性只有 `limit`（1–20，默认 5）、`density`（compact／comfortable）、`title`（纯文字 ≤80）、`accent`（#RGB／#RRGGBB），path 可加 `series`（精确匹配 ≤60）；不支持 HTML 或任意 CSS。

个人任务、概览、通知投影只在当前请求缓存成功结果；按当前用户、子站编号与表前缀隔离；切用户、switch_blog、提交后事件、本人元数据与相关配置变更失效。读取失败不缓存为空。多个任务／成就／路径组件复用同一 personal 投影；通知组件只读既有 XP 私人消息，不遍历其他外部通知源，不提交已读，也不加载完整交互面板脚本。编辑器只显示静态说明。

## 通知投递与显式重试

`WP_XP_Core_Notification_Delivery::recent($nonce)` 合并读取旧 `wp_xp_core_channel_results` 与惰性创建的 `wp_xp_core_channel_delivery`，最多展示 50 项。新 option 保存 ≤50 个 event/channel 状态、revision、次数、管理员原因与 actor、顺序和淘汰边界；旧格式不迁移、不改写。`retry({event_id,channel,revision,reason}, $nonce)` 要求管理权限、`wp_xp_core_manage` nonce、原接收人权限、原 `activity_message/activity_channels` 快照、当前本人 activity 偏好及渠道可用。无需当前 provider 代码或当前文案；不调用 record、provider 或奖励保存入口。

独立命名锁覆盖状态检查与调用，revision 拒绝同时重试同一失败状态。调用前持久保存 unknown；true 记 delivered，明确 false／WP_Error 记 failed，其余结果、异常、中断或终态写失败保持 unknown。只有当前明确 failed 可以显式再试；delivered／unknown 不允许。淘汰后的旧消息不重新投递，仍保留的明确失败行不受淘汰边界阻挡。外部回调可在提交后记录另一个可信事件；终态合并最新持久状态，内层淘汰的外层条目不会复活。渠道必须以相同 event_id 幂等，不保证跨系统严格一次。默认提交后投递的锁／存储异常被隔离，不阻断后续任务观察者，也不自动重试或新增 cron。

## 具体来源聚合与汇总导出

`Insights::provider_sources($input)` 使用与 sources 相同的 week／month／custom（≤366 天）日期校验，通过一次有界 GROUP BY 按 `detail.provider` 汇总，最多 100 行并返回 more。活动／撤销数含 0 XP，人数在来源内去重，正／负／净经验按所选时段的真实事件统计；不输出用户或个人事件。有效零经验原因只分类未撤销的 `provider_reward` 且 `xp=0`，优先级为额度、暂停、上限、规则零 XP、其他；不将正向部分截断误计为零，不用当前配置推断旧原因。撤销资格判断与任务步骤共用原始回执谓词。

来源关联当前启用任务的既有 task_rates；混合来源标记 mixed_sources，不作单来源归因。页面复用已获取的 task_rates；CSV 内部跳过任务统计和映射，仅执行相同来源聚合。`provider_csv($input,$nonce)` 要求 manage_options 及 wp_xp_core_insights_export nonce，超过 100 个来源拒绝导出；后台通过受保护的 admin_post_wp_xp_core_provider_csv 下载。固定列仅含来源、日期和汇总数字，UTF-8 BOM、标准 CSV 引号／换行、文本公式与控制字符处理，数字负值保持数字。

每次 personal () 内按 provider_id 复用一次 available 观察，包含 false 和异常结果；下一次调用重新观察，不做跨请求或跨用户缓存。匿名前台保留 reader_experience 已注册句柄、ReaderExperience、WPXPI18n／WPXPLocale 和 CSS，通过无 src 句柄输出兼容 inline，省略不需要的 app.js 网络资源；登录行为与原配置保持。小组件动态块筛选业务属性后调用严格渲染器，允许 WordPress metadata，短代码仍不接受目标 user_id。未配置有效完整面板时不生成指向自身的完整面板链接；原 URL helper 的回退契约保持。

可选 scale 沿原 1 万／5 万行读负载核对来源聚合和 CSV 查询数；合成种子不代表真实奖励。查询、统计和导出不建表、索引、统计缓存或定时任务；来源和任务读取失败均返回错误。
