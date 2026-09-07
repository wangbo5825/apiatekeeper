# S19 URL/Query/Header 攻击特征拦截

> 状态：未完成

## 1. 场景描述

在 access 阶段用正则/特征规则对 URL、query、header 做初步 WAF 式筛查：SQL
注入、XSS、路径穿越、异常 User-Agent 等探测特征，命中即 403/429 并可计入
扫描统计与自动拉黑。

## 2. 现状分析

- **未实现**：无正则/特征规则类型。
- 可复用基础：
  - access 阶段契约已携带 method / uri / headers / client_ip（正文不可用，
    因此只筛路径/query/header 是可行的）；
  - Context 可解析 `req.url / req.raw_url / req.query:* / req.header:*`；
  - 拒绝 + 统计 + 自动拉黑通道齐全（deny → Metrics::denied → 可选写事件）。

## 3. 设置方法（规划方案，待实现）

目标规则：

```json
{
  "name": "SQLi 初筛",
  "api_pattern": "*",
  "action_type": "pattern_block",
  "params": {
    "patterns": {
      "req.query": ["(union[%20\\s]*(all)?[%20\\s]*select)", "sleep\\s*\\("],
      "req.raw_url": ["(\\.\\./)+", "<script"],
      "req.header:user-agent": ["sqlmap", "nikto", "nuclei"]
    },
    "on_match": {"status": 403, "action": "count"}
  }
}
```

特征建议集中在独立配置（类黑名单表）而非写死在代码；命中次数接入 S05 行为
统计。

## 4. 测试方法（验收标准，待实现）

1. 请求 `/api/user?id=1 union select 1` → 403 且不转发。
2. 正常请求（含 `select` 单词的普通字段值，须小心）不误伤（正则限大小写/
   上下文）。
3. UA 为 sqlmap/nikto → 403；命中计数出现在 Metrics 或统计页。
4. 自动化：特征规则单测（正/反例表驱动），e2e 两例。

## 5. 后续工作

- 特征库管理面（增删、启用、严重级别）。
- body 特征检查依赖契约升级传 body。
- 与 S20 蜜罐、S05 行为检测的命中数据互通。
