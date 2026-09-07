<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 限流：固定时间窗口 + 突发额度。
 * 维度（keys）为变量引用（req.ip / dim:user / var:... 等），
 * 由当前请求上下文解析；计数存 APCu，不依赖统计链路。
 */
final class RateLimit
{
    /**
     * @param array<string,mixed> $params
     * @return array{allowed:bool, retry_after:int, exceeded_once:bool}
     */
    public static function check(array $rule, Context $ctx, array $params): array
    {
        $window = max(1, (int) ($params['window'] ?? 60));
        $limit = max(1, (int) ($params['limit'] ?? 100));
        $burst = max(0, (int) ($params['burst'] ?? 0));
        $keys = (array) ($params['keys'] ?? ['req.ip']);

        $dim = self::dimensionKey($keys, $ctx);
        $slot = (int) floor(time() / $window);
        $ckey = sprintf('gk:rl:%d:%d:%d:%s', $ctx->appId, (int) $rule['id'], $slot, $dim);

        $count = Cache::incr($ckey, 1, $window * 2);
        $exceeded = $count > ($limit + $burst);
        return [
            'allowed' => !$exceeded,
            'retry_after' => $window - (time() % $window),
            'exceeded_once' => $exceeded && $count === $limit + $burst + 1,
        ];
    }

    /**
     * @param array<int,string> $keys
     */
    private static function dimensionKey(array $keys, Context $ctx): string
    {
        $parts = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            $v = $ctx->resolve($k) ?? '';
            $parts[] = $k . '=' . $v;
        }
        return implode('|', $parts) ?: 'ip:' . $ctx->ip;
    }
}
