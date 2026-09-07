#!/usr/bin/env bash
#
# Build the server release package (no sources/dev files/tests/node_modules).
# Usage: ./deploy/package.sh
# Output: dist/gatekeeper-release/

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${OUT:-$ROOT/dist/gatekeeper-release}"

# only allow cleaning an output dir inside the project
case "$OUT" in
  "$ROOT"/*) ;;
  *) echo "!! OUT must be inside the project directory" >&2; exit 1 ;;
esac

rm -rf "$OUT"
mkdir -p "$OUT/processor" "$OUT/configs" "$OUT/admin-web" "$OUT/data/logs" "$OUT/deploy"

# processor (PHP sources + entry points + schema)
cp -r "$ROOT/processor/src" "$OUT/processor/src"
cp "$ROOT/processor/worker.php" "$OUT/processor/"
cp "$ROOT/processor/bootstrap.php" "$OUT/processor/"
cp "$ROOT/processor/config.php" "$OUT/processor/"
cp "$ROOT/processor/schema.sql" "$OUT/processor/"
cp "$ROOT/processor/import.php" "$OUT/processor/"
cp "$ROOT/processor/admin-passwd.php" "$OUT/processor/"

# configs
cp "$ROOT/configs/Caddyfile" "$OUT/configs/"
cp "$ROOT/configs/config.example.php" "$OUT/configs/"
cp "$ROOT/configs/api-gatekeeper.caddyfile" "$OUT/configs/"

# admin UI (must be built first; warn and continue if missing)
if [ -d "$ROOT/admin-web/dist" ] && [ -n "$(ls -A "$ROOT/admin-web/dist" 2>/dev/null)" ]; then
  cp -r "$ROOT/admin-web/dist" "$OUT/admin-web/dist"
else
  echo "!! admin-web/dist missing; build it first with: cd admin-web && npm ci && npm run build" >&2
fi

# deploy docs & scripts
cp "$ROOT/deploy/MANUAL-INSTALL.md" "$OUT/deploy/" 2>/dev/null || true
cp "$ROOT/deploy/install.sh" "$OUT/deploy/" 2>/dev/null || true
cp "$ROOT/deploy/install-frampp.sh" "$OUT/deploy/" 2>/dev/null || true
cp "$ROOT/deploy/test.sh" "$OUT/deploy/" 2>/dev/null || true
cp "$ROOT/deploy/README.md" "$OUT/deploy/" 2>/dev/null || true

touch "$OUT/data/.gitkeep"
echo ">> Release package built: $OUT"
echo ">> Copy it to the server and follow deploy/MANUAL-INSTALL.md"

# Web installer (disabled by default; dev-only, has security caveats).
# Enable explicitly with: WEB_INSTALL=1 ./deploy/package.sh
if [ "${WEB_INSTALL:-0}" = "1" ]; then
    WEB="$ROOT/dist/gatekeeper-webinstall"
    rm -rf "$WEB"
    mkdir -p "$WEB"
    cp "$ROOT/deploy/install.php" "$WEB/install.php"
    tar -czf "$WEB/gatekeeper-package.tar.gz" -C "$OUT" .
    echo ">> Web installer package built (dev only): $WEB"
else
    echo ">> Web installer not built (disabled by default; use WEB_INSTALL=1 for dev)"
fi
