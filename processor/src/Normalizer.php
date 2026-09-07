<?php

declare(strict_types=1);

namespace Apigate;

/**
 * API 路径归一化：把动态段（数字/UUID/日期/长哈希）归一为模板参数。
 */
final class Normalizer
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
    private const LONG_HEX_RE = '/^[0-9a-f]{16,}$/i';

    /** 归一化路径：/api/users/123 -> /api/users/{id} */
    public static function path(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $segs = explode('/', trim($path, '/'));
        $out = [];
        foreach ($segs as $seg) {
            if ($seg === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $seg) || preg_match(self::UUID_RE, $seg)
                || preg_match(self::DATE_RE, $seg) || preg_match(self::LONG_HEX_RE, $seg)) {
                $out[] = '{id}';
            } else {
                $out[] = $seg;
            }
        }
        return '/' . implode('/', $out);
    }

    /** 模板是否匹配某条已归一化路径：{param} 匹配一段，* 匹配任意多段。 */
    public static function templateMatches(string $template, string $path): bool
    {
        $template = trim($template, '/');
        if ($template === '' || $template === '*') {
            return true;
        }
        $parts = [];
        foreach (explode('/', $template) as $seg) {
            if ($seg === '*') {
                $parts[] = '.*';
            } elseif (str_starts_with($seg, '{') && str_ends_with($seg, '}')) {
                $parts[] = '[^/]+';
            } else {
                $parts[] = preg_quote($seg, '#');
            }
        }
        return (bool) preg_match('#^/' . implode('/', $parts) . '$#', $path);
    }

    /** 模板具体度：静态段越多越具体（用于规则排序）。 */
    public static function specificity(string $template): int
    {
        $score = 0;
        foreach (explode('/', trim($template, '/')) as $seg) {
            if (str_starts_with($seg, '{') && str_ends_with($seg, '}')) {
                $score += 1;
            } elseif ($seg === '*') {
                $score += 0;
            } else {
                $score += 10;
            }
        }
        return $score;
    }
}
