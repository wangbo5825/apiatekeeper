<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 请求记录（V3）：热路径只追加写本地 JSON 日志文件，不触 SQLite。
 * SQLite 导入 / API 自动登记 / 统计聚合由后台 Importer 完成。
 */
final class Recorder
{
    public static function logsDir(): string
    {
        $dir = (string) Config::get('record.log_dir', APIGATE_ROOT . '/../data/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function logPath(string $date = ''): string
    {
        return self::logsDir() . '/' . ($date !== '' ? $date : date('Y-m-d')) . '.log';
    }

    /** 追加一条请求日志（JSON 行）。 */
    public static function record(array $row): void
    {
        $row['ts'] = $row['ts'] ?? date('c');
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);
    }

    /** 兼容旧调用：批量落库已由文件写入取代，保留空实现。 */
    public static function flush(): void
    {
    }
}
