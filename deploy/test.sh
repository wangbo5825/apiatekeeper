#!/usr/bin/env bash
#
# ApiGateKeeper post-install verification.
# Usage:
#   ./deploy/test.sh [base_url]
#   PREFIX=/opt/gatekeeper ./deploy/test.sh
#
# Covers: admin health -> app present -> traffic forwarded -> log written ->
# background import -> API auto-register.

set -euo pipefail

PREFIX="${PREFIX:-/opt/gatekeeper}"
BASE_URL="${1:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
ADMIN_USER="${GATEKEEPER_ADMIN_USER:-admin}"
ADMIN_PASS="${GATEKEEPER_ADMIN_PASS:-admin123456}"

die() { echo "!! $*" >&2; rm -f "$JAR"; exit 1; }
trap 'rm -f "$JAR"' EXIT

# note: frankenphp php-cli does not support -m; use the official -r form.
if command -v php >/dev/null 2>&1 && php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
  PHP_BIN="$(command -v php)"
elif [ -n "${FRAMPP_HOME:-}" ] && [ -x "$FRAMPP_HOME/bin/php" ] \
  && "$FRAMPP_HOME/bin/php" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
  PHP_BIN="$FRAMPP_HOME/bin/php"
elif command -v frankenphp >/dev/null 2>&1; then
  PHP_BIN="$(command -v frankenphp) php-cli"
else
  die "A PHP with pdo_sqlite is required for import and queries"
fi

echo "== 1. Admin health check =="
curl -fsS "$BASE_URL/admin/api/health" && echo

echo "== 2. Ensure installed and logged in =="
STATUS="$(curl -fsS "$BASE_URL/admin/api/status")"
if echo "$STATUS" | grep -q '"installed":false'; then
  echo "   System not installed; running the install wizard (admin/$ADMIN_PASS)."
  curl -fsS -X POST "$BASE_URL/admin/api/install" -H 'Content-Type: application/json' \
    -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASS\"}" >/dev/null
fi
curl -fsS -X POST "$BASE_URL/admin/api/login" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASS\"}" -c "$JAR" >/dev/null \
  || die "Login failed (wrong GATEKEEPER_ADMIN_USER/GATEKEEPER_ADMIN_PASS?)"
echo "   Logged in as $ADMIN_USER"

echo "== 3. Make sure a base_path=/ app exists =="
APPS="$(curl -fsS "$BASE_URL/admin/api/apps" -b "$JAR")"
APP_ID="$(echo "$APPS" | $PHP_BIN -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d as $a){ if(($a["base_path"]??"")==="/"){echo $a["id"]; exit;} }')"
[ -n "$APP_ID" ] || die "No base_path=/ app found (install wizard should create one)"
echo "   app id: $APP_ID"

echo "== 4. Send a test request (gateway processing + logging) =="
LOG="$PREFIX/data/logs/$(date +%F).log"
BEFORE=0
[ -f "$LOG" ] && BEFORE="$(wc -l < "$LOG")"
PATH_SEG="gatekeeper-test-$(date +%s)"
CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/$PATH_SEG" || true)"
echo "   GET /$PATH_SEG -> HTTP $CODE (502 means the fallback upstream is down but the gateway handled it)"
sleep 1
AFTER=0
[ -f "$LOG" ] && AFTER="$(wc -l < "$LOG")"
echo "   log lines: $BEFORE -> $AFTER"
[ "$AFTER" -gt "$BEFORE" ] || die "Log did not grow; check the processor chain"

echo "== 5. Background import + API auto-register =="
cd "$PREFIX" && $PHP_BIN processor/import.php

ROWS="$($PHP_BIN -r '
$p = new PDO("sqlite:'"$PREFIX"'/data/apigate.sqlite");
foreach ($p->query("SELECT method, template, auto FROM apis WHERE template LIKE '\''%gatekeeper-test%'\'' ORDER BY id DESC LIMIT 3") as $r) {
  echo implode(" | ", $r) . "\n";
}')"
echo "   registered:"
echo "$ROWS" | sed 's/^/     /'
echo "$ROWS" | grep -q "auto=1\|1$" || die "No auto-registered API found"

echo "== 6. Done =="
echo "Admin UI: $BASE_URL/admin/"
echo "With a real upstream, request business paths under the app to auto-register APIs and collect stats."
