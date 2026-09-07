<?php

/**
 * ApiGateKeeper 处理器默认配置。
 *
 * 所有值均可被环境变量覆盖（见 docs/CONFIG.md）。
 * 返回数组，实际读取走 src/Config.php。
 */

return [
    'db' => [
        'path' => getenv('APIGATE_DB_PATH') ?: __DIR__ . '/../data/apigate.sqlite',
    ],
    'retention' => [
        'raw_days' => (int) (getenv('APIGATE_RAW_RETENTION_DAYS') ?: 7),
    ],
    'processor' => [
        'secret' => getenv('APIGATE_PROCESSOR_SECRET') ?: '',
        // 契约版本协商：仅接受此版本
        'contract_version' => '1',
    ],
    'admin' => [
        // 逗号分隔的 Bearer 令牌；空表示禁用管理端鉴权（仅开发环境）
        'tokens' => array_values(array_filter(array_map('trim', explode(',', getenv('APIGATE_ADMIN_TOKENS') ?: '')))),
    ],
    'auth' => [
        // HS256 校验密钥
        'jwt_secret' => getenv('APIGATE_JWT_SECRET') ?: '',
        // RS256 公钥（PEM）或 JWKS URL，二选一，可选
        'jwt_public_key' => getenv('APIGATE_JWT_PUBLIC_KEY') ?: '',
        'jwks_url' => getenv('APIGATE_JWKS_URL') ?: '',
        'default_issuer' => getenv('APIGATE_JWT_ISSUER') ?: '',
        'default_audience' => getenv('APIGATE_JWT_AUDIENCE') ?: '',
    ],
    'souin' => [
        'admin_url' => getenv('APIGATE_SOUIN_ADMIN_URL') ?: '',
    ],
    'cache' => [
        'use_apcu' => getenv('APIGATE_USE_APCU') ?: (function_exists('apcu_enabled') && apcu_enabled() ? '1' : '0'),
    ],
    'upstream' => [
        // https 主动探测是否跳过证书校验（自签测试用）
        'tls_insecure' => getenv('APIGATE_UPSTREAM_TLS_INSECURE') ?: '0',
    ],
    'record' => [
        // 记录缓冲条数阈值（达到后落库）
        'batch_size' => (int) (getenv('APIGATE_RECORD_BATCH') ?: 50),
        // 热路径 JSON 日志目录（按天轮转）
        'log_dir' => getenv('APIGATE_LOG_DIR') ?: __DIR__ . '/../data/logs',
    ],
];
