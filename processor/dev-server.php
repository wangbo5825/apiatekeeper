<?php

/**
 * 本地开发用持久 HTTP 服务器（单进程循环）。
 *
 * 与 FrankenPHP worker 一样在单进程内循环处理请求，静态状态（限流计数、
 * 变量、请求上下文）跨请求保持，用于在无 FrankenPHP / 无 APCu 的环境
 * 验证完整业务逻辑。
 *
 * 用法：php processor/dev-server.php [127.0.0.1] [8081]
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Apigate\Dispatcher;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8081);

$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "socket error ($errno): $errstr\n");
    exit(1);
}
fwrite(STDERR, "apigate dev server listening on $host:$port\n");

function statusText(int $code): string
{
    return match ($code) {
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        default => 'OK',
    };
}

while (true) {
    $conn = @stream_socket_accept($server, 120);
    if ($conn === false) {
        continue;
    }
    try {
        // 读请求头
        $buf = '';
        while (!str_contains($buf, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            if (strlen($buf) > 1_000_000) {
                break;
            }
        }
        [$head, $body] = explode("\r\n\r\n", $buf, 2) + ['', ''];
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines) ?: 'GET / HTTP/1.1';
        $parts = explode(' ', $requestLine);
        $method = strtoupper($parts[0] ?? 'GET');
        $target = $parts[1] ?? '/';

        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        // 按 Content-Length 读完 body
        $len = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $len) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        $path = (string) parse_url($target, PHP_URL_PATH);
        $resp = Dispatcher::dispatch($method, $path, $headers, (string) $body);

        $respBody = (string) $resp['body'];
        $out = "HTTP/1.1 {$resp['status']} " . statusText($resp['status']) . "\r\n";
        $out .= "Content-Type: " . ($resp['headers']['Content-Type'] ?? 'application/json') . "\r\n";
        $out .= "Content-Length: " . strlen($respBody) . "\r\n";
        $out .= "Connection: close\r\n\r\n";
        fwrite($conn, $out . $respBody);
    } catch (\Throwable) {
        fwrite($conn, "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    } finally {
        fclose($conn);
    }
}
