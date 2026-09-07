<?php

/**
 * 本地开发用 PHP 内置服务器路由。
 *
 * 与 worker.php 的分发逻辑完全一致（契约端点 + Admin API），但不依赖
 * FrankenPHP，可在任何 PHP CLI 环境运行：
 *
 *   php -S 127.0.0.1:8081 processor/dev-router.php
 *
 * 用途：在无 FrankenPHP 的机器上验证处理器全部业务逻辑。
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Apigate\AdminApi;
use Apigate\Config;
use Apigate\Contract;
use Apigate\Recorder;

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}

if ($path === '/__proc/request') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo 'method not allowed';
    } else {
        $req = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($req)) {
            http_response_code(400);
            echo 'invalid request';
        } elseif (($headers['x-processor-ver'] ?? '') !== (string) Config::get('processor.contract_version', '1')) {
            http_response_code(400);
            echo 'unsupported processor version';
        } else {
            $resp = Contract::handle($req);
            http_response_code($resp['status']);
            foreach ($resp['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
            echo $resp['body'];
        }
    }
} elseif (str_starts_with($path, '/admin/api/')) {
    $raw = (string) file_get_contents('php://input');
    $body = $raw === '' ? [] : (json_decode($raw, true) ?: []);
    $resp = AdminApi::handle($method, $path, is_array($body) ? $body : [], $headers);
    http_response_code($resp['status']);
    foreach ($resp['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $resp['body'];
} else {
    http_response_code(404);
    echo 'not found';
}

Recorder::flush();
