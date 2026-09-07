<?php

/**
 * ApiGateKeeper 处理器配置模板（用于首次安装生成 processor/config.php）。
 *
 * 全部值都可被环境变量覆盖；未提供时默认落在代码目录（__DIR__/../data）下，
 * 因此同一份模板既可用于 Docker（由环境变量指向 /app），
 * 也可用于 FRAMPP/裸机部署（默认 data 在代码目录旁）。
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
        'contract_version' => '1',
    ],
    'admin' => [
        'tokens' => array_values(array_filter(array_map('trim', explode(',', getenv('APIGATE_ADMIN_TOKENS') ?: '')))),
    ],
    'auth' => [
        'jwt_secret' => getenv('APIGATE_JWT_SECRET') ?: '',
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
        // https 主动探测是否跳过证书校验（自签测试用；生产建议保持 0）
        'tls_insecure' => getenv('APIGATE_UPSTREAM_TLS_INSECURE') ?: '0',
    ],
    'record' => [
        'batch_size' => (int) (getenv('APIGATE_RECORD_BATCH') ?: 50),
        'log_dir' => getenv('APIGATE_LOG_DIR') ?: __DIR__ . '/../data/logs',
    ],
];
