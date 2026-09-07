<?php

/**
 * ApiGateKeeper FrankenPHP worker 入口。
 *
 * 同一脚本服务两个入口：
 *   - /__proc/request  → caddy-access-filter 契约端点（access/filter）
 *   - /admin/api/*     → 管理端 REST API
 *
 * 用法：FRANKENPHP_CONFIG="worker ./processor/worker.php" 启动。
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Apigate\AdminApi;
use Apigate\Contract;
use Apigate\Recorder;

if (!function_exists('frankenphp_handle_request')) {
    fwrite(STDERR, "worker.php must run under FrankenPHP worker mode\n");
    exit(1);
}

$handler = static function (): void {
    try {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = (string) $v;
            }
        }

        if ($path === '/__proc/request') {
            if ($method !== 'POST') {
                http_response_code(405);
                echo 'method not allowed';
                return;
            }
            $secret = (string) \Apigate\Config::get('processor.secret', '');
            if ($secret !== '' && ($headers['x-processor-secret'] ?? '') !== $secret) {
                http_response_code(403);
                echo 'forbidden';
                return;
            }
            $req = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($req)) {
                http_response_code(400);
                echo 'invalid request';
                return;
            }
            if (($headers['x-processor-ver'] ?? '') !== (string) \Apigate\Config::get('processor.contract_version', '1')) {
                http_response_code(400);
                echo 'unsupported processor version';
                return;
            }
            $resp = Contract::handle($req);
        } elseif (str_starts_with($path, '/admin/api/')) {
            $raw = (string) file_get_contents('php://input');
            $body = $raw === '' ? [] : (json_decode($raw, true) ?: []);
            $resp = AdminApi::handle($method, $path, is_array($body) ? $body : [], $headers);
        } else {
            http_response_code(404);
            echo 'not found';
            return;
        }

        http_response_code($resp['status']);
        foreach ($resp['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $resp['body'];
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    } finally {
        Recorder::flush();
    }
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($i = 0; !$maxRequests || $i < $maxRequests; $i++) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}

Recorder::flush();
