<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 配置访问器（默认值 + 环境变量已由 config.php 展开）。
 */
final class Config
{
    private static array $data = [];

    public static function init(array $data): void
    {
        self::$data = $data;
    }

    /** 按 "a.b.c" 点路径取值，缺省返回 $default。 */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
