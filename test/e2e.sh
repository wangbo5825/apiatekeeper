#!/usr/bin/env bash
#
# apigate 端到端测试（Linux/WSL）。
#
# 前置：
#   - 已构建 frankenphp 二进制（deploy/build.sh 产物或 FRAMPP 的 frankenphp）
#   - python3
#   - 在本仓库根目录运行
#
# 用法：FRANKENPHP=/path/to/frankenphp ./test/e2e.sh

set -uo pipefail

FRANKENPHP="${FRANKENPHP:-./deploy/bin/frankenphp}"
PORT="${PORT:-8080}"
UPSTREAM_PORT=9000
DB="${TMPDIR:-/tmp}/apigate-e2e-$$.sqlite"
JAR="${TMPDIR:-/tmp}/apigate-e2e-$$.cookies"
PID_LIST=()

export APIGATE_DB_PATH="$DB"
export APIGATE_USE_APCU=1
export APIGATE_PROCESSOR_SECRET="e2e-secret"
export APIGATE_UPSTREAM="127.0.0.1:${UPSTREAM_PORT}"

E2E_USER="e2e-admin"
E2E_PASS="e2e-pass-123456"

pass=0
fail=0

check() {
  local name="$1" expected="$2" actual="$3"
  if [[ "$actual" == *"$expected"* ]]; then
    echo "PASS  $name"
    pass=$((pass + 1))
  else
    echo "FAIL  $name (expected [$expected] got [$actual])"
    fail=$((fail + 1))
  fi
}

cleanup() {
  for pid in "${PID_LIST[@]:-}"; do kill "$pid" 2>/dev/null; done
  rm -f "$DB" "$JAR"
}
trap cleanup EXIT

echo ">> starting upstream on :${UPSTREAM_PORT}"
python3 ./test/upstream.py &
PID_LIST+=($!)
sleep 1

echo ">> starting frankenphp on :${PORT}"
"$FRANKENPHP" run --config ./configs/Caddyfile &
PID_LIST+=($!)
sleep 2

BASE="http://127.0.0.1:${PORT}"
AUTH=(-b "$JAR")

# 0) 首次使用：安装向导（建管理员 + 默认应用），然后登录拿会话 Cookie
st=$(curl -s "$BASE/admin/api/status")
check "fresh install status" '"installed":false' "$st"
curl -s -X POST "$BASE/admin/api/install" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$E2E_USER\",\"password\":\"$E2E_PASS\"}" >/dev/null
curl -s -X POST "$BASE/admin/api/login" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$E2E_USER\",\"password\":\"$E2E_PASS\"}" -c "$JAR" >/dev/null

APPS=$(curl -s "$BASE/admin/api/apps" "${AUTH[@]}")
APP_ID=$(printf '%s' "$APPS" | python3 -c 'import json,sys; print([a["id"] for a in json.load(sys.stdin) if a["base_path"]=="/"][0])')
echo ">> default app id: $APP_ID"
APP="admin/api/apps/$APP_ID"

# 1) 缺省透明代理
body=$(curl -s "$BASE/hello")
check "transparent proxy" "upstream-ok" "$body"

# 2) 缓存策略：force -> Cache-Control: public, max-age=30
curl -s -X POST "$BASE/$APP/rules" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"name":"cache-force","api_pattern":"GET /api/cache/*","client_pattern":"*","action_type":"cache","params":{"mode":"force","ttl":30}}' >/dev/null
curl -s "$BASE/api/cache/1" >/dev/null
cc=$(curl -s -D - -o /dev/null "$BASE/api/cache/1" | grep -i '^cache-control:' | tr -d '\r')
check "cache force header" "public, max-age=30" "$cc"

# 3) 限流：窗口 60s 上限 2 -> 第 3 次 429
curl -s -X POST "$BASE/$APP/rules" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"name":"rl","api_pattern":"GET /api/rl/*","client_pattern":"*","action_type":"rate_limit","params":{"window":60,"limit":2,"keys":["ip"]}}' >/dev/null
curl -s -o /dev/null "$BASE/api/rl/a"
curl -s -o /dev/null "$BASE/api/rl/a"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/rl/a")
check "rate limit 429" "429" "$code"

# 4) 黑名单：表项 + 黑名单规则 -> 命中 403
#    （维度用 req.ip，与 Blacklist::hits 的变量引用一致；仅加表项不会触发拒绝）
curl -s -X POST "$BASE/$APP/blacklist" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"dimension":"req.ip","value":"127.0.0.1","reason":"e2e"}' >/dev/null
curl -s -X POST "$BASE/$APP/rules" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"name":"bl","api_pattern":"*","client_pattern":"*","action_type":"blacklist","params":{"dimensions":["req.ip"]}}' >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/black/x")
check "blacklist 403" "403" "$code"
curl -s -X DELETE "$BASE/$APP/blacklist/1" "${AUTH[@]}" >/dev/null

# 5) 认证：JWT 规则 -> 无 token 401
curl -s -X POST "$BASE/$APP/rules" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"name":"auth","api_pattern":"GET /api/auth/*","client_pattern":"*","action_type":"auth","params":{"type":"jwt"}}' >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/auth/x")
check "auth 401 without token" "401" "$code"

# 6) SSE 流式透传
first=$(curl -s -N --max-time 3 "$BASE/sse" | head -n 1)
check "sse stream passthrough" "data: event 0" "$first"

# 7) Admin API：先跑导入器把 auto_add 的 API 落库，再查清单 + 聚合
"$FRANKENPHP" php-cli processor/import.php >/dev/null 2>&1 || true
apis=$(curl -s "$BASE/$APP/apis" "${AUTH[@]}")
check "api inventory" "/api/auth/x" "$apis"
agg=$(curl -s -X POST "$BASE/$APP/stats" "${AUTH[@]}" -H 'Content-Type: application/json' -d '{"bucket":"hour"}')
check "aggregate" "processed_until" "$agg"

# 8) 登出后受保护接口返回 401
curl -s -X POST "$BASE/admin/api/logout" "${AUTH[@]}" >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/api/apps" "${AUTH[@]}")
check "logout blocks api" "401" "$code"

echo
echo "${pass} passed, ${fail} failed"
exit $((fail > 0 ? 1 : 0))
