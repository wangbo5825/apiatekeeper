#!/usr/bin/env pwsh
<#
  apigate 本地测试（Windows/PowerShell，无需 FrankenPHP）

  启动：
    1. PHP 内置服务器运行处理器（dev-router.php，模拟 worker.php 分发）
    2. Python mock 上游（test/upstream.py，端口 9000）
  然后按 caddy-access-filter 契约直接调用 /__proc/request，覆盖
  透明透传、缓存策略、限流、黑名单、认证、变量、记录统计、Admin API。

  用法：pwsh -File test/local-test.ps1
#>

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$db = Join-Path $env:TEMP ("apigate-local-{0}.sqlite" -f $PID)
$procPort = 8081
$upPort = 9000
$pass = 0
$fail = 0

function Check([string]$name, [string]$expected, [string]$actual) {
    if ($actual -like "*$expected*") {
        Write-Host "PASS  $name"
        $script:pass++
    } else {
        Write-Host "FAIL  $name (expected [$expected] got [$actual])"
        $script:fail++
    }
}

function Wait-Port([int]$port) {
    for ($i = 0; $i -lt 30; $i++) {
        $m = netstat -ano | Select-String ":$port\s"
        if ($m -and ($m -match 'LISTENING')) {
            return
        }
        Start-Sleep -Milliseconds 500
    }
    throw "port $port did not start"
}

# 环境（子进程继承）
$env:APIGATE_DB_PATH = $db
$env:APIGATE_USE_APCU = '0'
$env:APIGATE_ADMIN_TOKENS = 'local-admin'
$env:APIGATE_PROCESSOR_SECRET = 'local-secret'
$env:APIGATE_JWT_SECRET = 'local-secret'
$env:APIGATE_JWT_ISSUER = 'apigate'

Remove-Item -Force $db -ErrorAction SilentlyContinue

$proc = Start-Process -FilePath 'php' -ArgumentList '-S', "127.0.0.1:$procPort", 'processor/dev-router.php' -WorkingDirectory $root -PassThru -WindowStyle Hidden
$up = Start-Process -FilePath 'python' -ArgumentList 'test/upstream.py' -WorkingDirectory $root -PassThru -WindowStyle Hidden

try {
    Wait-Port $procPort
    Wait-Port $upPort

    $base = "http://127.0.0.1:$procPort"
    $admin = @('-H', 'X-Admin-Token: local-admin', '-H', 'Content-Type: application/json')

    # 1) 健康检查
    $health = & curl.exe -s "$base/admin/api/health" -H 'X-Admin-Token: local-admin'
    Check 'admin health' '"status":"ok"' $health

    # 2) 透明透传（无规则 → allow）
    $acc = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r1","method":"GET","uri":"/hello","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}'
    Check 'transparent access allow' '"decision":"allow"' $acc

    # 3) 缓存策略：force → route 提示 + filter 注入 Cache-Control
    & curl.exe -s -X POST "$base/admin/api/rules" @admin `
        -d '{"name":"cache","api_pattern":"GET /api/cache/*","client_pattern":"*","action_type":"cache","params":{"mode":"force","ttl":30}}' | Out-Null
    $acc2 = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r2","method":"GET","uri":"/api/cache/1","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}'
    Check 'cache force route hint' '"cache":"force"' $acc2
    $fil2 = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"filter","id":"r2","status":200,"headers":{"content-type":"application/json"},"bytes":10,"duration_ms":5,"upstream":"10.0.0.1:9000"}'
    Check 'cache force header' 'max-age=30' $fil2

    # 4) 限流：窗口 60s 上限 2 → 第 3 次 deny 429
    & curl.exe -s -X POST "$base/admin/api/rules" @admin `
        -d '{"name":"rl","api_pattern":"GET /api/rl/*","client_pattern":"*","action_type":"rate_limit","params":{"window":60,"limit":2,"keys":["ip"]}}' | Out-Null
    $rlBody = '{"phase":"access","id":"r3","method":"GET","uri":"/api/rl/a","headers":{},"client_ip":"1.2.3.4","proto":"HTTP/1.1"}'
    & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' -d $rlBody | Out-Null
    & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' -d $rlBody | Out-Null
    $rl3 = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' -d $rlBody
    Check 'rate limit deny 429' '"status":429' $rl3

    # 5) 黑名单：加入后 403
    & curl.exe -s -X POST "$base/admin/api/blacklist" @admin -d '{"dimension":"ip","value":"9.9.9.9"}' | Out-Null
    $bl = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r4","method":"GET","uri":"/api/x","headers":{},"client_ip":"9.9.9.9","proto":"HTTP/1.1"}'
    Check 'blacklist deny 403' '"status":403' $bl

    # 6) 认证：无 token 401；带有效 JWT 放行
    & curl.exe -s -X POST "$base/admin/api/rules" @admin `
        -d '{"name":"auth","api_pattern":"GET /api/auth/*","client_pattern":"*","action_type":"auth","params":{"type":"jwt"}}' | Out-Null
    $noAuth = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r5","method":"GET","uri":"/api/auth/x","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}'
    Check 'auth deny 401' '"status":401' $noAuth
    $jwt = (& php processor/dev_make_token.php local-secret dev-user apigate).Trim()
    $authOk = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d ('{"phase":"access","id":"r6","method":"GET","uri":"/api/auth/x","headers":{"authorization":"Bearer ' + $jwt + '"},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}')
    Check 'auth allow with jwt' '"decision":"allow"' $authOk

    # 7) 变量：先许可（无变量）403 → 捕获 → 放行
    & curl.exe -s -X POST "$base/admin/api/rules" @admin `
        -d '{"name":"var-capture","api_pattern":"GET /api/ticket/*","client_pattern":"*","action_type":"variable","params":{"mode":"capture","name":"code","from":"query:code","ttl":300}}' | Out-Null
    & curl.exe -s -X POST "$base/admin/api/rules" @admin `
        -d '{"name":"var-perm","api_pattern":"GET /api/private/*","client_pattern":"*","action_type":"variable","params":{"mode":"permission","name":"code"}}' | Out-Null
    $permBefore = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r7","method":"GET","uri":"/api/private/x","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}'
    Check 'variable permission deny before capture' '"status":403' $permBefore
    & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r8","method":"GET","uri":"/api/ticket/x?code=abc","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}' | Out-Null
    $permAfter = & curl.exe -s -X POST "$base/__proc/request" -H 'X-Processor-Ver: 1' -H 'Content-Type: application/json' `
        -d '{"phase":"access","id":"r9","method":"GET","uri":"/api/private/x","headers":{},"client_ip":"127.0.0.1","proto":"HTTP/1.1"}'
    Check 'variable permission allow after capture' '"decision":"allow"' $permAfter

    # 8) 记录统计 + Admin API
    $apis = & curl.exe -s "$base/admin/api/apis" -H 'X-Admin-Token: local-admin'
    Check 'api inventory' '/api/cache/{id}' $apis
    $agg = & curl.exe -s -X POST "$base/admin/api/stats/aggregate" -H 'X-Admin-Token: local-admin' -H 'Content-Type: application/json' -d '{"bucket":"hour"}'
    Check 'aggregate' 'processed_until' $agg
    $clients = & curl.exe -s "$base/admin/api/clients" -H 'X-Admin-Token: local-admin'
    Check 'client stats' 'client_key' $clients
} finally {
    Stop-Process -Id $proc.Id, $up.Id -Force -ErrorAction SilentlyContinue
    Remove-Item -Force $db -ErrorAction SilentlyContinue
}

Write-Host ""
Write-Host "$pass passed, $fail failed"
exit ($(if ($fail -gt 0) { 1 } else { 0 }))
