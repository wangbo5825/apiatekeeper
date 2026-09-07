# ApiGateKeeper — A lightweight API gateway on FRAMPP / 基于 FRAMPP 的轻量 API 网关

> Status / 状态：V3 implementation / V3 实施中

## What it is / 是什么

ApiGateKeeper is a lightweight API gateway built on **FRAMPP** (a customized
FrankenPHP that bundles `caddy-access-filter`):

ApiGateKeeper 是一个轻量 API 网关，构建于 **FRAMPP**（定制 FrankenPHP，内置
`caddy-access-filter`）之上：

- **Default transparent proxy / 缺省透明代理**: without rules it behaves like
  a standard reverse proxy / 不配置规则时等价标准反向代理
- **Logging & stats / 记录统计**: records request/response metadata and builds
  API/client inventories and statistics / 自动记录请求/响应元数据，沉淀清单与统计
- **Rule engine / 规则引擎**: cache, rate limit, blacklist, variables, auth and
  response transforms, all implemented in the PHP worker / 缓存、限流、黑名单、
  变量、认证、响应加工等规则，全部由 PHP worker 实现
- **Admin UI / 管理端**: Admin REST API + Vue 3 SPA (`admin-web/`)

## Component layout / 组件关系

```text
clients / 客户端
  │
  ▼
FRAMPP (FrankenPHP + caddy-access-filter + Souin)
  │  access hook ──▶ PHP worker (rules / identity)
  │  reverse_proxy (native forwarding)
  │  filter hook ──▶ PHP worker (logging / cache policy / response transform)
  ▼
upstream services / 上游服务
```

- [caddy-access-filter](C:/work/workspace/caddy-access-filter/dev/) is an
  external project; ApiGateKeeper only configures it / 是外部独立项目，只按其文档配置
- The PHP worker implements the module's
  [processor contract v1](C:/work/workspace/caddy-access-filter/dev/docs/CONTRACT.md)

## Directory layout / 目录结构

```text
apigate/dev/
├── processor/        # PHP worker (contract endpoint + Admin API + rule engine)
├── admin-web/        # Vue 3 + Vite admin SPA (separate sub-project)
├── configs/          # Caddyfile, processor config example
├── deploy/           # build scripts, Dockerfile, compose, install docs
├── test/             # end-to-end tests
├── docs/             # config reference, contract adapter notes
├── REQUIREMENTS.md   # requirements / 需求记录
└── ARCHITECTURE.md   # architecture / 架构文档
```

## Quick start / 快速开始

1. Build a FRAMPP binary with `caddy-access-filter` (Souin via
   `deploy/build.sh`) / 构建含 access-filter 的 FRAMPP 二进制
2. Configure `configs/Caddyfile` and `configs/config.php`
3. Run in worker mode: `frankenphp run --config configs/Caddyfile`
4. Open the admin UI at `http://<host>/admin/` — the first visit runs the
   install wizard / 首次访问 `/admin/` 进入安装向导

See / 详见 [docs/CONFIG.md](docs/CONFIG.md) and / 与
[deploy/README.md](deploy/README.md).
