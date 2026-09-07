<?php

declare(strict_types=1);

namespace Apigate;

/**
 * caddy-access-filter 契约 v1 处理器（V3）：
 * access = 应用匹配 + 上下文 + 规则裁决；filter = 响应加工 + 缓存头 + 记录。
 */
final class Contract
{
    private const PENDING_TTL = 60;

    /**
     * @param array<string,mixed> $req
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function handle(array $req): array
    {
        $phase = (string) ($req['phase'] ?? '');
        return $phase === 'filter' ? self::filter($req) : self::access($req);
    }

    /**
     * @param array<string,mixed> $req
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private static function access(array $req): array
    {
        $id = (string) ($req['id'] ?? '');
        $method = strtoupper((string) ($req['method'] ?? 'GET'));
        $uri = (string) ($req['uri'] ?? '/');
        $headers = is_array($req['headers'] ?? null) ? $req['headers'] : [];
        $clientIp = (string) ($req['client_ip'] ?? '');

        $identity = Identity::resolve($clientIp, $headers);
        $app = App::find($uri, $headers);
        if ($app === null) {
            self::recordDenied($id, $method, $uri, $clientIp, $identity, 404, 0, null);
            return self::denyEnvelope(404, [], '{"error":"app not found"}');
        }

        $ctx = new Context($app, $method, $uri, $headers, $clientIp, $identity->jwtSubject, $identity->apiKey);
        $apiKey = $method . ' ' . $ctx->template;
        $apiId = RuleEngine::apiId($ctx->appId, $apiKey);

        // 清单放行：未登记 API 拒绝（缺省配置 default_allow=0）
        if ($apiId === 0 && !App::defaultAllow($app)) {
            self::recordDenied($id, $method, $uri, $clientIp, $identity, 404, $ctx->appId, $ctx);
            return self::denyEnvelope(404, [], '{"error":"api not registered"}');
        }
        $autoAdd = $apiId === 0 && App::autoAdd($app) ? 1 : 0;

        $matched = RuleEngine::match($ctx->appId, $apiKey, $identity, 'access', $apiId);
        $res = RuleEngine::evaluate($matched, $ctx, $identity);
        if ($res['deny'] !== null) {
            self::recordDenied($id, $method, $uri, $clientIp, $identity, $res['deny']['status'], $ctx->appId, $ctx, $autoAdd);
            Metrics::denied($ctx->appId, self::denyReason((int) $res['deny']['status']));
            return self::denyEnvelope(
                (int) $res['deny']['status'],
                (array) $res['deny']['headers'],
                (string) $res['deny']['body']
            );
        }

        // 上游解析 + 缺省前缀映射（change_target 优先，其次组缺省映射）
        $up = Upstream::resolve($ctx->appId, (string) ($res['route']['backend'] ?? ''));
        $pendingUp = [];
        if ($up !== null) {
            $res['route']['backend'] = $up['address'];
            $path = Upstream::mapPath(
                $up['group'],
                (string) $app['base_path'],
                $ctx->rawPath,
                isset($res['route']['rewrite_path']) ? (string) $res['route']['rewrite_path'] : null,
                !empty($res['route']['strip_prefix'])
            );
            if ($path !== $ctx->rawPath) {
                $res['rewrite']['path'] = $path;
            }
            $pendingUp = [
                'up_group_id' => (int) $up['group']['id'],
                'up_target_id' => (int) $up['target']['id'],
                'up_fail_threshold' => (int) ($up['group']['fail_threshold'] ?? 3),
                'up_recover_threshold' => (int) ($up['group']['recover_threshold'] ?? 2),
            ];
        }

        // 暂存上下文供 filter 关联
        Cache::set('req:' . $id, [
            'method' => $method,
            'raw_path' => $ctx->rawPath,
            'rel_path' => $ctx->relPath,
            'template' => $ctx->template,
            'query' => (string) parse_url($uri, PHP_URL_QUERY),
            'headers' => $headers,
            'app_id' => $ctx->appId,
            'app_base' => (string) $app['base_path'],
            'client_ip' => $clientIp,
            'client_key' => $identity->key(),
            'api_key' => $apiKey,
            'cache_policy' => (string) ($res['context']['cache_policy'] ?? ''),
            'cache_ttl' => (int) ($res['context']['cache_ttl'] ?? 0),
            'auto_add' => $autoAdd,
        ] + $pendingUp, self::PENDING_TTL);

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'decision' => 'allow',
                'rewrite' => $res['rewrite'],
                'route' => $res['route'],
                // JSON_FORCE_OBJECT：空数组输出 {} 而非 []。
                // caddy-access-filter 的 Go 端把 rewrite/route/headers 解码为
                // map/struct，遇到 [] 会解码失败并整体走 on_error 旁路。
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
        ];
    }

    /**
     * @param array<string,mixed> $req
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private static function filter(array $req): array
    {
        $id = (string) ($req['id'] ?? '');
        $status = (int) ($req['status'] ?? 200);
        $headers = is_array($req['headers'] ?? null) ? array_change_key_case($req['headers'], CASE_LOWER) : [];
        $bytes = (int) ($req['bytes'] ?? 0);
        $durationMs = (int) ($req['duration_ms'] ?? 0);
        $upstream = (string) ($req['upstream'] ?? '');
        $body = (string) ($req['body'] ?? '');

        $pending = Cache::get('req:' . $id);
        Cache::delete('req:' . $id);
        $ctx = is_array($pending) ? $pending : [];

        $appId = (int) ($ctx['app_id'] ?? 0);
        $appBase = (string) ($ctx['app_base'] ?? '/');
        $method = (string) ($ctx['method'] ?? '');
        $rawPath = (string) ($ctx['raw_path'] ?? '/');
        $query = (string) ($ctx['query'] ?? '');
        $headersIn = is_array($ctx['headers'] ?? null) ? $ctx['headers'] : [];
        $clientIp = (string) ($ctx['client_ip'] ?? '');
        $template = (string) ($ctx['template'] ?? '');
        $apiKey = (string) ($ctx['api_key'] ?? ($method . ' ' . $template));
        $autoAdd = (int) ($ctx['auto_add'] ?? 0);

        Metrics::request($appId, $status);
        Metrics::latency($appId, $durationMs);
        if ($autoAdd > 0) {
            Metrics::autoAdded($appId);
        }
        if (!empty($ctx['up_group_id']) && !empty($ctx['up_target_id'])) {
            Upstream::markResult(
                $appId,
                (int) $ctx['up_group_id'],
                (int) $ctx['up_target_id'],
                $status < 500,
                (int) ($ctx['up_fail_threshold'] ?? 3),
                (int) ($ctx['up_recover_threshold'] ?? 2)
            );
        }

        $outHeaders = [];
        $contentType = (string) ($headers['content-type'] ?? 'application/json');

        // 响应加工（filter 阶段规则，buffer 模式 body 可用）
        if ($body !== '' && $appId > 0) {
            $app = App::findById($appId);
            $uri = 'http://gatekeeper.invalid' . $rawPath . ($query !== '' ? '?' . $query : '');
            $fctx = new Context(
                $app ?? ['id' => $appId, 'base_path' => $appBase],
                $method,
                $uri,
                $headersIn,
                $clientIp
            );
            $apiId = RuleEngine::apiId($appId, $apiKey);
            $identity = new Identity($clientIp);
            foreach (RuleEngine::match($appId, $apiKey, $identity, 'filter', $apiId) as $rule) {
                [$body, $contentType] = ResponseTransformer::apply((array) $rule['params'], $body, $contentType, $fctx);
            }
            if ($contentType !== (string) ($headers['content-type'] ?? 'application/json')) {
                $outHeaders['Content-Type'] = $contentType;
            }
        }

        // 缓存策略：force / no-cache 通过响应头表达（Souin 按 RFC 执行）
        $policy = (string) ($ctx['cache_policy'] ?? '');
        if ($policy === 'force') {
            $outHeaders['Cache-Control'] = 'public, max-age=' . (int) ($ctx['cache_ttl'] ?? 60);
        } elseif ($policy === 'no-cache') {
            $outHeaders['Cache-Control'] = 'no-store';
        }

        Recorder::record([
            'id' => $id,
            'app_id' => $appId,
            'method' => $method,
            'raw_path' => $rawPath,
            'api_template' => $template,
            'client_ip' => $clientIp,
            'client_key' => (string) ($ctx['client_key'] ?? ''),
            'status' => $status,
            'bytes' => $bytes,
            'duration_ms' => $durationMs,
            'upstream' => $upstream,
            'cache_policy' => $policy,
            'denied' => 0,
            'auto_add' => $autoAdd,
        ]);

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'headers' => $outHeaders,
                'body' => $body,
                'recorded' => true,
                // JSON_FORCE_OBJECT：headers 空数组输出 {}，否则 Go 端解码失败。
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
        ];
    }

    private static function recordDenied(
        string $id,
        string $method,
        string $uri,
        string $clientIp,
        Identity $identity,
        int $status,
        int $appId,
        ?Context $ctx,
        int $autoAdd = 0
    ): void {
        $template = $ctx?->template ?? '/';
        Recorder::record([
            'id' => $id,
            'app_id' => $appId,
            'method' => $method,
            'raw_path' => (string) parse_url($uri, PHP_URL_PATH),
            'api_template' => $template,
            'client_ip' => $clientIp,
            'client_key' => $identity->key(),
            'status' => $status,
            'bytes' => 0,
            'duration_ms' => 0,
            'upstream' => '',
            'cache_policy' => '',
            'denied' => 1,
            'auto_add' => $autoAdd,
        ]);
    }

    /** @param array<string,string> $headers */
    private static function denyEnvelope(int $status, array $headers, string $body): array
    {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'decision' => 'deny',
                'deny' => ['status' => $status, 'headers' => $headers, 'body' => $body],
                // JSON_FORCE_OBJECT：deny.headers 空数组输出 {}，否则 Go 端
                // map[string]string 解码失败（decode processor response）。
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
        ];
    }

    private static function denyReason(int $status): string
    {
        return match ($status) {
            401 => 'auth',
            429 => 'rate_limit',
            403 => 'forbidden',
            404 => 'not_found',
            default => 'denied',
        };
    }
}
