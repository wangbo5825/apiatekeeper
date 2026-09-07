# ApiGateKeeper 配置参考

## 1. Caddyfile

配置**严格遵循** caddy-access-filter 的指令表与
`examples/php-worker/Caddyfile` 拓扑：

| 指令 | 选项 | 默认 |
| --- | --- | --- |
| `access` | `upstream` / `path` / `timeout` / `on_error` / `circuit_failures` / `circuit_cooldown` | 见模块 README |
| `filter` | `mode`(buffer\|stream) + 与 access 相同 | `buffer` |

动态上游提示头（由模块写入请求头，`reverse_proxy` 占位符消费）：

- `X-Processor-Backend`
- `X-Processor-Cache`
- `X-Processor-TTL`

### 拓扑要点（来自模块示例，生产必读）

1. `/__proc/request` 处理器路由**绝不套 access/filter**，防止递归
2. 处理器路由必须用 `handle` 块 + `worker { file; match }` 显式绑定
3. `upstream` 指向本实例回环地址，`path` 与处理器路由一致
4. worker 线程数按并发调优（示例 `worker ./worker.php 8`）
5. 处理器端点必须保护（`client_ip` 限制 + 共享密钥）

### 完整示例

见 [configs/Caddyfile](../configs/Caddyfile)。

## 2. 处理器配置（config.php / 环境变量）

| 配置 | 环境变量 | 默认 | 说明 |
| --- | --- | --- | --- |
| `db.path` | `APIGATE_DB_PATH` | `data/apigate.sqlite` | SQLite 路径 |
| `retention.raw_days` | `APIGATE_RAW_RETENTION_DAYS` | `7` | 原始请求记录保留天数 |
| `processor.secret` | `APIGATE_PROCESSOR_SECRET` | 空 | 处理器端点共享密钥（Caddy 注入 `X-Processor-Secret`） |
| `admin.tokens` | `APIGATE_ADMIN_TOKENS` | 空 | 旧版管理令牌；管理端已改账号密码登录（会话 Cookie），不再使用 |
| `upstream.tls_insecure` | `APIGATE_UPSTREAM_TLS_INSECURE` | `0` | https 主动探测是否跳过证书校验（自签/测试用，生产保持 0） |
| `auth.jwt_secret` | `APIGATE_JWT_SECRET` | 空 | HS256 密钥（认证规则用） |
| `auth.jwks_url` | `APIGATE_JWKS_URL` | 空 | RS256 公钥 JWKS（认证规则用，可选） |
| `souin.admin_url` | `APIGATE_SOUIN_ADMIN_URL` | 空 | Souin 管理 API（用于 PURGE） |

## 3. 存储

- SQLite（WAL）：`request_logs` / `api_stats` / `client_stats` / `rules` / `blacklist` / `apis`
- APCu：限流计数、变量（TTL）、规则快照（版本号失效）、请求上下文暂存
- APCu 不可用时自动回退到进程内存（单 worker 有效），生产建议启用 APCu 扩展

## 4. 客户端身份

记录与规则匹配按以下维度识别客户端（优先级从高到低）：

1. `Authorization: Bearer` JWT 的 `sub`（需可校验）
2. `X-Api-Key` 请求头
3. 客户端 IP（access 契约的 `client_ip`）
