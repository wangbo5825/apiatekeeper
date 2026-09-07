<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 变量声明区域：命名变量（key 维度 / 取值来源 / TTL）。
 * 运行时值存储在 APCu，key = 当前请求经维度解析出的值（见 Context）。
 */
final class Variables
{
    private const CACHE_KEY = 'gk:vars:%d';
    private const TTL = 30;

    /** @return array<int,array<string,mixed>> 应用变量声明快照。 */
    public static function all(int $appId): array
    {
        $key = sprintf(self::CACHE_KEY, $appId);
        $vars = Cache::get($key);
        if (!is_array($vars)) {
            $stmt = Db::pdo()->prepare('SELECT * FROM variables WHERE app_id = ? ORDER BY id ASC');
            $stmt->execute([$appId]);
            $vars = $stmt->fetchAll();
            Cache::set($key, $vars, self::TTL);
        }
        return $vars;
    }

    public static function declaration(int $appId, string $name): ?array
    {
        foreach (self::all($appId) as $decl) {
            if ((string) $decl['name'] === $name) {
                return $decl;
            }
        }
        return null;
    }

    public static function invalidate(int $appId): void
    {
        Cache::delete(sprintf(self::CACHE_KEY, $appId));
    }

    public static function create(int $appId, string $name, string $keyDim, string $source, int $ttl): int
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO variables (app_id, name, key_dim, source, ttl)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(app_id, name) DO UPDATE SET
                key_dim = excluded.key_dim, source = excluded.source,
                ttl = excluded.ttl, updated_at = datetime(\'now\')'
        )->execute([$appId, $name, $keyDim, $source, max(1, $ttl)]);
        self::invalidate($appId);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $appId, string $name): void
    {
        Db::pdo()->prepare('DELETE FROM variables WHERE app_id = ? AND name = ?')->execute([$appId, $name]);
        self::invalidate($appId);
    }
}
