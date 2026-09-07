# Deployment / 部署

## Build the FRAMPP binary / 构建 FRAMPP 二进制

```bash
FRAMPP_DIR=/path/to/caddy-access-filter/dev ./deploy/build.sh
```

Output / 产物：`deploy/bin/frankenphp` (FrankenPHP + caddy-access-filter +
Souin HTTP cache module `http.handlers.cache`).

## Docker Compose

```bash
cd deploy
docker compose up --build
```

- Gateway / 网关：`http://localhost:8080`
- Admin UI / 管理端：`http://localhost:8080/admin/`
- Mock upstream: any path under `http://localhost:8080` (e.g. `/hello`)

## Manual install on a server / 服务器手工安装

```bash
./deploy/package.sh          # generates dist/gatekeeper-release/
```

Detailed steps / 详细步骤：[MANUAL-INSTALL.md](MANUAL-INSTALL.md)
(copy package -> configure -> start -> wizard -> cron -> verify /
复制发布包 → 配置 → 启动 → 安装向导 → cron → 验证)。

**FRAMPP integration (recommended; reuses FRAMPP startup) /
集成到 FRAMPP（推荐，复用其启动脚本）**:

```bash
sudo FRAMPP_HOME=/root/frampp ./deploy/install-frampp.sh \
  --dir /home/stream/gatekeeper --port 8090
```

`--dir` is the Gatekeeper code root (`processor/`, `admin-web/`, `configs/`).
The code is **not copied**: the script generates `processor/config.php` once,
renders the Caddy fragment to
`$FRAMPP_HOME/etc/caddy.d/api-gatekeeper.caddy` and imports it into the FRAMPP
Caddyfile. First visit to `/admin/` opens the install wizard.

`--dir` 指定代码根目录，代码**不复制**：脚本只生成一次 config.php、渲染片段
并挂载 import；首次访问 `/admin/` 进入安装向导。

**Standalone systemd install (own service) / 独立 systemd 安装**:

```bash
sudo ./deploy/install.sh
./deploy/test.sh
```

**Web installer (dev only, not recommended) / Web 安装器（仅开发，不推荐）**:
off by default because it exposes SQLite/logs under the web root. Use
`install-frampp.sh` for real installs; `WEB_INSTALL=1 ./deploy/package.sh`
generates it for local bootstrapping. See MANUAL-INSTALL.md.

默认不生成（安全问题）；正式安装用 `install-frampp.sh`，本地自举可用
`WEB_INSTALL=1 ./deploy/package.sh`。

## Environment variables / 环境变量

See / 见 [configs/config.example.php](../configs/config.example.php) and /
与 [docs/CONFIG.md](../docs/CONFIG.md).

## Production notes / 生产注意事项

1. Never expose `/__proc/request` publicly (loopback ACL + shared secret) /
   `/__proc/request` 禁止公网直连
2. Create a strong admin password in the install wizard / 向导中设置强密码
3. Tune worker threads for concurrency (the `php { worker }` block) /
   按并发调整 worker 线程数
4. For multi-instance deployments, move APCu shared state (rate limits,
   variables) to Redis / 多实例时把 APCu 共享状态迁移 Redis
