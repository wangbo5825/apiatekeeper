# S06 API 语法与请求格式检测

> 状态：未完成

## 1. 场景描述

在网关层"初步检测语法是否正确"：

- 请求侧：方法是否允许、路径是否符合已登记 API 的形状/参数类型、编码与 query
  是否合法，畸形请求尽早拒绝（400/404），避免打到上游。
- 配置侧：管理员登记 API 模板 / 规则模式时校验语法（方法、模板、`{}` / `*`
  规则），避免无效配置。

## 2. 现状分析

### 已有的基础

- 路径归一化：数字 / UUID / 日期 / 长哈希 → `{id}`
  （[Normalizer.php](../../processor/src/Normalizer.php)）。
- 模板匹配：`{param}` 匹配一个路径段、`*` 匹配多段；仅用于规则匹配与清单归属。
- 登记冲突检测：保存 API 时检测重复 / 模板互相覆盖（AdminApi::apiConflict）。
- 应用级白名单：default_allow=0 + 关闭自动加入时，只有已登记 API 放行，
  未登记返回 404。

### 缺口

- `{param}` 不校验参数类型/格式（`/users/abc` 与 `/users/123` 都匹配
  `GET /users/{id}`）。
- 不校验方法白名单、query/header 形态、编码合法性。
- access 阶段契约不传请求体，body 语法检查（JSON/SQL 等）不可做
  （[CONTRACT-ADAPTER.md](../CONTRACT-ADAPTER.md) 已知限制）。
- API 模板与规则 api_pattern 保存时不校验语法（`{` 未闭合等照存不误）。

## 3. 设置方法（规划方案，待实现）

请求侧目标配置形态：

```json
{
  "name": "订单ID格式校验",
  "api_pattern": "GET /orders/{id}",
  "action_type": "shape_check",
  "params": {
    "segments": {"id": {"type": "int|uuid"}},
    "on_error": 400
  }
}
```

配置侧：在 apis / rules 的 Admin 保存路径加模板语法解析器（可复用
`Normalizer::templateMatches` 的段模型），非法返回 400 + 具体位置。

## 4. 测试方法（验收标准，待实现）

1. 对 `GET /orders/{id}` 配 int 校验：`/orders/123` 放行，
   `/orders/abc` 返回 400/404 且不转发上游。
2. 未登记路径在 default_allow=1 时仍放行（保持透明代理语义）——
   语法检测只作用于显式登记且有规则的 API。
3. Admin 侧：`POST /apps/{id}/apis` 提交 `GET /orders/{unclosed` 应返回 400；
   提交合法模板返回 200。
4. 自动化：新增 normalizer / shape_check 单测与 e2e 畸形路径用例。

## 5. 后续工作

- 定义参数类型集合（int / uuid / date / 枚举 / 正则）。
- 依赖契约升级传 body 后再评估 JSON/表单语法检测。
- 与 S05/S19 的扫描判定共用命中统计。
