<?php

declare(strict_types=1);

namespace Apigate;

/**
 * Prometheus 指标：热路径 APCu 计数，/admin/api/metrics 以文本格式输出。
 * 计数在内存维护，不落 SQLite。
 */
final class Metrics
{
    private const BUCKETS = [10, 50, 100, 500, 1000];
    private const P = 'gk:m:';

    public static function request(int $appId, int $status): void
    {
        Cache::incr(self::P . 'req:' . $appId, 1, 86400 * 30);
        if ($status >= 400) {
            Cache::incr(self::P . 'err:' . $appId, 1, 86400 * 30);
        }
    }

    public static function denied(int $appId, string $reason): void
    {
        Cache::incr(self::P . 'den:' . $appId . ':' . $reason, 1, 86400 * 30);
    }

    public static function autoAdded(int $appId): void
    {
        Cache::incr(self::P . 'auto:' . $appId, 1, 86400 * 30);
    }

    public static function latency(int $appId, int $ms): void
    {
        foreach (self::BUCKETS as $b) {
            if ($ms <= $b) {
                Cache::incr(sprintf(self::P . 'h:%d:%d', $appId, $b), 1, 86400 * 30);
                break;
            }
        }
        Cache::incr(sprintf(self::P . 'h:%d:inf', $appId), 1, 86400 * 30);
    }

    /** 输出 Prometheus 文本格式（累积直方图 + 计数 + 上游健康 + 导入滞后）。 */
    public static function render(): string
    {
        $out = [];
        $out[] = '# HELP gatekeeper_http_requests_total 网关请求量';
        $out[] = '# TYPE gatekeeper_http_requests_total counter';
        $out[] = '# HELP gatekeeper_http_errors_total 4xx/5xx 错误数';
        $out[] = '# TYPE gatekeeper_http_errors_total counter';
        $out[] = '# HELP gatekeeper_denied_total 拦截数（403/429/404）';
        $out[] = '# TYPE gatekeeper_denied_total counter';
        $out[] = '# HELP gatekeeper_auto_added_apis_total 自动登记 API 数';
        $out[] = '# TYPE gatekeeper_auto_added_apis_total counter';
        $out[] = '# HELP gatekeeper_http_duration_seconds 延迟直方图（ms 桶）';
        $out[] = '# TYPE gatekeeper_http_duration_seconds histogram';
        $out[] = '# HELP gatekeeper_upstream_healthy 上游健康状态（0/1）';
        $out[] = '# TYPE gatekeeper_upstream_healthy gauge';
        $out[] = '# HELP gatekeeper_log_import_lag_seconds 日志导入滞后（秒）';
        $out[] = '# TYPE gatekeeper_log_import_lag_seconds gauge';

        foreach (App::all() as $app) {
            $appId = (int) $app['id'];
            $out[] = sprintf('gatekeeper_http_requests_total{app="%d"} %d', $appId, self::count('req:' . $appId));
            $out[] = sprintf('gatekeeper_http_errors_total{app="%d"} %d', $appId, self::count('err:' . $appId));
            $out[] = sprintf('gatekeeper_auto_added_apis_total{app="%d"} %d', $appId, self::count('auto:' . $appId));
            foreach (self::deniedByApp($appId) as $reason => $n) {
                $out[] = sprintf('gatekeeper_denied_total{app="%d",reason="%s"} %d', $appId, $reason, $n);
            }

            // 延迟直方图（累积）
            $hist = self::histogram($appId);
            $cum = 0;
            foreach (self::BUCKETS as $b) {
                $cum += $hist[$b] ?? 0;
                $out[] = sprintf(
                    'gatekeeper_http_duration_seconds_bucket{app="%d",le="%0.3f"} %d',
                    $appId,
                    $b / 1000.0,
                    $cum
                );
            }
            $total = $hist['inf'] ?? 0;
            $out[] = sprintf('gatekeeper_http_duration_seconds_bucket{app="%d",le="+Inf"} %d', $appId, $total);
            $out[] = sprintf('gatekeeper_http_duration_seconds_sum{app="%d"} %d', $appId, self::sumMs($appId));
            $out[] = sprintf('gatekeeper_http_duration_seconds_count{app="%d"} %d', $appId, $total);

            foreach (Upstream::health($appId) as $u) {
                $out[] = sprintf(
                    'gatekeeper_upstream_healthy{app="%d",group="%s",target="%s"} %d',
                    $appId,
                    $u['group'],
                    $u['address'],
                    $u['ok'] ? 1 : 0
                );
            }
        }

        $log = Recorder::logPath();
        $lag = is_file($log) ? max(0, time() - (int) filemtime($log)) : 0;
        $out[] = sprintf('gatekeeper_log_import_lag_seconds %d', $lag);

        return implode("\n", $out) . "\n";
    }

    private static function count(string $key): int
    {
        return (int) Cache::get(self::P . $key, 0);
    }

    /** @return array<string,int> */
    private static function deniedByApp(int $appId): array
    {
        // 已知 reason 集合（APCu 无前缀枚举，按固定集合读取）
        $reasons = ['auth', 'forbidden', 'rate_limit', 'not_found', 'unregistered'];
        $out = [];
        foreach ($reasons as $r) {
            $n = self::count('den:' . $appId . ':' . $r);
            if ($n > 0) {
                $out[$r] = $n;
            }
        }
        return $out;
    }

    /** @return array<int,int> */
    private static function histogram(int $appId): array
    {
        $hist = [];
        foreach (self::BUCKETS as $b) {
            $hist[$b] = self::count(sprintf('h:%d:%d', $appId, $b));
        }
        $hist['inf'] = self::count(sprintf('h:%d:inf', $appId));
        return $hist;
    }

    /** 近似总和：用桶上界 × 桶计数估算（精确 sum 需每请求累加，先近似）。 */
    private static function sumMs(int $appId): int
    {
        $hist = self::histogram($appId);
        $sum = 0;
        $prev = 0;
        foreach (self::BUCKETS as $b) {
            $sum += $b * ($hist[$b] ?? 0);
            $prev = $b;
        }
        $sum += $prev * 2 * ($hist['inf'] ?? 0);
        return $sum;
    }
}
