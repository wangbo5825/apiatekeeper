# 问题收集（Issue Log）

> 仅用于记录已发现的问题，暂不处理。待确认后再排期处理。

## 状态说明

- `未处理`：仅记录，尚未定位 / 修复
- `处理中` / `已修复`：处理进展后续补充

## 问题列表

### 1. 检查插件会有失败的概率，发生过检测错误

- 记录日期：2026-09-01
- 状态：未处理（暂不开始）
- 描述：检查插件（模块）存在失败概率，实际发生过检测错误。
- 相关位置（待确认）：
  - `deploy/install.sh`：预检 `list-modules` 后 grep `http.handlers.access_filter` / `http.handlers.cache`
  - `deploy/install-frampp.sh`：同样的模块预检逻辑
  - `deploy/MANUAL-INSTALL.md`：安装后验证及 PHP 扩展检查说明
- 备注：具体触发条件 / 复现步骤 / 现象细节待补充。

### 2. reverse_proxy 上游带 scheme 时不允许占位符，Caddyfile 适配失败

- 记录日期：2026-09-01
- 状态：已修复（2026-09-01）
- 描述：启动时 Caddy 配置适配报错，`reverse_proxy http://{vars.gk_upstream}` 中上游地址
  含有 scheme（`http://`），Caddy 解析器不允许此时使用占位符，导致整段
  `handle → route → reverse_proxy` 解析失败。
- 报错原文：
  ```
  Error: adapting config using caddyfile: parsing caddyfile tokens for 'handle':
  parsing caddyfile tokens for 'route': parsing caddyfile tokens for 'reverse_proxy':
  parsing upstream 'http://{http.vars.gk_upstream}': due to parsing difficulties,
  placeholders are not allowed when an upstream address contains a scheme
  ```
- 相关位置：
  - `configs/api-gatekeeper.caddyfile:80`：`reverse_proxy http://{vars.gk_upstream}`
    （渲染后对应 `/home/stream/frampp/etc/caddy.d/api-gatekeeper.caddy:80`）
  - `configs/Caddyfile:104`：同样写法 `reverse_proxy http://{vars.gk_upstream}`，需一并排查
  - 上游地址来源：`map {http.request.header.X-Processor-Backend} {vars.gk_upstream}`，
    处理器返回 `X-Processor-Backend` 时可能已带 scheme
- 修复方案：Caddy 占位符动态上游只允许 `host:port`（不能带 scheme）——
  1. 两处 Caddyfile 改为 `reverse_proxy {vars.gk_upstream}`，去掉字面 `http://`
  2. `deploy/install-frampp.sh` / `deploy/install.sh` 在渲染/写 env 前规范化兜底上游，
     自动去掉 `http(s)://` 前缀与末尾 `/`
  3. `deploy/docker-compose.yml`、`deploy/Dockerfile`、`test/e2e.sh` 的
     `APIGATE_UPSTREAM` 改为不带 scheme
  4. 文档与 `dist/gatekeeper-release` 发布包同步更新
- 备注：`X-Processor-Backend` 由处理器 `Upstream::normalizeAddress()` 产出，本就不带 scheme；
  服务器上需重跑 `install-frampp.sh`（或手工改渲染后的 caddy 文件）后重启生效。

### 3. 访问 /admin（无尾斜杠）落入默认网关路由，未配置上游时 502

- 记录日期：2026-09-01
- 状态：已修复（2026-09-01）
- 描述：Caddy 路径匹配中 `/admin/*` 的 `*` 只匹配 `/admin/` 之后的部分，
  **不带尾斜杠的 `/admin` 不会命中管理端 SPA 路由**，而是落入默认网关路由
  `access → filter → reverse_proxy`；上游未配置时返回 502。
- 现象日志：
  ```
  msg="dial tcp :0: connect: connection refused" uri="/admin" status=502
  ```
  `dial tcp :0` 表示兜底上游为空（`APIGATE_UPSTREAM` / `{{UPSTREAM}}` 未设值）。
- 修复方案：
  - `/admin/api/*` 用命名 matcher `@adminApi`：`path /admin/api /admin/api/*`，
    `handle @adminApi` 指向 PHP worker（`handle` 只能接单个 matcher，多路径用 `path` OR）
  - `/admin` 重定向到 `/admin/`（308）；`/admin/*` 用 `handle_path` 剥离前缀后
    以 `admin-web/dist` 为根提供文件，`try_files {path} /index.html` 兜底 SPA 路由
    （SPA 按 `base=/admin/` 构建，若 file_server 不剥离前缀会 404）
  - Caddyfile 细节（本地 WSL 实测发现）：
    - `try_files` 是独立指令，不能放在 `file_server { }` 块内（否则
      "unknown subdirective 'try_files'" 启动失败）
    - `redir` 语法为 `[<matcher>] <to> [<code>]`：`redir /admin/ 308` 会被解析成
      from=/admin/ to=308（302）；必须写 `redir * /admin/ 308`
    - `try_files` 的文件匹配器与 `file_server` 都读 `{http.vars.root}`，需在
      `handle_path` 内用 `root * <dist>` 限定作用域，否则 SPA 深链回退按站点根解析 404
  - 两处 Caddyfile 及 dist 发布包均已同步
- 备注：管理面板（PHP worker + SPA 静态资源）本身不依赖上游；
  非 admin 路径（如 `/ping`）在上游配置完成前会 502，属预期行为；
  若日志为 `dial tcp :0`，说明渲染出的 caddy 片段里 `default ` 上游为空，
  需重跑 `install-frampp.sh`（设置 `GATEKEEPER_UPSTREAM`）或手工补上默认上游后重启。

### 4. 本地 WSL 测试发现并修复的问题（2026-09-01）

- 状态：已修复（除标注"需重编 FRAMPP"项）
- 描述：在本地 WSL 用 FRAMPP 0.7.1 解包出的 frankenphp 启动测试实例，
  通过 `caddy validate` + `test/e2e.sh` 自动化复现并定位了以下问题。

- **处理器密钥注入无效（`header request` 用法错误）**
  - `header` 指令只处理响应头；设置请求头必须用 `request_header`。
  - 原写法导致 access 阶段内部调用 `/__proc/request` 永远 403，
    `on_error passthrough` 使所有请求绕过 access 直接透传（规则全部不生效）。
  - 已改为 `request_header X-Processor-Secret "..."`。

- **动态上游恒为空（`dial tcp :0`）的真正根因：map 目标不能用 `{vars.*}`**
  - Caddy 的 `{http.vars.*}` replacer 对未设置变量"总是返回空值"，
    注册在它之后的 map 懒回调永远不触发 → 上游恒空。
  - 已把 map 目标改为自定义占位符 `{gk_upstream}`，`reverse_proxy {gk_upstream}`。
  - 注意：`reverse_proxy {env.X}` 是可用的（SSE 路由证明），只有 `{http.vars.*}` 被遮蔽。

- **处理器契约 JSON 形态错误（空数组 `[]` vs 对象 `{}`）**
  - PHP `json_encode` 空数组输出 `[]`，Go 端 `map[string]string`/struct 解码失败，
    access/filter 响应全部被当作错误（deny 不生效、规则不执行）。
  - 已为 Contract.php 三处响应体 json_encode 加 `JSON_FORCE_OBJECT`。

- **caddy-access-filter 模块缺陷：access/filter 两次调用的 id 不一致**
  - 模块对同一请求的 access/filter 各生成一次随机 id，与
    `docs/CONTRACT.md` 契约（两阶段同 id）不符；worker 无法关联两阶段上下文，
    缓存策略/应用归属/上游健康状态全部丢失。
  - 已在 `caddy-access-filter/dev/processor.go` 的 `requestID()` 中把 id 回写到
    `X-Request-Id` 请求头，重编后同一请求两阶段 id 一致（已用本地重编二进制验证）。
  - **需重新构建 FRAMPP 二进制才会生效**；当前 FRAMPP 0.7.1 包内仍是旧模块，
    表现为 e2e 中"缓存策略 force"不生效（`Cache-Control: no-store`）。

- **全局统计端点类型错误**
  - `POST /admin/api/stats/aggregate` 被路由当作 `stats` 资源的数字 id 传入
    `appResource($subId: ?string)`，触发 TypeError。
  - 已在 AdminApi::handle 增加 `stats` 资源路由（`/admin/api/stats` GET/POST）。

- **e2e 脚本与当前产品模型脱节**
  - 网关要求请求命中应用；规则/黑名单均为应用级作用域。
  - 已更新 `test/e2e.sh`：先建默认应用，规则/黑名单/清单/聚合走
    `/admin/api/apps/{id}/...`，清单检查前运行一次导入器。
  - 黑名单需"表项（维度 req.ip）+ 黑名单规则"两者配合才会拒绝。

- 本地 e2e 结果：7/8 通过（透明代理、限流、黑名单、鉴权、SSE、清单、聚合）；
  唯一失败项"缓存策略 force"依赖上述模块修复后的 FRAMPP 重编。

### 5. 未实现 / 待办清单（2026-09-04 整理）

> 状态图例：`计划中`（有出处待排期） / `待确认`（产品需拍板） /
> `部分实现`（缺一环） / `已过时`（文档与现状不符，需清理）。

#### 网关 / 规则 / 模块层

- `计划中`：caddy-access-filter 的 X-Request-Id 关联改为内部传递
  （dev/PLAN.md "Future improvements"）：当前实现把 id 写回请求头并随请求转发
  上游/处理器，存在信息外泄；拟改存 context/内部头，须模块作者确认后实现。
- `待验证`：缓存策略 force 等依赖 access/filter 同 id 的功能，需用含模块修复的
  FRAMPP 二进制验证（本地旧二进制 e2e 该项仍失败）。
- `已知限制`：filter stream 模式 `on_error block` 退化为透传（模块兼容性文档）。
- `已知限制`：access 契约不传请求体，基于 body 的规则未支持。
- `待确认`：auth 规则令牌传递位置（Authorization / query / Cookie），
  docs/RULES.md 默认 header。
- `待确认`：规则命中"调试视图"（请求 → 命中规则 → 执行结果），
  原型 #app-rules 待确认项，未实现。
- `待确认`：response_transform 转换失败是否透传、default_allow=0 的默认响应码，
  docs/RULES.md 标注待确认。

#### 数据 / 统计

- `✓ 已完成（2026-09-04）`：请求流水/日志按 raw_days（默认 7 天）清理——
  新增 Retention.php（删除过期 request_logs/api_stats/client_stats 与历史日志
  文件），接入 Importer::run。
- `部分实现`：延迟指标仅 Prometheus 直方图（app 级）有输出；管理端趋势表无
  P95 展示（api_stats 只有总和）。
- `✓ 已完成（2026-09-04）`：系统页"记录链路"实时状态——新增
  `GET /admin/api/system`（日志行数/大小/最后写入、聚合水位与滞后、计数），
  System.vue 实时展示。
- `部分实现`：缓存 PURGE 只是"记录意图"（AdminApi 注释），未真正对接 Souin
  admin API；需配置 souin.admin_url。
- `未实现`：Dashboard 应用一览的"上游健康"列（metrics 有 gauge，UI 未读取）；
  （黑名单来源字段已实现，见下）

#### 管理端 / 应用配置

- `未实现`：应用"缺省上游组"选择（原型设置页）：apps 表无缺省上游组字段，
  运行时取第一个上游组。
- `✓ 已完成（2026-09-04）`：上游组支持编辑（新增 PUT），Upstreams.vue 可保存
  名称/映射/健康检查。
- `未实现`：API 列表"规则数"、变量"当前值示例"列（原型展示项）。
- `✓ 已完成（2026-09-04）`：黑名单"来源：自动/手动"——blacklist 表新增
  source 列（migration 自动补列），自动拉黑写入 auto，UI 展示来源。
- `已移除`：应用令牌（/tokens）管理页已按需求移除；后端接口与 auth=token 保留。
- `✓ 部分完成（2026-09-04）`：登录防爆破（按 IP 5 次/5 分钟窗口限速）与
  "修改密码"页面（POST /admin/api/password，保留当前会话）；CSRF 仍仅靠
  SameSite Cookie，未加独立 CSRF 令牌。
- `暂缓`：多实例部署的共享状态迁移 Redis（存储接口已抽象）。

#### 文档 / 配置清理

- `已过时`：ARCHITECTURE.md 管理端鉴权仍写 "Bearer/X-Admin-Token"；
  CONFIG.md 的 admin.tokens / APIGATE_ADMIN_TOKENS 说明已不适用于管理端
  （登录已改账号密码 + 会话 Cookie）。
- `已过时`：MANUAL-INSTALL.md 中"应用级上游组的运行时选择是待实现项"
  ——该功能已随 map 修复实现。
