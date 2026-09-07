# S03 按 API 差异化动态缓存

> 状态：部分完成

## 1. 场景描述

不同 API 使用不同缓存策略：强制缓存固定 TTL、禁止缓存、遵循 HTTP 缓存协议，
并且策略按 API 独立配置、集中展示，比在 Caddy/Souin 层手写更清晰。

## 2. 现状分析

### 已完成

- cache 规则可绑应用级或 API 级（scope + api_pattern，如
  `GET /orders/{id}`），参数：mode（force / no-cache / auto）、ttl、keys
  （[RuleEngine.php](../../processor/src/RuleEngine.php:195)）。
- filter 阶段输出 `Cache-Control: public, max-age=<ttl>` 或 `no-store`
  （[Contract.php](../../processor/src/Contract.php:192)）；Souin 负责实际缓存。
- 管理 UI：应用详情 → 规则 → cache 类型表单（模式/TTL/缓存键）。

### 未完成 / 缺口

- 应用设置页的"缺省缓存开/关 + TTL"只保存不生效（
  `App::cacheEnabled()/cacheTtl()` 无调用方）。
- 规则参数 `keys` 未接入 Souin 的缓存键控制。
- force 策略实际生效依赖 caddy-access-filter"同请求两阶段同 id"修复后的
  FRAMPP 二进制（旧二进制 e2e 该项失败，见
  [ISSUES.md](../ISSUES.md) 第 4/5 节）。

## 3. 设置方法

管理 UI：应用详情 → 规则 → 新建规则 → 作用对象选"API 级"，规则类型 cache。

等价 REST：

```bash
curl -s -X POST http://host/admin/api/apps/{appId}/rules \
  -H 'Content-Type: application/json' \
  -d '{"name":"商品详情缓存","api_pattern":"GET /products/{id}",
       "client_pattern":"*","action_type":"cache","phase":"access",
       "scope":"app","params":{"mode":"force","ttl":60,"keys":["req.url"]}}'

# 另一个 API 禁止缓存
curl -s -X POST http://host/admin/api/apps/{appId}/rules \
  -H 'Content-Type: application/json' \
  -d '{"name":"余额不缓存","api_pattern":"GET /balance",
       "client_pattern":"*","action_type":"cache","phase":"access",
       "scope":"app","params":{"mode":"no-cache"}}'
```

规则按"API 具体度 > 优先级"匹配，多个 API 可各自绑定不同缓存规则。

## 4. 测试方法

### 自动化 / E2E

`./test/e2e.sh` 含缓存用例：对 `GET /api/cache/*` 设 force + ttl=30，请求后
检查响应头 `Cache-Control: public, max-age=30`。

### 手工

1. 对 `/products/{id}` 建 force/60s 规则，对 `/balance` 建 no-cache 规则。
2. 分别 curl `-D -` 两次：
   - 商品接口第二次响应应命中缓存（如 Souin 日志 / 上游只收到一次请求）。
   - 余额接口两次都到上游且响应带 `Cache-Control: no-store`。
3. 无规则接口不注入缓存头（auto 交 Souin 按协议处理）。

预期：每个 API 的缓存行为符合其规则；不同 API 互不干扰。

## 5. 后续工作

- 让应用缺省缓存配置真正生效（无规则时作为默认策略）。
- 把 keys 接入 Souin 键生成；重编 FRAMPP 后回归 e2e 缓存项。
