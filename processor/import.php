<?php

/**
 * 后台导入器 CLI 入口。
 * 用法：php processor/import.php [--file=path/to/log]
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Apigate\Importer;

$file = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}

echo json_encode(Importer::run($file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
