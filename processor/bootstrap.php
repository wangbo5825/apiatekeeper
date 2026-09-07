<?php

/**
 * ApiGateKeeper 处理器引导文件：自动加载 + 配置。
 * 零依赖（不依赖 composer），纯 spl_autoload_register。
 */

declare(strict_types=1);

const APIGATE_ROOT = __DIR__;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Apigate\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = APIGATE_ROOT . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Apigate\Config;

Config::init(require APIGATE_ROOT . '/config.php');
