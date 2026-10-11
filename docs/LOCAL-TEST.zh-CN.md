# 一键本地测试环境

这套环境仅用于在当前电脑上手动验收后台界面和晋级流程，不读取或修改生产数据库、生产 `/data`，也不开放 MariaDB 端口。Flarum 只绑定到本机 `127.0.0.1`。

## 前提

- Docker Desktop 已启动；
- 在本仓库根目录打开 PowerShell；
- 本机 8088 端口未被占用。

## 启动

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\test-up.ps1
```

脚本会构建派生镜像、启动 MariaDB 10.11 和 Flarum 1.8.19、等待安装完成、启用会员晋级扩展并执行迁移。完成后访问：

```text
http://localhost:8088
```

初始管理员：

```text
用户名：flarum
密码：flarum
```

首次登录后应立即修改这个测试账号密码。自动晋级总开关仍保持关闭。

如果 8088 被占用，可指定其他端口：

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\test-up.ps1 -Port 18088
```

## 建议验收步骤

1. 在 Flarum 后台创建“正式会员”“活跃会员”等自定义组；
2. 进入“会员晋级”扩展页创建规则，暂时不打开自动晋级；
3. 创建几个普通测试用户和回复；
4. 在“用户检查”中核对有效回复、注册时长和未达标原因；
5. 用 dry-run 验证，不修改用户组：

```powershell
docker compose -f .\docker\compose.test.yml exec -u flarum flarum php flarum member-promotion:check --user=2 --dry-run
```

6. 需要测试后台批处理时，重新启动并开启 scheduler：

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\test-up.ps1 -WithScheduler
```

## 停止与清理

停止但保留测试数据：

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\test-down.ps1
```

彻底删除这套测试环境及其独立数据库和 `/data` 卷：

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\test-down.ps1 -DeleteData
```

`-DeleteData` 只处理 Compose 项目 `flarum-member-promotion-test` 的专用卷，不会接触生产环境。
