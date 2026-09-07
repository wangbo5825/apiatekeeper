#!/usr/bin/env bash
#
# ApiGateKeeper one-click installer (non-Docker; for servers with a FRAMPP
# FrankenPHP binary that includes caddy-access-filter).
#
# Prerequisites:
#   1) A frankenphp binary that includes caddy-access-filter (required) and the
#      HTTP cache module http.handlers.cache (optional)
#   2) A release package built by deploy/package.sh (or let this script build it)
#
# Usage:
#   sudo ./deploy/install.sh
#   sudo PREFIX=/opt/gatekeeper FRANKENPHP=/usr/local/bin/frankenphp ./deploy/install.sh
#
# Environment variables:
#   PREFIX                install dir, default /opt/gatekeeper
#   RELEASE               release dir (default: auto-run package.sh)
#   FRANKENPHP            frankenphp binary path (default: found on PATH)
#   APIGATE_UPSTREAM      fallback upstream, default http://127.0.0.1:9000
#                         (scheme stripped before writing env; Caddy dynamic
#                         upstreams must be host:port)
#   APIGATE_PROCESSOR_SECRET / APIGATE_ADMIN_TOKENS / APIGATE_JWT_SECRET
#                         default random (legacy admin tokens are no longer
#                         used for the admin UI; see the first-visit wizard)
#   APIGATE_DB_PATH / APIGATE_LOG_DIR / APIGATE_USE_APCU
#   CREATE_DEFAULT_APP    unused: the install wizard creates the default app

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PREFIX="${PREFIX:-/opt/gatekeeper}"
RELEASE="${RELEASE:-}"
FRANKENPHP="${FRANKENPHP:-$(command -v frankenphp || true)}"
USER_NAME="${GATEKEEPER_USER:-gatekeeper}"
PORT="${GATEKEEPER_PORT:-8080}"

UPSTREAM="${APIGATE_UPSTREAM:-http://127.0.0.1:9000}"
UPSTREAM="${UPSTREAM#http://}"
UPSTREAM="${UPSTREAM#https://}"
UPSTREAM="${UPSTREAM%/}"
PROCESSOR_SECRET="${APIGATE_PROCESSOR_SECRET:-$(openssl rand -hex 16 2>/dev/null || echo change-me)}"
ADMIN_TOKENS="${APIGATE_ADMIN_TOKENS:-$(openssl rand -hex 16 2>/dev/null || echo admin)}"
JWT_SECRET="${APIGATE_JWT_SECRET:-$(openssl rand -hex 16 2>/dev/null || echo change-me)}"
DB_PATH="${APIGATE_DB_PATH:-$PREFIX/data/apigate.sqlite}"
LOG_DIR="${APIGATE_LOG_DIR:-$PREFIX/data/logs}"
USE_APCU="${APIGATE_USE_APCU:-1}"

die() { echo "!! $*" >&2; exit 1; }

# CLI args (env vars also supported)
while [ $# -gt 0 ]; do
  case "$1" in
    --release) RELEASE="${2:-}"; shift 2 ;;
    --prefix) PREFIX="${2:-}"; shift 2 ;;
    *) die "Unknown option: $1 (supported: --release <dir> --prefix <dir>)" ;;
  esac
done

echo "== ApiGateKeeper one-click install =="

# ---------- preflight ----------
[ -n "$FRANKENPHP" ] || die "frankenphp binary not found. Install FRAMPP first, or set FRANKENPHP=/path/to/frankenphp"
FRANKENPHP="$(realpath "$FRANKENPHP")"
echo ">> FRAMPP binary: $FRANKENPHP"

MODULES="$("$FRANKENPHP" list-modules 2>/dev/null || true)"
echo "$MODULES" | grep -q "http.handlers.access_filter" \
  || die "FRAMPP does not include caddy-access-filter (http.handlers.access_filter not in list-modules). Rebuild FRAMPP with it"
echo "   [OK] caddy-access-filter included"
if echo "$MODULES" | grep -q "http.handlers.cache"; then
  echo "   [OK] HTTP cache module included (http.handlers.cache)"
else
  echo "   [!!] HTTP cache module not found: comment out the global cache block and the cache directive in configs/Caddyfile"
fi

echo ">> Checking embedded PHP extensions"
# note: frankenphp php-cli does not support -m (it treats it as a script path);
# use -r to read get_loaded_extensions().
PHP_MODS="$("$FRANKENPHP" php-cli -r 'echo implode("\n", get_loaded_extensions());' 2>/dev/null || true)"
[ -n "$PHP_MODS" ] || PHP_MODS="$(php -m 2>/dev/null || true)"
for ext in pdo_sqlite apcu openssl simplexml; do
  if echo "$PHP_MODS" | grep -qi "^$ext"; then
    echo "   [OK] $ext"
  else
    echo "   [!!] $ext not detected (confirm manually if FRAMPP bundles it; pdo_sqlite is required)"
  fi
done

# release package
if [ -z "$RELEASE" ]; then
  if [ -d "$ROOT/dist/gatekeeper-release" ]; then
    RELEASE="$ROOT/dist/gatekeeper-release"
  else
    echo ">> No release dir given; running package.sh"
    "$ROOT/deploy/package.sh"
    RELEASE="$ROOT/dist/gatekeeper-release"
  fi
fi
[ -d "$RELEASE/processor" ] || die "Release package incomplete: $RELEASE"
if [ ! -d "$RELEASE/admin-web/dist" ] || [ -z "$(ls -A "$RELEASE/admin-web/dist" 2>/dev/null)" ]; then
  echo "   [!!] admin-web/dist missing in the release; admin UI unavailable (run npm run build first)"
fi

# ---------- install ----------
[ "$(id -u)" = "0" ] || die "Run as root (creates a system user and a systemd service)"
mkdir -p "$PREFIX"
cp -r "$RELEASE/." "$PREFIX/"
mkdir -p "$PREFIX/data" "$LOG_DIR"
[ -f "$PREFIX/processor/config.php" ] || cp "$PREFIX/configs/config.example.php" "$PREFIX/processor/config.php"

if ! id "$USER_NAME" >/dev/null 2>&1; then
  useradd --system --home "$PREFIX" --shell /usr/sbin/nologin "$USER_NAME"
fi
chown -R "$USER_NAME":"$USER_NAME" "$PREFIX"

# importer PHP: prefer system php with pdo_sqlite, else the embedded FRAMPP php
if command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -q pdo_sqlite; then
  PHP_CLI="$(command -v php)"
else
  PHP_CLI="$FRANKENPHP php-cli"
fi
echo ">> importer PHP: $PHP_CLI"

# environment file (used by the systemd EnvironmentFile)
cat > "$PREFIX/gatekeeper.env" <<EOF
APIGATE_UPSTREAM=$UPSTREAM
APIGATE_PROCESSOR_SECRET=$PROCESSOR_SECRET
APIGATE_JWT_SECRET=$JWT_SECRET
APIGATE_DB_PATH=$DB_PATH
APIGATE_LOG_DIR=$LOG_DIR
APIGATE_USE_APCU=$USE_APCU
EOF
chown "$USER_NAME":"$USER_NAME" "$PREFIX/gatekeeper.env"
chmod 600 "$PREFIX/gatekeeper.env"

# systemd service
cat > /etc/systemd/system/gatekeeper.service <<EOF
[Unit]
Description=ApiGateKeeper (FrankenPHP + caddy-access-filter)
After=network.target

[Service]
Type=simple
User=$USER_NAME
Group=$USER_NAME
WorkingDirectory=$PREFIX
EnvironmentFile=$PREFIX/gatekeeper.env
ExecStart=$FRANKENPHP run --config $PREFIX/configs/Caddyfile
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable gatekeeper >/dev/null 2>&1 || true
systemctl restart gatekeeper >/dev/null 2>&1 || true

# cron: background importer (log import / API auto-register / blacklist events / aggregation)
CRON_LINE="* * * * * cd $PREFIX && $PHP_CLI $PREFIX/processor/import.php >> $PREFIX/data/logs/import.log 2>&1"
EXISTING_CRON="$(crontab -u "$USER_NAME" -l 2>/dev/null | grep -v "processor/import.php" || true)"
printf '%s\n%s\n' "$EXISTING_CRON" "$CRON_LINE" | crontab -u "$USER_NAME" -

# wait until the service is ready (health check is public)
for _ in $(seq 1 30); do
  curl -fsS "http://127.0.0.1:$PORT/admin/api/health" >/dev/null 2>&1 && break
  sleep 1
done
if ! curl -fsS "http://127.0.0.1:$PORT/admin/api/health" >/dev/null 2>&1; then
  echo "!! Service not ready; check: systemctl status gatekeeper, journalctl -u gatekeeper, logs under $LOG_DIR" >&2
fi

echo
echo "== Install complete =="
echo "Gateway:      http://<server>:$PORT"
echo "Admin UI:     http://<server>:$PORT/admin/  (first visit opens the install wizard)"
echo "Processor:    $PROCESSOR_SECRET"
echo "Fallback:     $UPSTREAM"
echo "Logs:         $LOG_DIR"
echo "Importer:     $PHP_CLI $PREFIX/processor/import.php (cron, every minute)"
echo
echo "Next: run $PREFIX/deploy/test.sh for full verification"
