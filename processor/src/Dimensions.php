<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 维度定义：语义维度（dim:*）解析链。
 * 解析链按声明顺序取第一个非空值，解决同一概念字段名不统一的问题。
 */
final class Dimensions
{
    private const CACHE_KEY = 'gk:dims:%d';
    private const TTL = 30;

    /** @return array<int,array<string,mixed>> 应用维度声明快照。 */
    public static function all(int $appId): array
    {
        $key = sprintf(self::CACHE_KEY, $appId);
        $dims = Cache::get($key);
        if (!is_array($dims)) {
            $stmt = Db::pdo()->prepare('SELECT * FROM dimensions WHERE app_id = ? ORDER BY id ASC');
            $stmt->execute([$appId]);
            $dims = $stmt->fetchAll();
            foreach ($dims as &$d) {
                $d['chain'] = json_decode((string) $d['chain'], true) ?: [];
            }
            unset($d);
            Cache::set($key, $dims, self::TTL);
        }
        return $dims;
    }

    public static function invalidate(int $appId): void
    {
        Cache::delete(sprintf(self::CACHE_KEY, $appId));
    }

    /** 解析语义维度：按链上引用顺序取第一个非空请求变量值。 */
    public static function resolve(string $name, Context $ctx): ?string
    {
        foreach (self::all($ctx->appId) as $dim) {
            if ((string) $dim['name'] !== 'dim:' . $name) {
                continue;
            }
            foreach ((array) $dim['chain'] as $ref) {
                $v = $ctx->getRequestVar((string) $ref);
                if ($v !== null && $v !== '') {
                    return $v;
                }
            }
            return null;
        }
        return null;
    }

    public static function create(int $appId, string $name, array $chain): int
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO dimensions (app_id, name, chain) VALUES (?, ?, ?)'
        )->execute([$appId, $name, json_encode(array_values($chain), JSON_UNESCAPED_SLASHES)]);
        $id = (int) $pdo->lastInsertId();
        self::invalidate($appId);
        return $id;
    }

    public static function delete(int $appId, string $name): void
    {
        Db::pdo()->prepare('DELETE FROM dimensions WHERE app_id = ? AND name = ?')->execute([$appId, $name]);
        self::invalidate($appId);
    }
}
