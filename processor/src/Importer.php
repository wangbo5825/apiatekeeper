<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 后台导入器：把热路径 JSON 日志导入 SQLite、自动登记 API（auto_add）、
 * 处理黑名单事件、触发统计聚合。独立 PHP CLI 进程运行。
 *
 * 用法：php processor/import.php
 */
final class Importer
{
    /**
     * @return array{file:string, imported:int, blacklist_events:int}
     */
    public static function run(?string $logFile = null, int $batch = 500): array
    {
        $logFile = $logFile !== null ? $logFile : Recorder::logPath();
        $watermarkKey = 'imp_watermark_' . md5($logFile);
        $offset = max(0, (int) Cache::get($watermarkKey, 0));

        $imported = 0;
        if (is_file($logFile)) {
            $size = filesize($logFile);
            $offset = min($offset, $size);
            $fh = @fopen($logFile, 'rb');
            if ($fh !== false) {
                fseek($fh, $offset);
                $rows = [];
                while (($line = fgets($fh)) !== false) {
                    $offset = ftell($fh);
                    $row = json_decode($line, true);
                    if (!is_array($row)) {
                        continue;
                    }
                    $rows[] = $row;
                    if (count($rows) >= $batch) {
                        self::flushRows($rows);
                        $imported += count($rows);
                        $rows = [];
                    }
                }
                if ($rows !== []) {
                    self::flushRows($rows);
                    $imported += count($rows);
                }
                fclose($fh);
                Cache::set($watermarkKey, $offset, 0);
            }
        }

        $events = self::importBlacklistEvents();
        $probed = 0;
        foreach (App::all() as $app) {
            $probed += Upstream::probeAll((int) $app['id']);
        }
        // 导入后立即聚合（全局一次覆盖所有应用），API 列表请求数无需手动"运行聚合"
        $agg = Aggregator::run('hour', 0);
        // 数据保留清理（按 retention.raw_days；幂等）
        $retention = Retention::run();
        return [
            'file' => $logFile,
            'imported' => $imported,
            'blacklist_events' => $events,
            'upstream_probed' => $probed,
            'aggregated' => $agg,
            'retention' => $retention,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function flushRows(array $rows): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO request_logs
                 (id, app_id, ts, method, raw_path, api_template, client_ip, client_key,
                  status, bytes, duration_ms, upstream, cache_policy, denied, auto_add)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($rows as $row) {
                $stmt->execute([
                    (string) ($row['id'] ?? uniqid('', true)),
                    (int) ($row['app_id'] ?? 0),
                    (string) ($row['ts'] ?? date('Y-m-d H:i:s')),
                    (string) ($row['method'] ?? ''),
                    (string) ($row['raw_path'] ?? ''),
                    (string) ($row['api_template'] ?? ''),
                    (string) ($row['client_ip'] ?? ''),
                    (string) ($row['client_key'] ?? ''),
                    isset($row['status']) ? (int) $row['status'] : null,
                    (int) ($row['bytes'] ?? 0),
                    (int) ($row['duration_ms'] ?? 0),
                    (string) ($row['upstream'] ?? ''),
                    (string) ($row['cache_policy'] ?? ''),
                    (int) ($row['denied'] ?? 0),
                    (int) ($row['auto_add'] ?? 0),
                ]);
                if (!empty($row['auto_add']) && !empty($row['app_id'])
                    && !empty($row['api_template']) && !empty($row['method'])) {
                    self::registerApi((int) $row['app_id'], (string) $row['method'], (string) $row['api_template']);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** 自动登记 API（app 内相对模板）。 */
    public static function registerApi(int $appId, string $method, string $template): int
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO apis (app_id, method, template, auto)
             VALUES (?, ?, ?, 1)
             ON CONFLICT(app_id, method, template) DO UPDATE SET last_seen = datetime(\'now\')'
        )->execute([$appId, strtoupper($method), $template]);
        $stmt = $pdo->prepare('SELECT id FROM apis WHERE app_id = ? AND method = ? AND template = ?');
        $stmt->execute([$appId, strtoupper($method), $template]);
        $row = $stmt->fetch();
        if ($row !== false) {
            RuleEngine::invalidateApiRegistry($appId);
            return (int) $row['id'];
        }
        return 0;
    }

    /** 处理黑名单事件文件（读取后清空）。 */
    private static function importBlacklistEvents(): int
    {
        $path = Recorder::logsDir() . '/' . Blacklist::EVENTS_FILE;
        if (!is_file($path)) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines === []) {
            return 0;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO blacklist (app_id, dimension, value, reason, source, expires_at)
             VALUES (?, ?, ?, ?, \'auto\', datetime(\'now\', ?))
             ON CONFLICT(app_id, dimension, value) DO UPDATE SET
                reason = excluded.reason, expires_at = excluded.expires_at'
        );
        $count = 0;
        foreach ($lines as $line) {
            $ev = json_decode($line, true);
            if (!is_array($ev) || empty($ev['value'])) {
                continue;
            }
            $stmt->execute([
                (int) ($ev['app_id'] ?? 0),
                (string) ($ev['dimension'] ?? 'req.ip'),
                (string) $ev['value'],
                (string) ($ev['reason'] ?? ''),
                '+' . max(1, (int) ($ev['duration'] ?? 3600)) . ' seconds',
            ]);
            Blacklist::invalidate((int) ($ev['app_id'] ?? 0));
            $count++;
        }
        @file_put_contents($path, '');
        return $count;
    }
}
