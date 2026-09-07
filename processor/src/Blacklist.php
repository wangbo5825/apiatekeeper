<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 黑名单：应用内维度黑名单。
 * 快照 APCu 缓存；自动拉黑 APCu 立即生效 + 事件文件异步持久化（不触热路径 DB）。
 */
final class Blacklist
{
    private const CACHE_KEY = 'gk:blacklist:%d';
    private const TTL = 30;
    public const EVENTS_FILE = 'blacklist-events.log';

    /** @return array<int,array<string,mixed>> */
    public static function snapshot(int $appId): array
    {
        $key = sprintf(self::CACHE_KEY, $appId);
        $list = Cache::get($key);
        if (!is_array($list)) {
            $stmt = Db::pdo()->prepare('SELECT dimension, value, expires_at FROM blacklist WHERE app_id = ?');
            $stmt->execute([$appId]);
            $list = $stmt->fetchAll();
            Cache::set($key, $list, self::TTL);
        }
        return $list;
    }

    public static function invalidate(int $appId): void
    {
        Cache::delete(sprintf(self::CACHE_KEY, $appId));
    }

    /** 命中判定：维度为变量引用（req.ip / req.userid / dim:user ...）。 */
    public static function hits(array $params, Context $ctx): bool
    {
        $dimensions = (array) ($params['dimensions'] ?? ['req.ip']);
        $list = self::snapshot($ctx->appId);
        $now = time();
        foreach ($dimensions as $d) {
            $d = (string) $d;
            $value = $ctx->resolve($d);
            if ($value === null) {
                continue;
            }
            foreach ($list as $entry) {
                if ((string) $entry['dimension'] !== $d || (string) $entry['value'] !== $value) {
                    continue;
                }
                $exp = $entry['expires_at'] ?? null;
                if ($exp !== null && $exp !== '') {
                    $expired = is_numeric($exp) ? (int) $exp < $now : strtotime((string) $exp) < $now;
                    if ($expired) {
                        continue;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /** 自动拉黑：APCu 快照立即生效 + 事件文件异步持久化。 */
    public static function addAuto(Context $ctx, int $duration): void
    {
        $dimension = 'req.ip';
        $value = $ctx->ip;
        if ($value === '') {
            return;
        }
        $expires = time() + max(1, $duration);
        $key = sprintf(self::CACHE_KEY, $ctx->appId);
        $list = Cache::get($key);
        if (!is_array($list)) {
            $list = self::snapshot($ctx->appId);
        }
        $list[] = ['dimension' => $dimension, 'value' => $value, 'expires_at' => $expires];
        Cache::set($key, $list, self::TTL);

        self::appendEvent([
            'app_id' => $ctx->appId,
            'dimension' => $dimension,
            'value' => $value,
            'reason' => 'auto rate-limit blacklist',
            'duration' => (int) $duration,
            'ts' => date('c'),
        ]);
    }

    /** 管理端直接加入（DB 写，非热路径）。 */
    public static function add(int $appId, string $dimension, string $value, string $reason = '', ?string $expiresAt = null, string $source = 'manual'): void
    {
        Db::pdo()->prepare(
            'INSERT INTO blacklist (app_id, dimension, value, reason, source, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(app_id, dimension, value) DO UPDATE SET
                reason = excluded.reason, source = excluded.source, expires_at = excluded.expires_at'
        )->execute([$appId, $dimension, $value, $reason, $source, $expiresAt]);
        self::invalidate($appId);
    }

    public static function remove(int $appId, int $id): void
    {
        Db::pdo()->prepare('DELETE FROM blacklist WHERE id = ? AND app_id = ?')->execute([$id, $appId]);
        self::invalidate($appId);
    }

    private static function appendEvent(array $event): void
    {
        $path = Recorder::logsDir() . '/' . self::EVENTS_FILE;
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
