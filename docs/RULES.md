# ApiGateKeeper 规则配置参考（V3）

> 规则 = 作用对象 + 类型 + 参数。参数可引用变量（请求级变量或声明变量）。
> 详细配置方法见下。原型见 admin-web/prototype.html。

## 1. 规则模型

### 1.1 作用对象

- **应用级规则**：作用于应用 base_path 下所有 API（如应用起始规则）
- **API 级规则**：作用于应用内某个具体 API（按方法 + 相对路径模板）
- 一个 API 可绑定多条规则；同一作用对象内按优先级 + 创建顺序执行

### 1.2 通用字段

| 字段 | 说明 | 示例 |
|---|---|---|
| `name` | 规则名称 | 创建订单限流 |
| `enabled` | 是否启用 | 1 |
| `priority` | 优先级（大者先执行） | 10 |
| `scope` | 作用对象：`app` 或 `api:<id>` | api:12 |
| `type` | 规则类型 | rate_limit |
| `params` | 类型专属参数（见下） | {"window":60,...} |

### 1.3 执行阶段

- **access（请求控制）**：stats / cache / rate_limit / auth / save_variable /
  change_target / blacklist / variable_check
- **filter（响应加工）**：response_transform —— 收到上游响应后，
  对响应 body 进行处理与转换（仅 buffer 模式；stream 路由不执行）

## 2. 变量系统（规则上下文）

规则参数通过变量取值，变量分两类：

### 2.1 请求级变量（内置，无需声明）

| 变量 | 含义 |
|---|---|
| `req.url` | 请求 API 相对路径（归一化模板，如 /orders/{id}） |
| `req.raw_url` | 原始路径 |
| `req.method` | HTTP 方法 |
| `req.ip` | 客户端 IP |
| `req.userid` | JWT subject（若有） |
| `req.apikey` | API Key（若有） |
| `req.header:<name>` | 请求头 |
| `req.query:<name>` | query 参数 |
| `req.path:<name>` | 路径参数 |

### 2.2 关联变量（声明后使用，按 key 存储）

在应用的“变量声明区域”声明，形式为"针对某个 key 的变量"：

| 声明示例 | key 维度 | 值 | 说明 |
|---|---|---|---|
| `last_url` | ip | 最近请求的 URL | 按客户端 IP 记住它上次请求的 URL |
| `last_ip` | userid | 最近请求的来源 IP | 按用户 ID 记住其上次来源 IP |
| `visit_count` | ip | 累计请求次数 | 由 stats 规则维护 |

引用语法：`var:<name>`。规则参数中出现 `var:last_url` 时，
按当前请求的 key 维度（如 ip）读取对应值。

**key 维度 = 任意请求级变量**（2026-08-29 确认）：

- `req.ip`：同 IP 上下文的请求共享同一槽位
- `req.url`：同 URL 上下文的请求共享同一槽位
- `req.query:<name>`：同 query 参数值的请求共享同一槽位
- `req.header:<name>`：同请求头值的请求共享同一槽位
- `req.userid` / `req.apikey` / `req.path:<name>`：同理

**可见性规则**：跨请求关联靠 key 值一致，不靠显式关联。
规则引用 `var:<name>` 时，系统取当前请求在该 key 维度上的值去查槽位；
取不到（key 缺失）时变量视为不存在。

### 2.3 维度定义（key 的来源）

变量声明中的 key 维度引用以下三类之一（2026-08-29 确认）：

- **标准维度**（内置）：`req.ip` / `req.url` / `req.method`
  —— 请求中必然可解析
- **参数化维度**：`req.query:<name>` / `req.header:<name>` / `req.path:<name>`
  —— 参数名在声明时写死（如 `req.query:uid`）；key 必须是确定字符串，
  不能用“任意 query 参数”作为动态维度
- **语义维度**（应用内自定义，解析链）：如
  `dim:user = [req.userid → req.header:X-User-Id → req.query:uid]`
  —— 按顺序取第一个非空值，解决同一概念（如“用户”）在不同接口
  字段名不同的问题

**解析规则**：解析链按声明顺序取第一个非空值；全部缺失 → 变量视为不存在。
规则参数可直接引用维度（如 `rate_limit keys: ["dim:user"]`）或变量
（`var:<name>`）。

## 3. 规则类型与详细配置

### 3.1 stats（统计计数）

用途：按变量维度计数，支撑后台统计与告警（如按 ip 累计、按 userid 计数）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `dimensions` | 计数维度（变量列表） | ["req.ip","req.userid"] |
| `count` | 计数值（默认 1） | 1 |
| `target` | 写往的声明变量（如 visit_count） | "visit_count" |
| `ttl` | 计数过期（秒） | 86400 |

```json
{"dimensions":["req.ip"],"target":"visit_count","ttl":86400}
```

### 3.2 cache（缓存）

用途：控制响应缓存（默认继承应用缺省缓存配置）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `mode` | auto / force / no-cache | force |
| `ttl` | 缓存时间（秒，force 必填） | 60 |
| `keys` | 缓存键变量（默认 URL+query） | ["req.url","req.query:lang"] |

```json
{"mode":"force","ttl":60,"keys":["req.url"]}
```

### 3.3 rate_limit（速率限制）

用途：按变量维度限流（可组合多个变量）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `window` | 时间窗口（秒） | 60 |
| `limit` | 阈值 | 100 |
| `burst` | 突发额度 | 20 |
| `keys` | 维度变量（可多选组合） | ["req.ip","req.userid"] |
| `auto_blacklist` | 超限自动拉黑 | 1 |
| `auto_blacklist_duration` | 拉黑时长（秒） | 3600 |

示例（按 IP+userid 组合限流；引用关联变量 last_ip 作为维度）：

```json
{"window":60,"limit":100,"burst":20,"keys":["req.ip","req.userid"]}
{"window":3600,"limit":10,"burst":0,"keys":["var:last_ip"]}
```

### 3.4 auth（认证）

用途：要求认证（JWT 或不透明令牌，令牌按应用隔离）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `type` | jwt / token | jwt |
| `secret` | HS256 密钥（jwt） | change-me |
| `public_key` | RS256 公钥 PEM（jwt） | "" |
| `issuer` / `audience` | 校验 issuer / audience | gatekeeper |
| `location` | 令牌传递位置：header / query / cookie（待确认） | header |

```json
{"type":"jwt","secret":"change-me","issuer":"gatekeeper"}
```

### 3.5 save_variable（保存变量）

用途：从请求提取值，保存为"针对某个 key 的变量"（声明区域需先声明）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `name` | 目标变量名（须已声明） | last_url |
| `key` | key 维度：req.ip / req.userid / req.apikey / global | req.ip |
| `from` | 取值来源 | req.url / req.query:code / req.header:x |
| `ttl` | 覆盖声明 TTL（可选） | 3600 |
| `overwrite` | 是否覆盖已有值 | 1 |

示例（按客户端 IP 保存其最后访问的 URL）：

```json
{"name":"last_url","key":"req.ip","from":"req.url","ttl":3600}
```

### 3.6 change_target（改变请求目标）

用途：改写请求目标（上游组 / 路径 / 请求头），实现动态路由。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `upstream` | 目标上游组（默认取应用上游） | 订单备用组 |
| `rewrite_path` | 路径改写（前缀替换 / 裁剪） | "strip:/v1" |
| `set_headers` | 设置请求头 | {"X-App":"orders"} |
| `strip_prefix` | 转发前剥离 base_path | 1 |

```json
{"upstream":"订单备用组","strip_prefix":true,"set_headers":{"X-App":"orders"}}
```

> **缺省映射（2026-08-29 确认）**：API 未配置任何 change_target / API 级转写时，
> 使用所属上游组的缺省映射——主要是前缀转写：
> 剥离应用 base_path（/v1 → 空）、前缀替换（/v1 → /api/v1）或加前缀。

### 3.7 blacklist（黑名单）

用途：按维度拦截（黑名单数据在应用的黑名单页维护，规则仅声明维度）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `dimensions` | 维度变量 | ["req.ip","req.userid"] |
| `reason` | 拦截原因（可选） | 风控 |

```json
{"dimensions":["req.ip"]}
```

### 3.8 variable_check（变量许可）

用途：校验关联变量是否存在 / 值匹配 / 剩余 TTL（动态访问许可）。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `name` | 目标变量名 | code |
| `require_value` | 要求值（留空=存在即可） | abc123 |
| `min_ttl` | 剩余生命期下限（秒） | 60 |

```json
{"name":"code","require_value":"abc123","min_ttl":60}
```

### 3.9 response_transform（响应加工，filter 阶段）

用途：对上游响应 body 进行处理与转换——替换 JSON 属性值、
把 JSON 属性保存到变量、JSON ↔ XML 互转。仅在 buffer 模式下可用。

| 配置项 | 说明 | 示例 |
|---|---|---|
| `op` | replace / save_variable / convert | replace |
| `path` | JSON 属性路径（点号，可含数组下标） | data.status |
| `value` | 替换值（静态值或 `var:<name>`，op=replace） | ok |
| `target` | 保存目标变量（op=save_variable，须已声明） | last_token |
| `key` | key 维度：req.ip / req.userid / req.apikey / global | req.userid |
| `ttl` | 变量 TTL（op=save_variable，可选） | 3600 |
| `format` | convert：json_to_xml / xml_to_json | json_to_xml |
| `root` | XML 根节点名（json_to_xml） | response |
| `content_type` | 输出 Content-Type（默认按转换自动设置） | application/xml |

示例：

```json
{"op":"replace","path":"data.status","value":"ok"}
{"op":"replace","path":"data.client_ip","value":"var:req.ip"}
{"op":"save_variable","path":"data.token","target":"last_token","key":"req.userid","ttl":3600}
{"op":"convert","format":"json_to_xml","root":"response"}
{"op":"convert","format":"xml_to_json"}
```

> 从返回结果中设置变量（op=save_variable）为**必须实现**的特性
> （2026-08-29 确认）：响应 JSON 属性 → 保存到声明变量 → 供后续请求引用。

## 4. 执行顺序与失败行为

同一请求匹配多条规则时，按优先级降序执行；同优先级按创建顺序。

| 规则类型 | 放行继续 | 失败行为 |
|---|---|---|
| stats | 是 | 忽略（不影响转发） |
| save_variable | 是 | 忽略（不影响转发） |
| cache | 是 | 回退缺省缓存配置 |
| change_target | 是 | 使用应用默认上游 |
| rate_limit | 否 | 429 + Retry-After；可触发自动拉黑 |
| auth | 否 | 401 + WWW-Authenticate |
| blacklist | 否 | 403 |
| variable_check | 否 | 403 |
| response_transform | 是（filter 阶段，收到响应后执行） | 非法 body / 转换失败 → 透传原始响应（待确认） |

## 5. 未登记 URL 的缺省行为

请求未命中任何 API 级规则时，按应用缺省配置处理：

1. `default_allow=1` → 放行到应用默认上游
2. `auto_add_rules=1` → 按归一化 URL 模式自动加入应用 API 列表（记录到日志，后台导入落库）
3. `default_allow=0` → 拒绝（默认响应码待确认）
