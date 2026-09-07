-- API Gatekeeper SQLite 模式 v2（幂等：全部使用 IF NOT EXISTS）
-- 业务表均带 app_id（0 = 旧版全局数据）

CREATE TABLE IF NOT EXISTS apps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    base_path  TEXT    NOT NULL,
    match_mode TEXT    NOT NULL DEFAULT 'prefix',   -- prefix | host | header
    match_value TEXT   NOT NULL DEFAULT '',          -- host 名 或 "Header-Name=值"
    default_allow        INTEGER NOT NULL DEFAULT 1,
    default_cache_enabled INTEGER NOT NULL DEFAULT 0,
    default_cache_ttl    INTEGER NOT NULL DEFAULT 60,
    auto_add_rules       INTEGER NOT NULL DEFAULT 1,
    status     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_apps_base ON apps (base_path);

CREATE TABLE IF NOT EXISTS upstream_groups (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id      INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    strategy    TEXT    NOT NULL DEFAULT 'round_robin', -- round_robin | active_standby
    path_mode   TEXT    NOT NULL DEFAULT 'strip',        -- strip | replace | add | none
    prefix_from TEXT    NOT NULL DEFAULT '',
    prefix_to   TEXT    NOT NULL DEFAULT '',
    check_mode  TEXT    NOT NULL DEFAULT 'active',       -- active | passive | both
    check_interval INTEGER NOT NULL DEFAULT 10,
    check_timeout  INTEGER NOT NULL DEFAULT 2,
    check_path     TEXT    NOT NULL DEFAULT '/healthz',
    fail_threshold INTEGER NOT NULL DEFAULT 3,
    recover_threshold INTEGER NOT NULL DEFAULT 2,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_upstream_groups_app ON upstream_groups (app_id);

CREATE TABLE IF NOT EXISTS upstream_targets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id   INTEGER NOT NULL,
    address    TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'round_robin', -- active | standby | round_robin
    weight     INTEGER NOT NULL DEFAULT 100,
    enabled    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS dimensions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id     INTEGER NOT NULL,
    name       TEXT    NOT NULL,          -- dim:user
    chain      TEXT    NOT NULL DEFAULT '[]', -- JSON 数组，按序解析取首个非空
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (app_id, name)
);

CREATE TABLE IF NOT EXISTS variables (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id     INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    key_dim    TEXT    NOT NULL,          -- req.ip | dim:user | req.query:uid ...
    source     TEXT    NOT NULL DEFAULT '',
    ttl        INTEGER NOT NULL DEFAULT 300,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (app_id, name)
);

CREATE TABLE IF NOT EXISTS rules (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id        INTEGER NOT NULL DEFAULT 0,
    scope         TEXT    NOT NULL DEFAULT 'app',  -- app | api
    api_id        INTEGER,                          -- scope=api 时绑定的 API
    phase         TEXT    NOT NULL DEFAULT 'access',-- access | filter
    name          TEXT    NOT NULL,
    description   TEXT    NOT NULL DEFAULT '',
    enabled       INTEGER NOT NULL DEFAULT 1,
    priority      INTEGER NOT NULL DEFAULT 0,
    api_pattern   TEXT    NOT NULL,             -- 应用内相对路径，如 POST /orders 或 *
    client_pattern TEXT   NOT NULL DEFAULT '*', -- * / ip:cidr / key:xxx / user:xxx
    action_type   TEXT    NOT NULL,             -- stats|cache|rate_limit|auth|save_variable|
                                                -- change_target|blacklist|variable_check|response_transform
    params        TEXT    NOT NULL DEFAULT '{}',
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_rules_app ON rules (app_id, scope, phase);

CREATE TABLE IF NOT EXISTS apis (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id       INTEGER NOT NULL DEFAULT 0,
    method       TEXT    NOT NULL,
    template     TEXT    NOT NULL,             -- 应用内相对路径模板
    display_name TEXT    NOT NULL DEFAULT '',
    auto         INTEGER NOT NULL DEFAULT 0,   -- 1 = 自动加入
    first_seen   TEXT    NOT NULL DEFAULT (datetime('now')),
    last_seen    TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (app_id, method, template)
);
CREATE INDEX IF NOT EXISTS idx_apis_app ON apis (app_id);

CREATE TABLE IF NOT EXISTS request_logs (
    id           TEXT PRIMARY KEY,             -- 网关请求 id
    app_id       INTEGER NOT NULL DEFAULT 0,
    ts           TEXT    NOT NULL DEFAULT (datetime('now')),
    method       TEXT    NOT NULL,
    raw_path     TEXT    NOT NULL,
    api_template TEXT    NOT NULL DEFAULT '',
    api_id       INTEGER,
    client_ip    TEXT    NOT NULL DEFAULT '',
    client_key   TEXT    NOT NULL DEFAULT '',
    status       INTEGER,
    bytes        INTEGER NOT NULL DEFAULT 0,
    duration_ms  INTEGER NOT NULL DEFAULT 0,
    upstream     TEXT    NOT NULL DEFAULT '',
    cache_policy TEXT    NOT NULL DEFAULT '',
    denied       INTEGER NOT NULL DEFAULT 0,
    auto_add     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_request_logs_ts ON request_logs (ts);
CREATE INDEX IF NOT EXISTS idx_request_logs_app ON request_logs (app_id);

CREATE TABLE IF NOT EXISTS api_stats (
    api_id     INTEGER NOT NULL,
    app_id     INTEGER NOT NULL DEFAULT 0,
    bucket     TEXT    NOT NULL,               -- yyyy-mm-dd HH:00 或 yyyy-mm-dd
    requests   INTEGER NOT NULL DEFAULT 0,
    errors     INTEGER NOT NULL DEFAULT 0,
    denied     INTEGER NOT NULL DEFAULT 0,
    total_ms   INTEGER NOT NULL DEFAULT 0,
    total_bytes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (api_id, bucket)
);

CREATE TABLE IF NOT EXISTS client_stats (
    client_key TEXT    NOT NULL,
    app_id     INTEGER NOT NULL DEFAULT 0,
    bucket     TEXT    NOT NULL,
    requests   INTEGER NOT NULL DEFAULT 0,
    errors     INTEGER NOT NULL DEFAULT 0,
    denied     INTEGER NOT NULL DEFAULT 0,
    total_ms   INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (client_key, bucket)
);

CREATE TABLE IF NOT EXISTS blacklist (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id     INTEGER NOT NULL DEFAULT 0,
    dimension  TEXT    NOT NULL,               -- req.ip | req.userid | req.query:<name> ...
    value      TEXT    NOT NULL,
    reason     TEXT    NOT NULL DEFAULT '',
    source     TEXT    NOT NULL DEFAULT 'manual',  -- manual | auto
    expires_at TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (app_id, dimension, value)
);

CREATE TABLE IF NOT EXISTS tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id     INTEGER NOT NULL DEFAULT 0,
    value      TEXT    NOT NULL,
    owner      TEXT    NOT NULL DEFAULT '',
    expires_at TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (app_id, value)
);

-- 管理端账号（用户名 + 密码登录）
CREATE TABLE IF NOT EXISTS admin_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- 管理端会话（HttpOnly Cookie 只存随机值，库内存哈希）
CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_user ON admin_sessions (user_id);

CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
