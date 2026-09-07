<?php

declare(strict_types=1);

namespace Apigate;

use PDO;

/**
 * SQLite 访问（WAL 模式），首次使用时应用 schema.sql。
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = (string) Config::get('db.path', ':memory:');
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        self::$pdo = $pdo;
        self::migrate();
        return $pdo;
    }

    private static function migrate(): void
    {
        $schema = file_get_contents(APIGATE_ROOT . '/schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('schema.sql not found');
        }
        self::$pdo->exec($schema);

        // v2 迁移：旧库补充新列（列已存在时忽略错误）
        $alters = [
            'rules' => [
                'app_id INTEGER NOT NULL DEFAULT 0',
                'scope TEXT NOT NULL DEFAULT \'app\'',
                'api_id INTEGER',
                'phase TEXT NOT NULL DEFAULT \'access\'',
                'description TEXT NOT NULL DEFAULT \'\'',
            ],
            'apis' => [
                'app_id INTEGER NOT NULL DEFAULT 0',
                'auto INTEGER NOT NULL DEFAULT 0',
            ],
            'request_logs' => ['app_id INTEGER NOT NULL DEFAULT 0'],
            'api_stats' => ['app_id INTEGER NOT NULL DEFAULT 0'],
            'client_stats' => ['app_id INTEGER NOT NULL DEFAULT 0'],
            'blacklist' => [
                'app_id INTEGER NOT NULL DEFAULT 0',
                'source TEXT NOT NULL DEFAULT \'manual\'',
            ],
            'tokens' => ['app_id INTEGER NOT NULL DEFAULT 0'],
        ];
        foreach ($alters as $table => $cols) {
            foreach ($cols as $col) {
                try {
                    self::$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col}");
                } catch (\Throwable) {
                    // 列已存在，忽略
                }
            }
        }
    }

    /** 测试用：重置连接。 */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
