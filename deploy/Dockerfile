# apigate 部署镜像（Linux）
# 构建阶段：用 xcaddy 把 FrankenPHP + caddy-access-filter + Souin 编进一个二进制

FROM dunglas/frankenphp:1.12.7-builder AS builder

ARG FRAMPP_DIR=/workspace/caddy-access-filter/dev
ARG FRANKENPHP_VERSION=v1.12.7

RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*

COPY caddy-access-filter /workspace/caddy-access-filter

ENV CGO_ENABLED=1 XCADDY_SETCAP=1
RUN xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with "github.com/dunglas/frankenphp/caddy@${FRANKENPHP_VERSION}" \
    --with "github.com/wangbo5825/caddy-access-filter=${FRAMPP_DIR}" \
    --with github.com/darkweak/souin/plugins/caddy \
    --with github.com/darkweak/storages/otter/caddy

# 运行时阶段
FROM dunglas/frankenphp:1.12.7

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

RUN install-php-extensions apcu pdo_sqlite

WORKDIR /app
COPY processor /app/processor
COPY configs /app/configs
COPY admin-web/dist /app/admin-web/dist

ENV APIGATE_DB_PATH=/app/data/apigate.sqlite
ENV APIGATE_UPSTREAM=upstream:9000

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/app/configs/Caddyfile"]
