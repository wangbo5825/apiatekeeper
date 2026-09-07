# S21 安全响应头与 CORS 治理

> 状态：已完成

## 1. 场景描述

浏览器客户端场景下统一治理响应安全头与跨域：CORS（Origin 白名单、预检）、
HSTS、X-Frame-Options、X-Content-Type-Options 等；反向代理转发前也可剥离/
注入请求头。

## 2. 现状分析

- Caddy 原生 `header` / `request_header` 指令可直接配置，网关 Caddyfile 已有
  先例（向处理器注入 X-Processor-Secret 用 request_header）。
- 网关 PHP 规则层也能做请求头注入（change_target set_headers）与响应头
  Content-Type 改写（response_transform）。
- 说明：该场景定位为"Caddy 层配置 + 现有规则组合"，无独立管理 UI。

## 3. 设置方法

在 `configs/Caddyfile` 站点块或独立 caddy.d 片段加：

```caddy
:8080 {
	# 响应安全头（所有响应）
	header {
		X-Content-Type-Options nosniff
		X-Frame-Options DENY
		Strict-Transport-Security "max-age=31536000"
		+Access-Control-Allow-Origin "https://app.example.com"
		+Access-Control-Allow-Methods "GET, POST, OPTIONS"
		+Access-Control-Allow-Headers "Authorization, Content-Type, X-Api-Key"
	}

	# 预检直接应答（放于 /admin 之前、网关路由之前的 handle）
	handle OPTIONS {
		respond 204
	}

	# 其余原有路由……
}
```

规则层补充示例：change_target set_headers 注入上游期望的内部头（见 S15）。

## 4. 测试方法

1. `curl -s -D - http://host/api/x` 检查上述响应头存在且值正确。
2. 跨域浏览器场景：带 Origin 的 GET / 预检 OPTIONS → 204 且
   Access-Control-Allow-Origin 匹配；不允许的 Origin 不带对应响应头。
3. `caddy validate --config configs/Caddyfile` 校验语法（修改后必须执行）。
4. e2e：在现有用例基础上断言安全头。

## 5. 后续工作

- 若要"按 API 差异"配置 CORS/安全头，需要把响应头规则纳入 filter 输出（当前
  filter 只改写 Content-Type）。
