# ApiGateKeeper Installation Guide / 服务器安装指南

Applies to servers with FRAMPP already installed
(FrankenPHP + `caddy-access-filter` [+ HTTP cache module
`http.handlers.cache`]) deploying the V3 code base.

适用于已手工安装 FRAMPP（FrankenPHP + caddy-access-filter，可选 HTTP 缓存
模块 `http.handlers.cache`）的服务器，部署 V3（多应用 + 变量 + 响应加工）代码。

---

## 0. One-click install / 一键安装（推荐）

After confirming the FRAMPP binary includes `caddy-access-filter`
(and the PHP extensions):

确认 FRAMPP 二进制包含 caddy-access-filter 与所需 PHP 扩展后：

```bash
# Build the release package (or build it locally and upload)
./deploy/package.sh

# Incrementally sync the package to the server
rsync -avzP --delete dist/gatekeeper-release/ root@server:/home/stream/gatekeeper/

# Run the mount script on the server (preflight, config, caddy fragment, cron)
sudo FRAMPP_HOME=/root/frampp \
  /home/stream/gatekeeper/deploy/install-frampp.sh --dir /home/stream/gatekeeper --port 28090

# Optional: full verification (health -> app -> traffic -> log -> import -> register)
sudo /home/stream/gatekeeper/deploy/test.sh http://127.0.0.1:28090
```

The code is **not copied into FRAMPP**. The Gatekeeper code root (the `--dir`
directory, with `processor/`, `admin-web/`, `configs/`) stays where it is; the
rendered Caddy fragment points at it. On the first visit to `/admin/` the
**install wizard** creates the admin account and the default application.

代码**不复制进 FRAMPP**：`--dir` 指定的代码目录原地保留，渲染出的 Caddy 片段
指向它；首次访问 `/admin/` 时由**安装向导**创建管理员与默认应用。

What the mount script does / 脚本做的事：

1. Preflight: `list-modules` contains `http.handlers.access_filter` and
   `http.handlers.cache`; embedded PHP has `pdo_sqlite / apcu / openssl /
   simplexml`.
   预检模块与 PHP 扩展。
2. First install only: generate `WEB_DIR/processor/config.php` (static paths
   pointing to `WEB_DIR/data/`; secrets generated once or taken from
   `GATEKEEPER_*` env vars). Re-runs never overwrite it.
   首次生成静态 config.php（路径指向 WEB_DIR/data，密钥只生成一次）。
3. Render the fragment `$FRAMPP_HOME/etc/caddy.d/api-gatekeeper.caddy`
   (default port 8090) and make sure the FRAMPP Caddyfile imports it.
   渲染独立 Caddy 片段并挂载 import。
4. Restart FRAMPP (`systemctl restart frampp` preferred).
   重启 FRAMPP。
5. Install the importer cron and register the default application (also done by
   the first-visit wizard).
   配置导入 cron（默认应用由安装向导创建）。

### Regular updates / 日常更新

```bash
# 1) Sync code to the same WEB_DIR (exclude processor/config.php!)
rsync -avzP --delete --exclude processor/config.php \
  dist/gatekeeper-release/ root@server:/home/stream/gatekeeper/

# 2) The PHP worker is resident: restart once to pick up code changes
ssh root@server systemctl restart frampp
```

Re-run `install-frampp.sh --dir ...` only when
`configs/api-gatekeeper.caddyfile` changed; updating only `admin-web/dist`
needs no restart at all.

只有 `api-gatekeeper.caddyfile` 模板变化时才需要重跑挂载脚本；仅更新管理端
静态文件时连重启都不需要。

### Admin account & password reset (CLI) / 管理员初始化与密码重置

The first visit opens the install wizard; you **type** the admin username and
password yourself (>= 8 chars, bcrypt-hashed). Lost the password? Reset it
locally:

首次访问进入安装向导，管理员用户名/密码由你**亲自输入**（≥8 位，bcrypt）。
忘记密码可本地重置：

```bash
# list existing admins / 列出管理员
$FRAMPP_HOME/bin/php <WEB_DIR>/processor/admin-passwd.php list
# reset an existing admin (old sessions invalidated) / 重置密码
$FRAMPP_HOME/bin/php <WEB_DIR>/processor/admin-passwd.php admin '新密码至少8位'
# initialize when not installed (creates admin + default app + cron) / 未安装时初始化
$FRAMPP_HOME/bin/php <WEB_DIR>/processor/admin-passwd.php admin '初始密码'
```

Quote passwords with special characters; env vars `GK_ADMIN_USER` /
`GK_ADMIN_PASS` are also accepted.
特殊字符用单引号包裹；也可用环境变量传参。

### Notes / 注意

- `bin/frampp init` regenerates `etc/Caddyfile`; re-run the mount script after a
  re-init (it is idempotent).
  `bin/frampp init` 会重新生成宿主 Caddyfile，需重跑挂载脚本（幂等）。
- FRAMPP known issue: duplicate `access-filter.caddy` imports break startup;
  the mount script comments out duplicates.
  FRAMPP 已知重复 import access-filter 会导致启动失败，脚本会注释重复项。

---

## 1. Do not package the whole project / 不要直接打包整个工程

The repo contains tests, `admin-web/node_modules`, dev data and local absolute
paths. Build the release instead:

仓库含测试、node_modules、开发数据等，请用发布包：

```bash
cd /path/to/apigate/dev && ./deploy/package.sh
```

`dist/gatekeeper-release/` contains only / 只包含：

```text
processor/   # PHP sources + worker + schema + import.php
configs/     # Caddyfile + config.example.php
admin-web/   # built SPA (dist)
data/logs/   # empty dir (SQLite + JSON logs land here)
deploy/      # this document + install scripts
```

## 2. Server prerequisites / 服务器前置条件

- FRAMPP binary with `caddy-access-filter` (HTTP cache module optional)
  FRAMPP 二进制含 caddy-access-filter（HTTP 缓存模块可选）
- PHP extensions: `pdo_sqlite` (required), `apcu` (recommended),
  `openssl` (JWT RS256), `simplexml` (JSON<->XML)
  PHP 扩展：pdo_sqlite（必需）、apcu（推荐）、openssl、simplexml
- Write access to the code dir `data/` for the FRAMPP service user
  代码目录 `data/` 需对 FRAMPP 运行用户可写

## 3. Manual copy & config / 手工复制与配置

```bash
rsync -avzP --delete --exclude processor/config.php \
  dist/gatekeeper-release/ root@server:/home/stream/gatekeeper/
mkdir -p /home/stream/gatekeeper/data/logs
```

`processor/config.php` is env-driven with `__DIR__`-relative data paths, so it
works anywhere. On the first mount, `install-frampp.sh` bakes it into a static
file with paths pointing at `WEB_DIR/data/`. Relevant settings:

`processor/config.php` 默认按代码目录（`__DIR__/../data`）定位数据库与日志，
任何位置都能工作；首次挂载时脚本会求值成静态配置。相关配置：

| Key / 键 | Description / 说明 |
|---|---|
| `processor.secret` | Shared secret for `/__proc/request`; must match the value injected by Caddy / 处理器共享密钥，须与 Caddy 注入值一致 |
| `db.path` / `record.log_dir` | Default `data/` under the code dir / 默认代码目录下 data/ |
| `admin.tokens` | Legacy; the admin UI now uses account/password login / 旧版管理令牌，管理端已改账号密码登录 |
| `auth.jwt_secret` | HS256 JWT key when used / JWT 校验密钥（用到时） |

Env overrides (all `APIGATE_*` keys in config.php) take precedence when set.
所有配置均可用对应 `APIGATE_*` 环境变量覆盖。

## 4. Build the admin UI (if the release lacks dist) / 构建管理端

```bash
cd admin-web && npm ci && npm run build
```

## 5. Start / 启动

The first visit to `http://<server>:<port>/admin/` opens the install wizard
(create admin -> default application -> best-effort cron). No manual app
creation is required.

首次访问 `/admin/` 进入安装向导（建管理员 → 默认应用 → 注册 cron），
无需手工创建应用。Database tables are created automatically on first use /
数据库首次使用自动建表。

For the manual FRAMPP path the essential steps are: place the code anywhere,
render `configs/api-gatekeeper.caddyfile` into
`$FRAMPP_HOME/etc/caddy.d/api-gatekeeper.caddy` with paths/port/secret filled
in, ensure the host Caddyfile imports it, then restart FRAMPP.

手工路径要点：代码放任意目录；渲染片段到 `etc/caddy.d/`（填好路径/端口/
密钥）；确保宿主 Caddyfile import；重启 FRAMPP。

## 6. Verify / 验证

```bash
# public health / 公开健康检查
curl http://127.0.0.1:28090/admin/api/health

# app traffic (default app exists after the wizard)
curl -i "http://127.0.0.1:28090/index.php/api?action=..."

# log + import + auto register
php processor/import.php
sqlite3 data/apigate.sqlite "SELECT method, template, auto FROM apis;"
```

## 7. Security notes / 安全注意

1. Never expose `/__proc/request` publicly (loopback ACL + shared secret).
   `/__proc/request` 禁止公网直连（回环限制 + 共享密钥）。
2. The admin UI login is mandatory after the wizard; use a strong password.
   向导创建的管理员密码请使用强密码。
3. Expose only the gateway port in the firewall.
   防火墙只放行网关端口。
4. `processor/config.php` holds secrets: exclude it from rsync updates.
   `processor/config.php` 含密钥：rsync 更新时排除。
