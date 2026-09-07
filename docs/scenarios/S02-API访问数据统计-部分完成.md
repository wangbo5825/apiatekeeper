# S02 API 访问数据统计

> 状态：部分完成

## 1. 场景描述

统计不同 API（以及客户端）的访问数量、错误、拦截、延迟，形成趋势与排行，
供管理员"看着数据配规则"；另以 Prometheus 格式暴露实时指标。

## 2. 现状分析

### 已完成

- 热路径只追加 JSON 日志（含 app_id / method / api_template / status /
  duration_ms / denied / client_key 等），被拒绝请求也记录
  （[Recorder.php](../../processor/src/Recorder.php)）。
- cron 每分钟导入 SQLite（request_logs），自动登记 API，并按小时/天聚合到
  api_stats / client_stats（[Importer.php](../../processor/src/Importer.php)、
  [Aggregator.php](../../processor/src/Aggregator.php:22)）。
- 管理端接口：
  - `GET /admin/api/apps/{id}/apis`：每个 API 的 requests / errors / denied /
    avg_ms（LEFT JOIN api_stats）。
  - `GET /admin/api/apps/{id}/stats`：按小时桶趋势。
  - `GET /admin/api/apps/{id}/clients`：按客户端汇总。
  - `GET /admin/api/metrics`：Prometheus 文本（请求量 / 错误 / 拦截 / 延迟
    直方图 / 上游健康 / 导入滞后）。
- 管理 UI：仪表盘概览与趋势、应用概览、API 列表请求数列、客户端页。

### 未完成 / 缺口

- Prometheus 指标目前按 app 聚合，**没有 api 维度标签**（管理端 Metrics 页的
  说明写 app/api/client/status，与实际输出不符）。
- api_stats 聚合 JOIN apis 表：日志模板须能在 apis 找到对应行才计入；
  request_logs 本身是全量。
- 管理端趋势无 P95/分位数展示；导出功能见 S24。

## 3. 设置方法

- 确认 cron 已注册（安装向导或 `deploy/install-frampp.sh` 会注册）：
  `* * * * * php processor/import.php >> data/logs/import.log 2>&1`。
- 无额外业务配置；应用 default_allow / auto_add_rules 决定哪些请求计入并自动
  登记（见 [REQUIREMENTS.md](../../REQUIREMENTS.md) 第 9/10 节）。
- 手工触发聚合：仪表盘"运行聚合"或
  `POST /admin/api/apps/{id}/stats -d '{"bucket":"hour"}'`。

## 4. 测试方法

### 自动化

```bash
php processor/tests/run.php
```

覆盖：metrics_test.php（Prometheus 输出）、adminapi_test.php（apis/stats/
clients 接口）、contract_test.php（记录字段）。

### 手工 / E2E

1. `./test/e2e.sh`（或手工）打一批不同路径请求，含一次 404/403。
2. 执行 `php processor/import.php`。
3. 查询 `GET /admin/api/apps/{id}/apis`，确认自动登记的 API 请求数与实际一致；
   查 `/stats` 确认按小时桶数值；打开管理端 API 列表核对请求数列。
4. `curl /admin/api/metrics`，确认 `gatekeeper_http_requests_total{app=...}`
   与延迟直方图增长。

预期：管理端统计在导入后 1 分钟内可见；Prometheus 端点为按应用计数。

## 5. 后续工作

- Prometheus 增加 api / client 标签（与 Metrics.vue 文档对齐）。
- 趋势展示 P50/P95/P99（api_stats 需补直方图或独立延迟表）。
