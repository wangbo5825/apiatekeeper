<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 共享存储抽象：优先 APCu（单进程内跨 worker 线程），缺失时回退到
 * 进程内存（仅单 worker 有效，用于本地开发与测试）。
 */
final class Cache
{
    private const PREFIX = 'apigate:';
    private static ?bool $apcu = null;
    private static array $mem = [];

    private static function useApcu(): bool
    {
        if (self::$apcu === null) {
            self::$apcu = (bool) Config::get('cache.use_apcu', '0')
                && function_exists('apcu_enabled') && apcu_enabled();
        }
        return self::$apcu;
    }

    private static function key(string $k): string
    {
        return self::PREFIX . $k;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::useApcu()) {
            return apcu_fetch(self::key($key), $ok) ?: $default;
        }
        $k = self::key($key);
        if (!isset(self::$mem[$k])) {
            return $default;
        }
        [$val, $exp] = self::$mem[$k];
        if ($exp !== 0 && $exp < microtime(true)) {
            unset(self::$mem[$k]);
            return $default;
        }
        return $val;
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        if (self::useApcu()) {
            apcu_store(self::key($key), $value, $ttl);
            return;
        }
        self::$mem[self::key($key)] = [$value, $ttl > 0 ? microtime(true) + $ttl : 0];
    }

    /** 自增计数：不存在时初始化为 $initial；返回新值。 */
    public static function incr(string $key, int $delta = 1, int $ttl = 0, int $initial = 0): int
    {
        if (self::useApcu()) {
            $k = self::key($key);
            apcu_inc($k, $delta, $ok, $ttl);
            if (!$ok) {
                apcu_store($k, $initial + $delta, $ttl);
            }
            return (int) apcu_fetch($k);
        }
        $cur = (int) self::get($key, $initial);
        $val = $cur + $delta;
        self::set($key, $val, $ttl);
        return $val;
    }

    public static function delete(string $key): void
    {
        if (self::useApcu()) {
            apcu_delete(self::key($key));
            return;
        }
        unset(self::$mem[self::key($key)]);
    }

    public static function clearAll(): void
    {
        if (self::useApcu()) {
            apcu_clear_cache();
            return;
        }
        self::$mem = [];
    }
}
