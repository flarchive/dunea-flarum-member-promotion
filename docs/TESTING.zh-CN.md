# 测试与性能验证

## 自动化测试

```bash
composer install
composer test:setup # 只对环境变量指定的隔离测试库执行一次，会清空该库
composer test
```

单元测试覆盖 `>` / `>=` 边界、多条件 AND、下一次时长检查、依赖排序、间接循环和重复目标冲突。集成测试覆盖有效正文回复口径及事件修正、连续多阶段晋级、重复执行幂等、人工撤销阻止、已消费阶段、规则结构修改、任务续跑/重试、dry-run 无业务写入、可选审核扩展缺失和管理 API 越权。

事件测试应在 CI 的两组依赖中运行：启用和不启用 `flarum/approval`，确保可选扩展缺失时不访问其 PHP 类或字段。生产发布前还应补跑 MariaDB（不能只用测试框架默认 SQLite）以覆盖 `GREATEST`、行锁和并发认领。

## 已执行验证（2026-10-10）

环境：Docker Desktop；`crazymax/flarum:1.8.19`；PHP 8.4.21；Flarum Core 1.8.19；MariaDB 10.11.19；Node.js 24.16.0；npm 11.17.0。

- PHPUnit：31 tests，76 assertions，7.626 秒，PHPUnit 报告峰值 34 MiB；
- 全部 PHP 文件 `php -l`：通过；
- `npm run build`：通过，生产 `admin.js` 约 26.3 KiB；
- `npm run check-typings`：通过；
- `npm audit`：0 个前端已知漏洞；
- `docker build -f docker/Dockerfile ...`：通过，并通过 `composer show` 确认扩展已安装。

功能测试的总墙钟时间不是吞吐量基准。当前已完成 1 万用户/10 万帖子、三阶段规则的 MariaDB 单 worker 快速压测，结果见 [QUICK-BENCHMARK.zh-CN.md](QUICK-BENCHMARK.zh-CN.md)；10 万用户/100 万帖子、SQL digest 查询数量和真实多 worker 压测尚未执行，因此仍没有百万规模或并发吞吐量结论。并发正确性当前由用户行锁、claim token、待办主键及授予唯一约束保证，并由幂等/续跑测试覆盖；大规模上线前仍建议按下文脚本做真实并发验证。

当前测试套件包含查询数量回归：调度器预载启用规则并预热稳定元数据后，一个无状态变化用户的核心评估最多执行 4 条 SQL（用户状态与计数、用户组、阻止、历史授予），且授予/阻止各只查询一次，不随规则数量增加。该断言用于防止 N+1 回归，不等同于真实硬件上的吞吐量结论。

## 可复现规模数据

仅在隔离的 Flarum 测试数据库使用：

```bash
php benchmarks/generate.php \
  --confirm-isolated \
  --dsn='mysql:host=127.0.0.1;dbname=flarum_bench;charset=utf8mb4' \
  --user=dbuser --password='...' --users=100000 --posts=1000000 --batch=5000
```

脚本只追加带 `mpbench_` 前缀的用户和对应主题/帖子。运行前仍需快照；清理必须销毁整个隔离数据库，不提供面向生产的批量删除命令。

然后：

```bash
/usr/bin/time -v php flarum member-promotion:rebuild-counts --foreground
while php flarum member-promotion:status | grep -q '"pendingJobs": [1-9]'; do
  /usr/bin/time -v php flarum member-promotion:run
done
```

任务固定为每批最多 100 个用户、单次最多 20 秒。记录：MariaDB/PHP/Flarum 版本、CPU/内存限制、存储类型、用户/帖子分布、规则数和依赖深度、每批墙钟时间、`Maximum resident set size`、数据库慢查询及 `performance_schema.events_statements_summary_by_digest` 的调用次数。查询数量必须按 digest 统计，不能只记录 HTTP 数量。

至少执行三轮（冷缓存、热缓存、并发两个 worker），核对：

- grant、group_user 无重复；
- cursor 中断续跑后总用户数一致；
- 同用户并发只产生一条同版本 grant；
- 主题隐藏/恢复后的计数与基准 SQL 一致；
- 毒性用户三次后停止，其余用户继续；
- 峰值内存不随总用户数线性增长。
