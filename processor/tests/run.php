<?php

declare(strict_types=1);

/**
 * 轻量测试 runner：发现 tests/ 下 *_test.php 中的 test_* 函数并执行。
 * 用法：php tests/run.php
 */

putenv('APIGATE_DB_PATH=' . sys_get_temp_dir() . '/apigate_test_' . getmypid() . '.sqlite');
putenv('APIGATE_USE_APCU=0');
putenv('APIGATE_ADMIN_TOKENS=test-token');
putenv('APIGATE_JWT_SECRET=test-secret');
putenv('APIGATE_JWT_ISSUER=apigate');
putenv('APIGATE_LOG_DIR=' . sys_get_temp_dir() . '/apigate_logs_test_' . getmypid());

@unlink(getenv('APIGATE_DB_PATH'));

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/helpers.php';

use Apigate\Cache;
use Apigate\Db;

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s expected=%s actual=%s',
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException($msg ?: 'assert_true failed');
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($msg ?: 'assert_contains failed') . " needle=[$needle]");
    }
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function make_hs256_token(array $payload, string $secret, int $ttl = 300): string
{
    $header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = $payload['iat'] ?? time();
    $payload['exp'] = time() + $ttl;
    $p = b64url(json_encode($payload));
    $sig = b64url(hash_hmac('sha256', "$header.$p", $secret, true));
    return "$header.$p.$sig";
}

$files = glob(__DIR__ . '/*_test.php') ?: [];
$pass = 0;
$fail = 0;

foreach ($files as $file) {
    $before = get_defined_functions()['user'];
    require $file;
    $after = get_defined_functions()['user'];
    $new = array_values(array_diff($after, $before));

    foreach ($new as $fn) {
        if (!str_starts_with($fn, 'test_')) {
            continue;
        }
        Cache::clearAll();
        Db::reset();
        @unlink(getenv('APIGATE_DB_PATH'));
        // Windows 下文件可能仍被占用，unlink 失败时逐表清空兜底
        $pdo = Db::pdo();
        foreach ([
            'meta', 'upstream_targets', 'upstream_groups', 'dimensions', 'variables',
            'rules', 'apis', 'request_logs', 'api_stats', 'client_stats', 'blacklist', 'tokens', 'apps',
        ] as $table) {
            try {
                $pdo->exec("DELETE FROM $table");
            } catch (Throwable) {
            }
        }
        $logDir = getenv('APIGATE_LOG_DIR');
        if (is_dir($logDir)) {
            foreach (glob($logDir . '/*.log') ?: [] as $f) {
                @unlink($f);
            }
        }
        try {
            $fn();
            $pass++;
            echo "PASS  $fn\n";
        } catch (Throwable $e) {
            $fail++;
            echo "FAIL  $fn :: {$e->getMessage()}\n";
        }
    }
}

@unlink(getenv('APIGATE_DB_PATH'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
