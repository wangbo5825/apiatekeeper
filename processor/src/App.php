<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 应用（Application）模型：匹配（base_path / host / header）、
 * 缺省配置、快照缓存。
 */
final class App
{
    private const CACHE_KEY = 'apps:v';
    private const TTL = 30;

    /** @return array<int,array<string,mixed>> 启用应用快照（APCu 缓存）。 */
    public static function all(): array
    {
        $apps = Cache::get(self::CACHE_KEY);
        if (!is_array($apps)) {
            $apps = Db::pdo()->query('SELECT * FROM apps WHERE status = 1 ORDER BY id ASC')->fetchAll();
            Cache::set(self::CACHE_KEY, $apps, self::TTL);
        }
        return $apps;
    }

    public static function invalidate(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    /** 按请求匹配应用；未命中返回 null。 */
    public static function find(string $uri, array $headers): ?array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $headers = array_change_key_case($headers, CASE_LOWER);
        foreach (self::all() as $app) {
            $base = rtrim((string) $app['base_path'], '/');
            if ($base !== '' && $base !== '/') {
                if ($path !== $base && !str_starts_with($path, $base . '/')) {
                    continue;
                }
            }
            $mode = (string) $app['match_mode'];
            $value = (string) $app['match_value'];
            if ($mode === 'host' && $value !== '') {
                $host = (string) ($headers['host'] ?? '');
                if (strcasecmp($host, $value) !== 0) {
                    continue;
                }
            }
            if ($mode === 'header' && $value !== '') {
                [$name, $expect] = array_pad(explode('=', $value, 2), 2, '');
                if ($expect !== '' && (string) ($headers[strtolower(trim($name))] ?? '') !== trim($expect)) {
                    continue;
                }
            }
            return $app;
        }
        return null;
    }

    public static function findById(int $id): ?array
    {
        foreach (self::all() as $app) {
            if ((int) $app['id'] === $id) {
                return $app;
            }
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM apps WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 从应用 base_path 中剥离出应用内相对路径（以 / 开头）。 */
    public static function relativePath(string $basePath, string $path): string
    {
        $base = rtrim($basePath, '/');
        if ($base === '' || $base === '/') {
            return $path;
        }
        if ($path === $base) {
            return '/';
        }
        return substr($path, strlen($base));
    }

    public static function defaultAllow(array $app): bool
    {
        return (int) $app['default_allow'] === 1;
    }

    public static function cacheEnabled(array $app): bool
    {
        return (int) $app['default_cache_enabled'] === 1;
    }

    public static function cacheTtl(array $app): int
    {
        return max(0, (int) $app['default_cache_ttl']);
    }

    public static function autoAdd(array $app): bool
    {
        return (int) $app['auto_add_rules'] === 1;
    }

    public static function create(array $data): int
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO apps (name, base_path, match_mode, match_value,
                               default_allow, default_cache_enabled, default_cache_ttl, auto_add_rules)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (string) ($data['name'] ?? ''),
            (string) ($data['base_path'] ?? '/'),
            (string) ($data['match_mode'] ?? 'prefix'),
            (string) ($data['match_value'] ?? ''),
            (int) ($data['default_allow'] ?? 1),
            (int) ($data['default_cache_enabled'] ?? 0),
            (int) ($data['default_cache_ttl'] ?? 60),
            (int) ($data['auto_add_rules'] ?? 1),
        ]);
        $id = (int) $pdo->lastInsertId();
        self::invalidate();
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'UPDATE apps SET name=?, base_path=?, match_mode=?, match_value=?,
                    default_allow=?, default_cache_enabled=?, default_cache_ttl=?, auto_add_rules=?, status=?,
                    updated_at=datetime(\'now\')
             WHERE id=?'
        )->execute([
            (string) ($data['name'] ?? ''),
            (string) ($data['base_path'] ?? '/'),
            (string) ($data['match_mode'] ?? 'prefix'),
            (string) ($data['match_value'] ?? ''),
            (int) ($data['default_allow'] ?? 1),
            (int) ($data['default_cache_enabled'] ?? 0),
            (int) ($data['default_cache_ttl'] ?? 60),
            (int) ($data['auto_add_rules'] ?? 1),
            (int) ($data['status'] ?? 1),
            $id,
        ]);
        self::invalidate();
    }

    public static function delete(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM apps WHERE id = ?')->execute([$id]);
        self::invalidate();
    }

    /** base_path 冲突检测：返回冲突应用（含自身排除）。 */
    public static function pathConflict(int $exceptId, string $basePath, string $matchMode = 'prefix', string $matchValue = ''): ?array
    {
        $base = rtrim($basePath, '/');
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM apps WHERE match_mode = ? AND match_value = ? AND id != ? ORDER BY id ASC'
        );
        $stmt->execute([$matchMode, $matchValue, $exceptId]);
        foreach ($stmt->fetchAll() as $app) {
            $other = rtrim((string) $app['base_path'], '/');
            if ($base === $other) {
                return $app;
            }
            if ($base !== '' && $other !== ''
                && (str_starts_with($base, $other . '/') || str_starts_with($other, $base . '/'))) {
                return $app;
            }
        }
        return null;
    }
}
