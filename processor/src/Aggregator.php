<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 统计聚合：把 request_logs 增量汇总到 api_stats / client_stats。
 * 支持按应用聚合（appId > 0）或全局（appId = 0）。
 */
final class Aggregator
{
    public static function run(string $bucket = 'hour', int $appId = 0): array
    {
        $pdo = Db::pdo();
        $fmt = $bucket === 'day' ? '%Y-%m-%d' : '%Y-%m-%d %H:00';
        $since = self::since();
        // 注意：api_stats 聚合已改为 JOIN apis，app_id 必须限定 r.app_id
        $appFilter = $appId > 0 ? ' AND r.app_id = ' . (int) $appId : '';

        $pdo->prepare(
            "INSERT INTO api_stats (api_id, app_id, bucket, requests, errors, denied, total_ms, total_bytes)
             SELECT a.id, r.app_id, strftime('$fmt', r.ts) AS b, COUNT(*),
                    SUM(CASE WHEN r.status >= 400 THEN 1 ELSE 0 END),
                    SUM(r.denied), SUM(r.duration_ms), SUM(r.bytes)
             FROM request_logs r
             JOIN apis a ON a.app_id = r.app_id AND a.method = r.method AND a.template = r.api_template
             WHERE r.ts > ? $appFilter
             GROUP BY a.id, r.app_id, b
             ON CONFLICT(api_id, bucket) DO UPDATE SET
                app_id = excluded.app_id,
                requests = api_stats.requests + excluded.requests,
                errors   = api_stats.errors + excluded.errors,
                denied   = api_stats.denied + excluded.denied,
                total_ms = api_stats.total_ms + excluded.total_ms,
                total_bytes = api_stats.total_bytes + excluded.total_bytes"
        )->execute([$since]);

        $pdo->prepare(
            "INSERT INTO client_stats (client_key, app_id, bucket, requests, errors, denied, total_ms)
             SELECT client_key, app_id, strftime('$fmt', ts) AS b, COUNT(*),
                    SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END),
                    SUM(denied), SUM(duration_ms)
             FROM request_logs r
             WHERE r.client_key <> '' AND r.ts > ? $appFilter
             GROUP BY r.client_key, r.app_id, b
             ON CONFLICT(client_key, bucket) DO UPDATE SET
                app_id = excluded.app_id,
                requests = client_stats.requests + excluded.requests,
                errors   = client_stats.errors + excluded.errors,
                denied   = client_stats.denied + excluded.denied,
                total_ms = client_stats.total_ms + excluded.total_ms"
        )->execute([$since]);

        $max = $pdo->query('SELECT MAX(ts) AS m FROM request_logs')->fetch();
        $newSince = $max['m'] ?: $since;
        $pdo->prepare('INSERT INTO meta (key, value) VALUES (\'agg_since\', ?)
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$newSince]);

        return ['bucket' => $bucket, 'app_id' => $appId, 'since' => $since, 'processed_until' => $newSince];
    }

    private static function since(): string
    {
        $stmt = Db::pdo()->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute(['agg_since']);
        $row = $stmt->fetch();
        return $row ? (string) $row['value'] : '1970-01-01 00:00:00';
    }
}
