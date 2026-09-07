<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 数据保留清理：按 retention.raw_days（默认 7 天）删除过期
 * request_logs / api_stats / client_stats，以及过期的 JSON 日志文件。
 * 由 Importer::run 每次执行（幂等，成本低）。
 */
final class Retention
{
    public static function run(): array
    {
        $days = max(1, (int) Config::get('retention.raw_days', 7));
        $cut = date('Y-m-d H:i:s', time() - $days * 86400);
        $day = date('Y-m-d', time() - $days * 86400);
        $pdo = Db::pdo();

        $logs = $pdo->prepare('DELETE FROM request_logs WHERE ts < ?')->execute([$cut]);
        $stats = $pdo->prepare('DELETE FROM api_stats WHERE bucket < ?')->execute([$day]);
        $clients = $pdo->prepare('DELETE FROM client_stats WHERE bucket < ?')->execute([$day]);

        $removedFiles = self::cleanLogFiles($days);
        return [
            'retention_days' => $days,
            'request_logs' => $logs,
            'api_stats' => $stats,
            'client_stats' => $clients,
            'log_files' => $removedFiles,
        ];
    }

    /** 删除超过保留期的历史 JSON 日志文件（data/logs/YYYY-MM-DD.log）。 */
    private static function cleanLogFiles(int $days): int
    {
        $dir = Recorder::logsDir();
        $removed = 0;
        foreach ((array) glob($dir . '/*.log') as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (basename($file) === 'import.log') {
                continue; // 导入器自身日志不参与轮转
            }
            if (filemtime($file) !== false && filemtime($file) < time() - $days * 86400) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }
}
