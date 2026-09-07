# S17 API 版本化与废弃治理

> 状态：未完成

## 1. 场景描述

同一业务按版本共存（/v1、/v2），各版本独立配置策略；版本废弃时返回 410 +
Sunset/Deprecation 响应头引导迁移，而不是悄悄把请求打到旧代码。

## 2. 现状分析

- 已具备：多应用模型天然适合按版本分应用（base_path=/v1、/v2），每版本独立
  上游/规则/统计；规则级动态路由可把某版本切到新后端。
- **缺口**：无"API 废弃"状态字段与响应行为：apis 表没有 deprecated 标记；
  规则无"返回 410 + 自定义头"的动作类型。

## 3. 设置方法（规划方案，待实现）

目标：

1. apis 增加 `deprecated_at` / `sunset_at`（或版本应用的废弃配置）。
2. 规则新增 deny 类动作或 API 属性：命中废弃 API 返回 410，并注入
   `Deprecation: true`、`Sunset: <date>`、`Link: </v2>; rel="successor-version"`。
3. 管理 UI：API 列表"废弃"开关；设置页版本入口 URL。

示意 REST（规划）：

```json
PATCH /admin/api/apps/{appId}/apis/{id}
{"deprecated": true, "successor": "/v2/orders"}
```

## 4. 测试方法（验收标准，待实现）

1. 把 v1 某 API 标记废弃 → 请求返回 410 + Sunset 头，body 提示 successor。
2. v1 其它未废弃 API 正常；v2 同路径正常转发。
3. 统计中废弃 API 请求单列（或标记），用于评估迁移进度。
4. e2e：升级路径回归（先建 v1/v2 两个应用再标记）。

## 5. 后续工作

- 废弃宽限期（标记后 N 天开始 410）。
- 自动生成迁移报告（S24 报表扩展）。
