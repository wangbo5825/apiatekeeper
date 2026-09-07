<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 认证：JWT 或不透明令牌（应用隔离）。
 * 令牌走 APCu 快照，热路径不查库。
 */
final class Auth
{
    private const TOKEN_KEY = 'gk:tokens:%d';
    private const TTL = 30;

    /**
     * @param array<string,mixed> $params
     */
    public static function check(array $params, Context $ctx, Identity $id): bool
    {
        $type = (string) ($params['type'] ?? 'jwt');
        if ($type === 'token') {
            return self::checkToken($params, $ctx);
        }
        return self::checkJwt($params, $ctx);
    }

    /** @return array<int,array<string,mixed>> */
    public static function tokenSnapshot(int $appId): array
    {
        $key = sprintf(self::TOKEN_KEY, $appId);
        $tokens = Cache::get($key);
        if (!is_array($tokens)) {
            $stmt = Db::pdo()->prepare('SELECT value, expires_at FROM tokens WHERE app_id = ?');
            $stmt->execute([$appId]);
            $tokens = $stmt->fetchAll();
            Cache::set($key, $tokens, self::TTL);
        }
        return $tokens;
    }

    public static function invalidateTokens(int $appId): void
    {
        Cache::delete(sprintf(self::TOKEN_KEY, $appId));
    }

    private static function checkJwt(array $params, Context $ctx): bool
    {
        $token = self::tokenFromHeaders($ctx);
        if ($token === '') {
            return false;
        }
        try {
            Jwt::decode(
                $token,
                (string) ($params['secret'] ?? Config::get('auth.jwt_secret', '')),
                (string) ($params['public_key'] ?? Config::get('auth.jwt_public_key', ''))
            );
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    private static function checkToken(array $params, Context $ctx): bool
    {
        $token = self::tokenFromHeaders($ctx);
        if ($token === '') {
            return false;
        }
        $now = time();
        foreach (self::tokenSnapshot($ctx->appId) as $t) {
            if ((string) $t['value'] !== $token) {
                continue;
            }
            $exp = $t['expires_at'] ?? null;
            if ($exp !== null && $exp !== '' && strtotime((string) $exp) < $now) {
                return false;
            }
            return true;
        }
        return false;
    }

    private static function tokenFromHeaders(Context $ctx): string
    {
        $auth = trim((string) ($ctx->headers['authorization'] ?? ''));
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
