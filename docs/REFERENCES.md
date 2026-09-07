# 设计借鉴参考（Kong / APISIX）

> 2026-08-29：对照 Kong Gateway 与 Apache APISIX 的成熟机制，记录可借鉴点，
> 供实现 ApiGateKeeper 各模块时对照，避免重复设计。

## 1. 借鉴映射表

| 我们的设计点 | Kong 对应 | APISIX 对应 | 借鉴做法 |
|---|---|---|---|
| 应用/API 规则分层 | 插件作用域：global / service / route / consumer | global_rules + route / consumer 插件 | 应用级规则 ≈ global/service；API 级规则 ≈ route |
| 规则执行阶段 access/filter | PDK 阶段：rewrite / access / header_filter / body_filter / log | 插件 phase：rewrite / access / header_filter / body_filter / log | 细化阶段：access（请求控制）→ header_filter（响应头）→ body_filter（响应体）→ log（记录） |
| 身份维度不统一（dim:user） | Consumer：认证插件把凭证归一为 consumer.id / username | Consumer + `consumer_name` 内置变量 | 增加“消费者（Consumer）”概念：dim:user 解析结果归一为消费者标识 |
| 缺省前缀映射 | Route `strip_path` + Service path | proxy-rewrite `regex_uri` / upstream `pass_host` | `strip_path` 语义 = 剥离 base_path；regex_uri = 前缀替换 / 加前缀 |
| 限流维度引用 | rate-limiting `key`: consumer / ip / credential / path / header | limit-count `key`: remote_addr / consumer_name / server_addr / arg_xxx | keys 参数直接引用维度（我们已有） |
| 响应转换 | response-transformer（头 / JSON 增删改） | response-rewrite（status / headers / body + vars 条件） | response_transform 的 replace/save 对应之；convert（JSON↔XML）需自研 |
| 跨请求状态（按 key 变量） | 无内建；pre-function + Redis / shared dict 自行实现 | 无内建；serverless + shared dict / Redis 自行实现 | 我们的变量系统是差异化，存储参考 Redis KV + TTL 设计 |
| 路由 / 应用匹配 | 路由字段（host / path / methods / headers） | radixtree + `vars` 表达式（http_* / arg_* / remote_addr） | 应用匹配与规则条件可用 vars 表达式语法 |
| 配置管理 | Admin API + declarative config（decK） | Admin API + etcd + Dashboard | 后续可加声明式导出 / 导入 |
| 可观测 | prometheus 插件（latency histogram） | prometheus 插件 | 指标名与标签设计对齐（app / api 标签） |

## 2. 最值得借鉴的 5 点

1. **Consumer（消费者）归一化身份**：Kong/APISIX 都通过认证插件把不同凭证
   （JWT / API Key / 令牌）归一成唯一的消费者标识。我们的 `dim:user` 解析链
   最终应落到"消费者标识"，而不是停留在"哪个字段"层面。
2. **插件阶段模型**：access / header_filter / body_filter / log 四阶段
   比我们现在的 access / filter 更精确——响应头加工和响应体加工应当分离。
3. **Route strip_path / proxy-rewrite**：缺省前缀映射的成熟实现
   （剥离 base_path、regex 前缀替换）。
4. **rate-limiting / limit-count 的 key 维度**：消费者 / IP / header / 参数
   都是声明式的字符串引用，与我们的维度定义同构，可直接对齐语法。
5. **全局 / 服务 / 路由插件优先级**：规则分层和作用域决策的现成参照。

## 3. 不要照搬的部分

- **按 key 的跨请求持久化变量**（save_variable / response 保存变量）：
  Kong 与 APISIX 都没有内建能力，只能靠自定义 Lua + Redis/shared dict 实现。
  这是我们的差异化特性，保留自研，存储层参考 Redis KV + TTL。
- **JSON↔XML 转换**：两者均无内建插件，response-transform 只做字段级操作。
- **自动加入 API 列表（auto_add_rules）**：两者都是"先定义路由再治理"，
  没有从流量自动登记 API 清单的机制，这也是我们的差异化。

## 4. 对应文档入口

- APISIX 插件：https://apisix.apache.org/docs/apisix/plugins/limit-count/
- Kong 插件：https://docs.konghq.com/hub/kong-inc/rate-limiting/
- APISIX vars 表达式：https://apisix.apache.org/docs/apisix/terminology/route/
- Kong 路由 strip_path：https://docs.konghq.com/gateway/latest/key-concepts/routes/
