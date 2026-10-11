# 会员晋级（Member Promotion）

适用于 Flarum 1.8 的可配置、多阶段、可审计会员晋级扩展。包名 `dunea/flarum-member-promotion`，扩展 ID `dunea-member-promotion`。

扩展只管理用户组身份；发帖、上传等权限仍由 Flarum 权限表和 FoF Upload 管理。它不会自动创建业务用户组，也不会覆盖用户的完整组列表。

## 状态

- 后端、数据库迁移、管理 API、CLI、Mithril 后台页、中英文翻译和生产前端构建已完成。
- 已在 `crazymax/flarum:1.8.19`（PHP 8.4.21）和 MariaDB 10.11.19 上通过 39 个 PHPUnit 测试、112 个断言；前端生产构建与 TypeScript 类型检查也已通过。
- 已实际构建派生镜像，并确认 Composer 能从本地 path repository 安装扩展。
- 已提供 PHPUnit 单元/集成测试和 10 万用户、100 万帖子规模测试生成器。
- 已完成 1 万用户、10 万帖子、三阶段规则的 MariaDB 单 worker 快速压测；10 万用户、100 万帖子规模测试及真实多 worker 压测尚未执行，不应把快速压测结果线性外推到生产环境。

## 功能

- 任意多条规则；注册时长（小时/天）和有效回复数支持 `>`、`>=`，启用条件之间为 AND。
- 可选前置组、目标组、显式移除前置组；内置组 ID 1–4 不能作为规则组。
- 依赖拓扑排序，依赖优先；同层按优先级降序、规则 ID 升序，单批可连续晋级多阶段。
- 保存时检测直接/间接循环以及重复目标组造成的来源冲突。
- 自动晋级默认关闭。保存规则只创建可恢复后台任务，不在 HTTP 请求中扫描全站。
- 增量有效回复缓存、去重待办、注册时长到期唤醒、稳定 ID 游标、数量/时间双预算、数据库认领、三次重试。
- 插件授予来源、人工撤销阻止、用户暂停、排除组、dry-run、状态变化审计日志、运行心跳。
- 规则版本快照；结构性修改先预览确认，历史成员不会被迁移或降级。复制和删除规则均要求二次确认；删除只从管理列表移除并停用规则，审计所需的历史数据继续保留。

## 有效回复口径

第一版全站统一采用以下口径：

1. `posts.type = comment` 且 `number > 1`，排除主题首帖和系统事件；
2. 帖子和主题均未隐藏；
3. 帖子和主题的 `is_private = false`；
4. 安装 `flarum/approval` 且字段存在时，帖子和主题均须 `is_approved = true`；
5. 作者必须存在。

创建、隐藏、恢复、删除和审核通过使用增量计数。主题隐藏、恢复及硬删除会通过持久化参与者索引创建分批失效任务，再按用户重建。未初始化或已失效的计数不会以 0 参与晋级。

`is_private` 是 Flarum 1.8 的扩展协作字段，本扩展保守排除私密内容。没有为特定私密讨论扩展建立显式集成；第三方若不使用核心的 `is_private`/隐藏字段，其内容语义不在保证范围内。

## 规则示例

先在 Flarum 后台创建“正式会员”“活跃会员”“资深会员”三个自定义组，再创建：

| 规则 | 前置组 | 注册时长 | 有效回复 | 目标组 | 移除前置组 |
|---|---|---:|---:|---|---|
| 正式会员 | 无 | `> 3 天` | `> 10` | 正式会员 | 否 |
| 活跃会员 | 正式会员 | `>= 30 天` | `>= 100` | 活跃会员 | 按需 |
| 资深会员 | 活跃会员 | `>= 90 天` | `>= 500` | 资深会员 | 按需 |

人工授予的目标组被视为该阶段已完成，可参与下一阶段，但不会伪造为插件授予。移除前置组时，只有插件能确认唯一有效授予来源时才会移除；来源不明则保留并写日志。

## 后台任务

宿主机已有每分钟 `schedule:run` 时，无需 Redis、队列 worker 或额外 cron。验证：

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum schedule:list
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum member-promotion:status
```

“尚未检测到运行”只表示扩展尚未写入心跳，不代表调度器一定未安装。

## CLI

```bash
# 指定用户；--dry-run 不写组、授予历史、日志或任务状态
php flarum member-promotion:check --user=42 --dry-run
php flarum member-promotion:check --user=42 --rule=3

# 全量或按规则扫描默认只排入后台；--foreground 只追加执行一个受预算限制的批次
php flarum member-promotion:check --all
php flarum member-promotion:check --rule=3 --foreground

# 计数重建
php flarum member-promotion:rebuild-counts
php flarum member-promotion:rebuild-counts --user=42

# 状态、手动处理一批、确认修复根因后重置失败项
php flarum member-promotion:status
php flarum member-promotion:run
php flarum member-promotion:run --retry-failed
```

后台任务固定每批最多处理 100 个用户、单次最多运行 20 秒，后台和 CLI 均不提供覆盖参数，避免误配置导致数据库负载突增。

## 一致性与人工操作边界

- 所有时间使用 UTC；同一用户加组由用户行锁串行化，数据库唯一约束和应用幂等共同防重。
- 加目标组、插件授予来源和可确认的前置组移除在同一数据库事务内。
- API 中人工移除插件授予的组会触发 Flarum `GroupsChanged` 并建立阻止记录。
- 扩展后台的“撤销并阻止恢复”是可靠入口。直接 SQL 修改不会发出事件；后续评估发现“有效插件授予存在、组关系却缺失”时会保守阻止恢复，但无法准确识别所有外部脚本的意图。
- 停用或删除规则、停用扩展、删除回复或提高门槛都不会自动降级。删除规则不会清理历史授予和审计记录；目标组/前置组结构变化不迁移历史授予，更推荐先复制并检查新规则，再删除旧规则并启用新规则。
- 日志清理只删除运行日志，不删除规则版本、授予来源、用户暂停或人工阻止。

## 安装、更新与卸载

针对 `crazymax/flarum:1.8.19` 的完整说明见 [docs/INSTALL.zh-CN.md](docs/INSTALL.zh-CN.md)。该镜像不会持久化 `/opt/flarum/composer.json`、锁文件或 `vendor`，生产环境应使用文档中的派生镜像方案，而不是只在运行中容器执行一次 Composer。

若要先在当前电脑手动体验后台和晋级流程，使用[一键本地测试环境](docs/LOCAL-TEST.zh-CN.md)。它使用独立容器、网络和命名卷，不读取生产数据库或生产 `/data`。

## FoF Upload

本扩展不调用 FoF Upload API。请在 Flarum 后台权限页把“上传文件”等权限授予晋级目标组；组成员变化后 Flarum 权限系统自然生效。扩展不会修改上传大小、MIME、存储驱动或上传记录。

## 测试

```bash
composer install
composer test
cd js
npm ci
npm run build
npm run check-typings
```

测试覆盖条件边界/AND、依赖与间接循环、冲突、多阶段连续晋级、幂等、dry-run、有效回复口径和管理接口越权。更多集成与规模验证方法见 [docs/TESTING.zh-CN.md](docs/TESTING.zh-CN.md)。

目标基础镜像在 2026-10-10 的 `composer audit` 中存在其上游依赖告警（`league/commonmark` 两项、`league/flysystem` 一项）；本扩展没有新增运行时第三方依赖。部署前应结合 Flarum 官方可用版本评估升级，详见安装文档。

## 设计文档

- [架构、任务恢复与调优](docs/ARCHITECTURE.zh-CN.md)
- [crazymax/flarum 安装与运维](docs/INSTALL.zh-CN.md)
- [一键本地测试环境](docs/LOCAL-TEST.zh-CN.md)
- [测试与性能验证](docs/TESTING.zh-CN.md)
- [1 万用户 / 10 万帖子快速压测记录](docs/QUICK-BENCHMARK.zh-CN.md)

实现依据 Flarum 1.x 的[后台扩展文档](https://docs.flarum.org/1.x/extend/admin/)、[路由文档](https://docs.flarum.org/1.x/extend/routes/)、[调度文档](https://docs.flarum.org/1.x/extend/console/)以及 v1.8.19 官方源码。
