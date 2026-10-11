# crazymax/flarum 1.8.19 安装与运维

以下命令均在宿主机执行，容器名按部署环境使用 `dididi-flarum-1`。先备份 MariaDB 和 `/data`。

## 为什么使用派生镜像

`crazymax/flarum` 只持久化 `/data`（assets、extensions、storage）。镜像启动脚本会从干净骨架恢复 `/opt/flarum`，并根据 `/data/extensions/list` 重装公开 Composer 包；`composer.json`、`composer.lock` 和 `vendor` 本身不在 `/data` 中。

本扩展尚未发布 Packagist，因此仅把源码 bind mount 到 `/data/extensions` 再运行一次 `composer require` 不足以跨容器重建：新的 Composer 骨架不知道本地 path 仓库。生产方案是构建派生镜像，把以下内容一起固化到镜像层：

- 扩展源码：`/opt/member-promotion`；
- Composer path repository 配置；
- `dunea/flarum-member-promotion:@dev` 的安装记录及 vendor 链接。

站点配置、资源和扩展运行数据仍留在 `/data` 与 MariaDB。

## 基础镜像依赖告警

2026-10-10 对构建后的镜像执行 `composer audit`，基础 Flarum 1.8.19 依赖中存在三项上游告警：`league/commonmark` 的一项高危 DoS 和一项中危 HTML 过滤绕过，以及 `league/flysystem` 的一项低危路径规范化问题。本扩展没有新增运行时第三方依赖，也没有用关闭安全检查的参数构建镜像。上线前应核对 Flarum 官方兼容更新；若必须保持 1.8.19，应结合站点是否启用相关 CommonMark 扩展、输入限流和上传路径边界做风险评估。不要为消除 Composer 提示而盲目单独升级锁文件中的底层包。

## 构建派生镜像

仓库已提供 `docker/Dockerfile`，等价内容如下，构建上下文使用本仓库根目录：

```dockerfile
FROM crazymax/flarum:1.8.19

COPY --chown=flarum:flarum . /opt/member-promotion

RUN composer config --working-dir=/opt/flarum \
      repositories.member-promotion path /opt/member-promotion \
 && composer require --working-dir=/opt/flarum \
      dunea/flarum-member-promotion:@dev --no-interaction --prefer-dist
```

宿主机执行：

```bash
sudo docker build -f docker/Dockerfile \
  -t dididi-flarum:1.8.19-member-promotion .
```

把 Compose 中主服务（以及使用相同代码的 cron sidecar）镜像改成 `dididi-flarum:1.8.19-member-promotion`，保留原 `/data:/data` 和数据库卷，然后重建容器。若宿主机已有独立的 `schedule:run`，不要再启动第二个 cron sidecar。

## 启用

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum extension:enable dunea-member-promotion
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum migrate
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum cache:clear
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum schedule:list
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum member-promotion:status
```

Flarum 1.8.19 的 `extension:enable` 会先执行该扩展迁移，再发布资源并启用扩展。后续 `migrate` 是幂等的全站迁移确认，不能用“先对尚未启用的扩展执行全站 migrate”代替启用动作。

后台进入扩展页：

1. 保持自动晋级关闭；
2. 创建/检查自定义目标组；
3. 配置规则并先用用户检查或 `--dry-run` 验证；
4. 执行 `member-promotion:rebuild-counts`，等待状态页显示完成；
5. 启用自动晋级。启用动作只创建后台扫描任务。

## 不要在现有生产容器中直接 Composer 安装

不要为了临时验证而在现有容器中执行 `composer require dunea/flarum-member-promotion:@dev`。`crazymax/flarum` 的 Composer hook 会把新增的直接依赖同步到 `/data/extensions/list`；本扩展尚未发布到 Packagist，新容器只有这条包名记录、却没有本地 path repository 时，重建可能安装失败。

测试环境同样使用上面的派生镜像。需要回退时切回原镜像即可；不要依赖对运行中容器的临时修改。

## 更新

1. 拉取/替换本仓库源码，确认 `js/dist/admin.js` 已随版本交付；
2. 使用同一个 Dockerfile 重新构建新 tag；
3. 更新主服务和 cron sidecar 镜像并滚动重建；
4. 执行：

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum migrate
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum cache:clear
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum member-promotion:status
```

数据库迁移必须先备份。不要修改 `/opt/flarum/vendor` 中的扩展源码。

## 停用

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 \
  php flarum extension:disable dunea-member-promotion
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 php flarum cache:clear
```

停用不会移除任何用户组，也不会删除规则、授予来源、阻止记录或日志。

## 卸载但保留数据

先停用，再构建一个不包含本扩展的新派生镜像或恢复官方镜像。不要运行 `migrate:reset`。用户组关系和扩展数据表均保留；以后重新安装同一扩展可继续使用。

## 显式删除历史数据

这是独立且不可逆的操作。确认数据库备份后，在扩展仍安装但已停用时执行：

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 \
  php flarum migrate:reset --extension dunea-member-promotion
```

该命令删除扩展自己的表；不会从 `group_user` 中批量删除已经授予的用户组。若业务要求清理这些组关系，必须依据备份和 `member_promotion_grants` 在 reset **之前**单独审核、导出并执行，不能把“插件曾授予”直接等同于“现在可以安全删除”。

## FoF Upload 权限

在 Flarum 后台“权限”页将 FoF Upload 的上传权限授予相应目标组。不要在本扩展规则里寻找上传选项；本扩展只维护组成员关系。

## 故障恢复

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 \
  php flarum member-promotion:status
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 \
  php flarum member-promotion:run
```

该命令固定每批最多处理 100 个用户、单次最多运行 20 秒，不接受批大小或时间预算覆盖参数。

先解决 `lastError`（例如目标组被删、数据库不可用），再显式执行：

```bash
sudo docker exec -u flarum -w /opt/flarum dididi-flarum-1 \
  php flarum member-promotion:run --retry-failed
```

不要反复自动重置毒性任务；失败项在三次重试后停止占用队列。
