<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 请求分发：worker.php（FrankenPHP）、dev-router.php（php -S）、
 * dev-server.php（持久循环）共用的入口逻辑。
 */
final class Dispatcher
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function dispatch(string $method, string $path, array $headers, string $rawBody): array
    {
        try {
            if ($path === '/__proc/request') {
                if ($method !== 'POST') {
                    return ['status' => 405, 'headers' => [], 'body' => 'method not allowed'];
                }
                $secret = (string) Config::get('processor.secret', '');
                if ($secret !== '' && ($headers['x-processor-secret'] ?? '') !== $secret) {
                    return ['status' => 403, 'headers' => [], 'body' => 'forbidden'];
                }
                if (($headers['x-processor-ver'] ?? '') !== (string) Config::get('processor.contract_version', '1')) {
                    return ['status' => 400, 'headers' => [], 'body' => 'unsupported processor version'];
                }
                $req = json_decode($rawBody, true);
                if (!is_array($req)) {
                    return ['status' => 400, 'headers' => [], 'body' => 'invalid request'];
                }
                return Contract::handle($req);
            }

            if (str_starts_with($path, '/admin/api/')) {
                $body = $rawBody === '' ? [] : (json_decode($rawBody, true) ?: []);
                return AdminApi::handle($method, $path, is_array($body) ? $body : [], $headers);
            }

            return ['status' => 404, 'headers' => [], 'body' => 'not found'];
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['error' => $e->getMessage()]),
            ];
        } finally {
            Recorder::flush();
        }
    }
}
