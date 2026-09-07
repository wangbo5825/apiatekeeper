#!/usr/bin/env bash
#
# ApiGateKeeper -> FRAMPP mount script (v2, simplified)
#
# The release code is NOT copied into FRAMPP. The Gatekeeper code root
# (a directory containing processor/, admin-web/, configs/ and data/, called
# WEB_DIR below) may live anywhere. This script only:
#   1) generates processor/config.php on first install (static config, paths
#      point to WEB_DIR; secrets are generated once and never rotated)
#   2) renders the standalone Caddy fragment ->
#      $FRAMPP_HOME/etc/caddy.d/api-gatekeeper.caddy (all paths point to
#      WEB_DIR) and makes sure the FRAMPP Caddyfile imports it
#   3) restarts FRAMPP, installs the importer cron and (first run) creates
#      nothing else -- the first visit to /admin/ opens the install wizard
#
# Usage:
#   First install (code already rsynced to WEB_DIR):
#     sudo FRAMPP_HOME=/root/frampp ./deploy/install-frampp.sh \
#       --dir /home/stream/gatekeeper --port 28090
#   Regular updates (code changed):
#     rsync -av --delete --exclude processor/config.php \
#       dist/gatekeeper-release/ root@server:/home/stream/gatekeeper/
#     systemctl restart frampp
#     # re-run this script only when configs/api-gatekeeper.caddyfile changed
#
# Environment variables (only used when config.php is first generated):
#   FRAMPP_HOME           FRAMPP install dir (auto-detected by default)
#   GATEKEEPER_PORT       listen port, default 8090 (or use --port)
#   GATEKEEPER_UPSTREAM   fallback upstream, default http://127.0.0.1:9000
#                         (scheme stripped automatically; Caddy dynamic
#                         upstreams must be host:port)
#   GATEKEEPER_ADMIN_TOKENS / GATEKEEPER_PROCESSOR_SECRET / GATEKEEPER_JWT_SECRET
#                         used when config.php is first generated; random when
#                         not provided

set -euo pipefail

PORT="${GATEKEEPER_PORT:-8090}"
WEB_DIR=""
UPSTREAM="${GATEKEEPER_UPSTREAM:-http://127.0.0.1:9000}"
UPSTREAM="${UPSTREAM#http://}"
UPSTREAM="${UPSTREAM#https://}"
UPSTREAM="${UPSTREAM%/}"

die() { echo "!! $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --dir) WEB_DIR="${2:-}"; shift 2 ;;
    --port) PORT="${2:-8090}"; shift 2 ;;
    *) die "Unknown option: $1 (supported: --dir <code-dir> --port <port>)" ;;
  esac
done

echo "== ApiGateKeeper -> FRAMPP mount =="

# ---------- locate FRAMPP ----------
detect_frampp_home() {
  if [ -n "${FRAMPP_HOME:-}" ] && [ -x "$FRAMPP_HOME/bin/frampp" ]; then
    echo "$FRAMPP_HOME"; return
  fi
  local p
  p="$(command -v frampp 2>/dev/null || true)"
  if [ -n "$p" ]; then
    p="$(readlink -f "$p")"
    echo "$(cd "$(dirname "$p")/.." && pwd)"; return
  fi
  for d in "$HOME/frampp" /root/frampp /opt/frampp /usr/local/frampp; do
    [ -x "$d/bin/frampp" ] && { echo "$d"; return; }
  done
  echo ""
}

FRAMPP_HOME="${FRAMPP_HOME:-$(detect_frampp_home)}"
[ -n "$FRAMPP_HOME" ] || die "FRAMPP not found (set FRAMPP_HOME or add bin/frampp to PATH)"
FRAMPP_HOME="$(readlink -f "$FRAMPP_HOME")"
FRANKENPHP="$FRAMPP_HOME/modules/frankenphp/frankenphp"
PHP_BIN="$FRAMPP_HOME/bin/php"
[ -x "$FRANKENPHP" ] || die "FRAMPP is missing $FRANKENPHP"
echo ">> FRAMPP_HOME: $FRAMPP_HOME"
"$FRAMPP_HOME/bin/frampp" version 2>/dev/null | head -1 || true

# ---------- locate the code dir (no copy, reference only) ----------
if [ -z "$WEB_DIR" ]; then
  if [ -d "$FRAMPP_HOME/apps/gatekeeper/processor" ]; then
    WEB_DIR="$FRAMPP_HOME/apps/gatekeeper"   # backward compatible with old layout
  elif [ -f "$PWD/configs/api-gatekeeper.caddyfile" ] && [ -d "$PWD/processor" ]; then
    WEB_DIR="$PWD"
  else
    die "No code dir given: use --dir /path/to/gatekeeper (with processor/ admin-web/ configs/)"
  fi
fi
WEB_DIR="$(readlink -f "$WEB_DIR")"
[ -d "$WEB_DIR/processor" ] || die "Code dir is missing processor/: $WEB_DIR"
[ -d "$WEB_DIR/admin-web/dist" ] || echo "   [!!] admin-web/dist missing; admin UI unavailable"
[ -f "$WEB_DIR/configs/api-gatekeeper.caddyfile" ] \
  || die "Code dir is missing configs/api-gatekeeper.caddyfile: $WEB_DIR"
echo ">> WEB_DIR: $WEB_DIR (code is not copied; the fragment points here)"

# ---------- module & PHP extension preflight ----------
MODULES="$("$FRANKENPHP" list-modules 2>/dev/null || true)"
echo "$MODULES" | grep -q "http.handlers.access_filter" \
  || die "FRAMPP does not include the caddy-access-filter module"
echo "   [OK] caddy-access-filter"
echo "$MODULES" | grep -q "http.handlers.cache" \
  && echo "   [OK] HTTP cache module" || echo "   [!!] HTTP cache module not found (cache directive unavailable)"
PHP_MODS="$("$PHP_BIN" -r 'echo implode("\n", get_loaded_extensions());' 2>/dev/null || true)"
[ -n "$PHP_MODS" ] || PHP_MODS="$("$FRANKENPHP" php-cli -r 'echo implode("\n", get_loaded_extensions());' 2>/dev/null || true)"
for ext in pdo_sqlite apcu openssl simplexml; do
  echo "$PHP_MODS" | grep -qi "^$ext" || die "FRAMPP PHP is missing extension $ext"
done
echo "   [OK] PHP extensions"

# ---------- first install: generate static processor/config.php ----------
mkdir -p "$WEB_DIR/data/logs"
CONFIG="$WEB_DIR/processor/config.php"
CONFIG_TMPL="$WEB_DIR/configs/config.example.php"
[ -f "$CONFIG_TMPL" ] || die "Config template missing: $CONFIG_TMPL"

GEN_SCRIPT="$(mktemp)"; READ_SCRIPT="$(mktemp)"
trap 'rm -f "$GEN_SCRIPT" "$READ_SCRIPT"' EXIT

if [ "${GATEKEEPER_KEEP_ENV_CONFIG:-0}" = "1" ]; then
  echo ">> Keeping env-driven config (GATEKEEPER_KEEP_ENV_CONFIG=1); make sure the worker has the env vars"
elif [ ! -f "$CONFIG" ] || grep -q 'getenv(' "$CONFIG" 2>/dev/null; then
  # Missing, or still the env-driven template shipped in the release: evaluate
  # it into a static config once (paths point to WEB_DIR). A baked (no getenv)
  # or hand-edited config.php is never overwritten.
  ADMIN_TOKENS="${GATEKEEPER_ADMIN_TOKENS:-$(openssl rand -hex 16 2>/dev/null || echo admin-token-1)}"
  PROCESSOR_SECRET="${GATEKEEPER_PROCESSOR_SECRET:-$(openssl rand -hex 16 2>/dev/null || echo change-me)}"
  JWT_SECRET="${GATEKEEPER_JWT_SECRET:-$(openssl rand -hex 16 2>/dev/null || echo change-me)}"
  cat > "$GEN_SCRIPT" <<'PHP'
<?php
$cfg = require getenv('GK_TMPL');
file_put_contents(getenv('GK_OUT'), "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
PHP
  APIGATE_DB_PATH="$WEB_DIR/data/apigate.sqlite" \
  APIGATE_LOG_DIR="$WEB_DIR/data/logs" \
  APIGATE_USE_APCU=1 \
  APIGATE_ADMIN_TOKENS="$ADMIN_TOKENS" \
  APIGATE_PROCESSOR_SECRET="$PROCESSOR_SECRET" \
  APIGATE_JWT_SECRET="$JWT_SECRET" \
  GK_TMPL="$CONFIG_TMPL" GK_OUT="$CONFIG" \
  "$PHP_BIN" "$GEN_SCRIPT"
  echo ">> Generated static config: $CONFIG (secrets generated once, never rotated)"
else
  echo ">> Reusing existing config: $CONFIG (not overwritten)"
fi

# read the processor secret from config.php (keeps fragment and worker in sync)
cat > "$READ_SCRIPT" <<'PHP'
<?php
$cfg = require getenv('GK_CONFIG');
echo $cfg['processor']['secret'] ?? '', "\n";
PHP
read_secret() { GK_CONFIG="$CONFIG" "$PHP_BIN" "$READ_SCRIPT" | sed -n '1p'; }
PROCESSOR_SECRET="$(read_secret)"
echo ">> processor secret: ${PROCESSOR_SECRET:0:6}…"

# ---------- render the standalone fragment (all paths point to WEB_DIR) ----------
CADDY_DIR="$FRAMPP_HOME/etc/caddy.d"
CADDY_FRAG="$CADDY_DIR/api-gatekeeper.caddy"
mkdir -p "$CADDY_DIR"
sed -e "s|{{PORT}}|$PORT|g" \
    -e "s|{{PROCESSOR_SECRET}}|$PROCESSOR_SECRET|g" \
    -e "s|{{UPSTREAM}}|$UPSTREAM|g" \
    -e "s|{{PHP_ROOT}}|$WEB_DIR|g" \
    -e "s|{{WORKER_PHP}}|$WEB_DIR/processor/worker.php|g" \
    -e "s|{{ADMIN_DIST}}|$WEB_DIR/admin-web/dist|g" \
    -e "s|{{LOOPBACK_BASE}}|http://127.0.0.1:$PORT|g" \
    "$WEB_DIR/configs/api-gatekeeper.caddyfile" > "$CADDY_FRAG"
echo ">> Rendered fragment: $CADDY_FRAG"

# ---------- attach to the FRAMPP Caddyfile (idempotent) ----------
FRAMPP_CADDY="$FRAMPP_HOME/etc/Caddyfile"
if [ -f "$FRAMPP_CADDY" ]; then
  if grep -q "caddy.d" "$FRAMPP_CADDY"; then
    echo ">> $FRAMPP_CADDY already imports caddy.d"
  elif ! grep -q "api-gatekeeper.caddy" "$FRAMPP_CADDY"; then
    echo "" >> "$FRAMPP_CADDY"
    echo "import $CADDY_FRAG" >> "$FRAMPP_CADDY"
    echo ">> import appended"
  fi
else
  die "FRAMPP is not initialized (missing $FRAMPP_CADDY); run bin/frampp init first"
fi

# FRAMPP known issue: duplicate access-filter.caddy imports break startup
IMPORT_COUNT="$(grep -c 'access-filter.caddy' "$FRAMPP_CADDY" || true)"
if [ "${IMPORT_COUNT:-0}" -gt 1 ]; then
  cp "$FRAMPP_CADDY" "$FRAMPP_CADDY.frampp-bak" 2>/dev/null || true
  awk '/access-filter\.caddy/ { n++; if (n > 1) { print "# " $0; next } } { print }' \
    "$FRAMPP_CADDY" > "$FRAMPP_CADDY.tmp"
  mv "$FRAMPP_CADDY.tmp" "$FRAMPP_CADDY"
  echo ">> Commented out duplicate access-filter imports"
fi

# ---------- restart FRAMPP ----------
if systemctl list-unit-files 2>/dev/null | grep -q '^frampp'; then
  systemctl restart frampp
else
  (cd "$FRAMPP_HOME" && bin/frampp stop all >/dev/null 2>&1 || true)
  (cd "$FRAMPP_HOME" && bin/frampp start all >/dev/null 2>&1 || true)
fi

# ---------- importer cron (idempotent: remove old gatekeeper import lines) ----------
CRON_LINE="* * * * * cd $WEB_DIR && $PHP_BIN processor/import.php >> $WEB_DIR/data/logs/import.log 2>&1"
EXISTING_CRON="$(crontab -l 2>/dev/null | grep -v "processor/import.php" || true)"
printf '%s\n%s\n' "$EXISTING_CRON" "$CRON_LINE" | crontab -
echo ">> importer cron: $CRON_LINE"

# ---------- wait until ready (health check is public, no token needed) ----------
for _ in $(seq 1 30); do
  curl -fsS "http://127.0.0.1:$PORT/admin/api/health" >/dev/null 2>&1 && break
  sleep 1
done

echo
echo "== Mount complete =="
echo "Code dir:      $WEB_DIR (not copied; update with rsync --exclude processor/config.php)"
echo "First visit:   http://<server>:$PORT/admin/ (opens the install wizard to create an admin account)"
echo "Fallback:      $UPSTREAM"
echo "Fragment:      $CADDY_FRAG"
echo "Service:       systemctl restart frampp (or $FRAMPP_HOME/bin/frampp start|stop)"
echo
echo "Daily update: rsync the code, then systemctl restart frampp;"
echo "              re-run this script only when configs/api-gatekeeper.caddyfile changed."
