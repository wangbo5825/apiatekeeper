#!/usr/bin/env bash
#
# Build a FrankenPHP binary with caddy-access-filter and Souin (FRAMPP).
# See caddy-access-filter/examples/php-worker/build.sh.
#
# Prerequisites: Go, xcaddy, PHP built with --enable-embed --enable-zts
# (php-config available).
# Usage:
#   FRAMPP_DIR=/path/to/caddy-access-filter ./build.sh

set -euo pipefail

FRAMPP_DIR="${FRAMPP_DIR:-$(realpath ../../caddy-access-filter/dev)}"
FRANKENPHP_VERSION="${FRANKENPHP_VERSION:-v1.12.7}"
OUTPUT="${OUTPUT:-./bin/frankenphp}"

mkdir -p "$(dirname "$OUTPUT")"

echo ">> building FrankenPHP + caddy-access-filter + souin"

if command -v php-config >/dev/null 2>&1; then
  export CGO_ENABLED=1
  export CGO_CFLAGS="$(php-config --includes)"
  export CGO_LDFLAGS="$(php-config --libs)"
fi

xcaddy build \
  --output "$OUTPUT" \
  --with "github.com/dunglas/frankenphp/caddy@${FRANKENPHP_VERSION}" \
  --with "github.com/wangbo5825/caddy-access-filter=${FRAMPP_DIR}" \
  --with github.com/darkweak/souin/plugins/caddy \
  --with github.com/darkweak/storages/otter/caddy

echo ">> done: $OUTPUT"
