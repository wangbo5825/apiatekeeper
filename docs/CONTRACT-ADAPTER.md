# 处理器契约适配说明

ApiGateKeeper 的 PHP worker 实现 caddy-access-filter 的
[处理契约 v1](C:/work/workspace/caddy-access-filter/dev/docs/CONTRACT.md)，
单一端点 `/__proc/request`，通过 `phase` 区分阶段。

## access（phase=access）

输入：`method` / `uri` / `headers` / `client_ip` / `proto` / `id`

处理顺序：

1. 身份识别（JWT subject → API Key → IP）
2. 路径归一化（`/api/users/123` → `GET /api/users/{id}`）
3. 规则加载（APCu 快照 + 版本失效）与匹配（API 精确 > 通配；客户端具体 > 全部）
4. 规则执行：认证 → 黑名单 → 限流 → 变量许可 → 缓存策略（route 提示）
5. 记录 access 元数据到请求上下文（按 `id` 暂存，TTL 60s）

输出：`decision` / `rewrite` / `route` / `deny`

## filter（phase=filter）

输入：`id` / `status` / `headers` / `body` / `duration_ms` / `upstream` / `bytes`

处理顺序：

1. 按 `id` 取回 access 上下文
2. 合并响应元数据 → 写入 `request_logs`（异步缓冲批量落库）
3. 应用缓存策略：`force` → `Cache-Control: public, max-age=<ttl>`；
   `no-cache` → `Cache-Control: no-store`；`auto` → 不注入（交 Souin）
4. 更新 API/客户端清单（首次出现自动注册）

输出：`status` / `headers` / `body`（默认透传）/ `recorded`

## 注意

- access 阶段契约不传请求体，基于 body 的规则推迟到契约升级
- stream 模式下模块忽略 body 变换，仅记录元数据
- 处理器端点必须保护（`client_ip` + `X-Processor-Secret`），禁止公网直连
