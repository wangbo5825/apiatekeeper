# ApiGateKeeper 架构文档（V3 设计更新）

> 状态：V3 设计更新（2026-08-29）
> 项目由 apigate → Charon → API Gatekeeper → 正式更名为 **ApiGateKeeper**（2026-09-04）。
> V3 引入：应用统一缺省配置、自动加入规则（零配置可用）、
> 变量（上下文）模型、每 API 多规则绑定。

## 1. 总体架构

ApiGateKeeper 构建于 **FRAMPP**（定制 FrankenPHP，内置 `caddy-access-filter`
v1.0.1，仓库位于 `C:\work\workspace\caddy-access-filter\dev`）之上：

| 组件 | 职责 | 位置 |
|---|---|---|
| caddy-access-filter | access/filter 前后钩子（外部独立项目，不改动） | FRAMPP |
| Caddy `reverse_proxy` | 原生转发 | 内置 |
| Souin（可选） | HTTP 缓存（部署构建补齐） | deploy/build.sh |
| PHP worker（Gatekeeper） | 应用匹配 + 契约端点 + 规则引擎 + 变量上下文 + 记录 + Admin API | processor/ |
| SQLite / APCu | 管理与配置持久化 / 热路径内存状态 | processor/ |
| admin-web | Vue 3 管理端 SPA | admin-web/ |

## 2. 多应用模型

请求必须命中某个应用（Application）才会被处理；未命中 → 404。

- **应用标识**：base_path（起始路径，如 `/v1`）+ 可选 host / 请求头匹配
- **每应用统一缺省配置**：
  - `default_allow`：缺省是否放行（默认放行）
  - `default_cache_enabled` / `default_cache_ttl`：缺省缓存与 TTL
  - `auto_add_rules`：缺省是否自动加入规则（默认开启）
- **每应用独立定义**：上游组（含缺省前缀映射）、API 列表、规则、变量声明、
  黑名单、令牌

### 缺省配置 × 自动加入

| default_allow | auto_add_rules | 效果 |
|---|---|---|
| 放行 | 开 | 新 URL 自动放行并加入 API 列表（零配置可用） |
| 放行 | 关 | 新 URL 放行但不加入列表 |
| 拒绝 | 开 | 新 URL 拒绝；加入列表后放行 |
| 拒绝 | 关 | 严格白名单 |

## 3. 请求链路

```text
客户端
  │
  ▼
FRAMPP :8080
  ├─ /__proc/request  → PHP worker（契约端点，禁套钩子，本机回环 + 共享密钥）
  ├─ /admin/api/*     → PHP worker（Admin REST，令牌鉴权）
  ├─ /admin/*         → SPA 静态文件
  └─ 其他             → access → filter → (cache) → reverse_proxy
      │
      ├─ 应用匹配：base_path / host / 其他规则 → app（未命中 → 404）
      ├─ 构建请求上下文：请求级变量（req.url / req.ip / req.userid / ...）
      │    + 关联变量（按 key 读取：var:last_url / var:last_ip / ...）
      ├─ phase=access：按 API 匹配规则（应用级 + API 级多规则）
      │    → 黑名单 / 认证 / 限流 / 变量许可 / 缓存策略 / 保存变量 / 改变目标
      │    → 未登记 URL：按缺省配置裁决 + 自动加入（auto_add_rules）
      ├─ phase=filter：响应加工（替换属性 / 保存变量 / JSON↔XML）、
      │    注入 Cache-Control、记录统计（写 JSON 日志文件）
      └─ reverse_proxy → 应用上游组（缺省前缀映射 → 健康检查 / 轮询 / 主备）
```

## 4. 处理器（processor/，纯 PHP 无框架）

- `worker.php`：FrankenPHP worker 入口，分发契约端点 / Admin API
- `src/App.php`：应用匹配与解析、应用缺省配置
- `src/Contract.php`：实现 caddy-access-filter 契约 v1（access/filter）
- `src/Context.php`（新增）：请求上下文构建（请求级变量 + 关联变量读取）
- `src/Variables.php`（扩展）：变量声明区域、按 key 的变量存取（APCu + TTL）
- `src/RuleEngine.php`：按应用加载规则快照（APCu，app_id + 版本失效）；
  规则绑定应用级 / API 级，多规则按顺序执行
- 规则类型：
  - access 阶段：`stats` / `cache` / `rate_limit` / `auth` / `save_variable` /
    `change_target` / `blacklist` / `variable_check`
  - filter 阶段：`response_transform`（replace / save_variable / convert）
  （模块化 Action 接口，各规则详细配置见 docs/RULES.md）
- `src/Recorder.php`：请求元数据写 JSON 日志文件（不直接触 SQLite）
- `src/ResponseTransformer.php`（新增）：filter 阶段响应 body 加工
  （属性替换 / 属性保存变量 / JSON↔XML 转换）
- `src/Importer.php`：日志导入 SQLite + API 清单派生（auto_add 落库）+ 聚合
- `src/Normalizer.php`：路径归一化（数字/UUID/日期 → {id}）
- `src/Identity.php`：客户端身份（JWT subject > API Key > IP）
- `src/Upstream.php`：上游组、健康检查、轮询 / 主备、缺省前缀映射
  （strip / replace / add；API 未单独转写时生效）
- `src/AdminApi.php`：管理端 REST（/admin/api/*）

## 5. 存储与共享状态

- **SQLite（WAL，仅管理与配置用途）**：
  apps / upstreams / rules / apis / variables / request_logs / api_stats /
  client_stats / blacklist / tokens / meta（业务表均带 app_id）
- **APCu（热路径唯一状态源）**：
  应用规则 / 黑名单 / 令牌快照（app_id + 版本失效）、关联变量（按 key + TTL）、
  限流计数、请求上下文暂存（60s）
- **日志文件**：`data/logs/YYYY-MM-DD.log`，按天轮转，保留期与 raw_days 对齐
- **Redis**：暂缓（多实例部署时再评估）

## 6. 管理端

- **应用管理**：应用 CRUD（base_path / 匹配方式 / 状态）
- **应用详情**：
  - 设置：应用缺省配置（放行 / 缓存 / 自动加入规则）
  - API 列表：编辑 + 冲突检测（重复 / 模板覆盖）、删除
  - 客户端 / 黑名单 / 令牌 / 上游
  - 规则：应用级 + API 级多规则，8 种规则类型
  - 变量：变量声明区域（key 维度 / 取值来源 / TTL）
- **全局**：仪表盘、Prometheus 指标、系统（记录链路状态）
- Admin REST API：`/admin/api/*`，管理员账号密码登录（会话 Cookie；
  首次访问 `/admin/` 进入安装向导）

## 7. 运行与测试

```bash
# 单元测试（本机 PHP CLI 可跑）
php processor/tests/run.php

# 构建 FRAMPP + Souin 二进制（Linux/WSL）
FRAMPP_DIR=/path/to/caddy-access-filter/dev ./deploy/build.sh

# 端到端测试（Linux/WSL，需 frankenphp 二进制）
FRANKENPHP=./deploy/bin/frankenphp ./test/e2e.sh

# Docker 部署
cd deploy && docker compose up --build
```

## 8. 已知限制（V3）

- access 阶段契约不传请求体，基于 body 的规则推迟
- stream 模式 `on_error block` 退化为透传（模块兼容性说明）
- 多实例部署需将 APCu 共享状态迁移 Redis（暂缓，存储接口已抽象）
- Souin 构建/配置语法需按实际版本校验
- 关联变量 key 维度 = 任意请求级变量（ip / url / query / header / userid /
  apikey）；同 key 值共享槽位，跨请求关联靠 key 值一致
- 冲突检测目前针对同一应用内的 API；跨应用重叠由应用匹配规则先行收敛
- filter 响应加工依赖 buffer 模式与响应 body；需设置 body 读取上限，
  stream（SSE 等）路由不做响应转换，仅记录
