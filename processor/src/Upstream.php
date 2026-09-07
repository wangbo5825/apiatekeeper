<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 上游运行时：健康状态（主动探测 + 被动失败计数）、轮询 / 主备选择、
 * 缺省前缀映射（strip / replace / add）。
 * 配置存 SQLite（管理用途），健康与选择状态仅存 APCu（热路径不触库）。
 */
final class Upstream
{
    private const GROUPS_KEY = 'gk:up_groups:%d';
    private const TARGETS_KEY = 'gk:up_targets:%d';
    private const HEALTH_KEY = 'gk:up_health:%d';
    private const RR_KEY = 'gk:up_rr:%d';
    private const TTL = 30;

    /** @return array<int,array<string,mixed>> */
    public static function groups(int $appId): array
    {
        $key = sprintf(self::GROUPS_KEY, $appId);
        $groups = Cache::get($key);
        if (!is_array($groups)) {
            $stmt = Db::pdo()->prepare('SELECT * FROM upstream_groups WHERE app_id = ? ORDER BY id ASC');
            $stmt->execute([$appId]);
            $groups = $stmt->fetchAll();
            Cache::set($key, $groups, self::TTL);
        }
        return $groups;
    }

    /** @return array<int,array<string,mixed>> */
    public static function targets(int $groupId): array
    {
        $key = sprintf(self::TARGETS_KEY, $groupId);
        $targets = Cache::get($key);
        if (!is_array($targets)) {
            $stmt = Db::pdo()->prepare('SELECT * FROM upstream_targets WHERE group_id = ? AND enabled = 1 ORDER BY id ASC');
            $stmt->execute([$groupId]);
            $targets = $stmt->fetchAll();
            Cache::set($key, $targets, self::TTL);
        }
        return $targets;
    }

    public static function invalidate(int $appId): void
    {
        Cache::delete(sprintf(self::GROUPS_KEY, $appId));
        foreach (self::groups($appId) as $g) {
            Cache::delete(sprintf(self::TARGETS_KEY, (int) $g['id']));
        }
        Cache::delete(sprintf(self::HEALTH_KEY, $appId));
    }

    /**
     * 解析请求目标：backendName 为空时取应用第一个上游组。
     *
     * @return array{group:array,target:array,address:string,healthy:bool}|null
     */
    public static function resolve(int $appId, string $backendName = ''): ?array
    {
        $groups = self::groups($appId);
        if ($groups === []) {
            return null;
        }
        $group = null;
        if ($backendName !== '') {
            foreach ($groups as $g) {
                if ((string) $g['name'] === $backendName) {
                    $group = $g;
                    break;
                }
            }
        }
        $group ??= $groups[0];

        $targets = self::targets((int) $group['id']);
        if ($targets === []) {
            return null;
        }
        $health = self::healthMap($appId);
        $gid = (int) $group['id'];

        $isHealthy = static fn (array $t): bool => ($health[$gid][(int) $t['id']]['ok'] ?? true) === true;
        $strategy = (string) ($group['strategy'] ?? 'round_robin');
        $target = null;

        if ($strategy === 'active_standby') {
            foreach ($targets as $t) {
                if ($t['role'] === 'active' && $isHealthy($t)) {
                    $target = $t;
                    break;
                }
            }
            if ($target === null) {
                foreach ($targets as $t) {
                    if ($t['role'] === 'standby' && $isHealthy($t)) {
                        $target = $t;
                        break;
                    }
                }
            }
        } else {
            $pool = array_values(array_filter($targets, $isHealthy));
            if ($pool === []) {
                $pool = $targets; // 全部不健康时兜底直连（fail-open）
            }
            $idx = (int) (Cache::incr(sprintf(self::RR_KEY, $gid), 1) % count($pool));
            $target = $pool[$idx];
        }

        // 主备策略下无任何健康目标时兜底第一个启用目标
        $target ??= $targets[0];
        return [
            'group' => $group,
            'target' => $target,
            'address' => self::normalizeAddress((string) $target['address']),
            'healthy' => $isHealthy($target),
        ];
    }

    /** 缺省前缀映射：ruleRewrite（change_target 的 rewrite_path）优先，其次 ruleStrip，最后组缺省映射。 */
    public static function mapPath(array $group, string $basePath, string $rawPath, ?string $ruleRewrite = null, bool $ruleStrip = false): string
    {
        if ($ruleRewrite !== null && $ruleRewrite !== '') {
            return self::applyRewrite($ruleRewrite, $basePath, $rawPath);
        }
        if ($ruleStrip) {
            return App::relativePath($basePath, $rawPath);
        }

        $mode = (string) ($group['path_mode'] ?? 'strip');
        $from = (string) ($group['prefix_from'] ?? '');
        $to = (string) ($group['prefix_to'] ?? '');
        return match ($mode) {
            'strip' => App::relativePath($basePath, $rawPath),
            'replace' => ($from !== '' && str_starts_with($rawPath, $from))
                ? $to . substr($rawPath, strlen($from))
                : $rawPath,
            'add' => ($to !== '' ? $to : '') . $rawPath,
            default => $rawPath,
        };
    }

    /** change_target 的 rewrite_path 语法：strip:/v1 | replace:/old:/new | add:/prefix */
    public static function applyRewrite(string $rewrite, string $basePath, string $rawPath): string
    {
        if (str_starts_with($rewrite, 'strip:')) {
            $prefix = substr($rewrite, 6);
            if ($prefix === 'base') {
                return App::relativePath($basePath, $rawPath);
            }
            return $prefix !== '' && str_starts_with($rawPath, $prefix) ? substr($rawPath, strlen($prefix)) : $rawPath;
        }
        if (str_starts_with($rewrite, 'replace:')) {
            [$from, $to] = array_pad(explode(':', substr($rewrite, 8), 2), 2, '');
            if ($from !== '' && str_starts_with($rawPath, $from)) {
                return $to . substr($rawPath, strlen($from));
            }
            return $rawPath;
        }
        if (str_starts_with($rewrite, 'add:')) {
            return substr($rewrite, 4) . $rawPath;
        }
        return $rawPath;
    }

    /** 被动健康标记（filter 阶段调用）：5xx 失败计数，成功恢复。 */
    public static function markResult(int $appId, int $groupId, int $targetId, bool $ok, int $failThreshold = 3, int $recoverThreshold = 2): void
    {
        $health = self::healthMap($appId);
        $h = $health[$groupId][$targetId] ?? ['ok' => true, 'fails' => 0, 'streak' => 0, 'checked' => 0];
        if ($ok) {
            $h['fails'] = 0;
            $h['streak'] = (int) $h['streak'] + 1;
            $h['ok'] = $h['streak'] >= max(1, $recoverThreshold);
        } else {
            $h['fails'] = (int) $h['fails'] + 1;
            $h['streak'] = 0;
            $h['ok'] = $h['fails'] < max(1, $failThreshold);
        }
        $h['checked'] = time();
        $health[$groupId][$targetId] = $h;
        Cache::set(sprintf(self::HEALTH_KEY, $appId), $health, self::TTL);
    }

    /** 主动探测：对应用所有启用目标发 GET check_path。 */
    public static function probeAll(int $appId): int
    {
        $probed = 0;
        foreach (self::groups($appId) as $group) {
            $timeout = max(1, (int) ($group['check_timeout'] ?? 2));
            $checkPath = (string) ($group['check_path'] ?? '/healthz');
            foreach (self::targets((int) $group['id']) as $target) {
                $address = (string) $target['address'];
                $url = self::schemeOf($address) . '://' . self::normalizeAddress($address) . $checkPath;
                $ok = self::httpGet($url, $timeout);
                self::markResult(
                    $appId,
                    (int) $group['id'],
                    (int) $target['id'],
                    $ok,
                    (int) ($group['fail_threshold'] ?? 3),
                    (int) ($group['recover_threshold'] ?? 2)
                );
                $probed++;
            }
        }
        return $probed;
    }

    /** @return array<int,array<string,mixed>> 健康快照（供指标/管理端）。 */
    public static function health(int $appId): array
    {
        $out = [];
        foreach (self::groups($appId) as $group) {
            foreach (self::targets((int) $group['id']) as $target) {
                $out[] = [
                    'group' => (string) $group['name'],
                    'group_id' => (int) $group['id'],
                    'target_id' => (int) $target['id'],
                    'address' => (string) $target['address'],
                    'role' => (string) $target['role'],
                    'ok' => self::healthMap($appId)[(int) $group['id']][(int) $target['id']]['ok'] ?? true,
                ];
            }
        }
        return $out;
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private static function healthMap(int $appId): array
    {
        $h = Cache::get(sprintf(self::HEALTH_KEY, $appId));
        return is_array($h) ? $h : [];
    }

    private static function normalizeAddress(string $address): string
    {
        $address = preg_replace('#^https?://#i', '', $address) ?? $address;
        return rtrim($address, '/');
    }

    /** 从存储的地址推导探测协议；无前缀按 http。 */
    private static function schemeOf(string $address): string
    {
        return str_starts_with($address, 'https://') ? 'https' : 'http';
    }

    /** 是否允许对 https 探测跳过证书校验（自签测试用）。 */
    private static function tlsInsecure(): bool
    {
        return (string) Config::get('upstream.tls_insecure', '0') === '1';
    }

    private static function httpGet(string $url, int $timeout): bool
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_NOBODY => true,
            ];
            if (str_starts_with($url, 'https://') && self::tlsInsecure()) {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $err = curl_errno($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            return $err === 0 && $code >= 200 && $code < 500;
        }
        $ctxOpts = ['http' => ['timeout' => $timeout, 'method' => 'HEAD']];
        if (str_starts_with($url, 'https://') && self::tlsInsecure()) {
            $ctxOpts['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }
        $ctx = stream_context_create($ctxOpts);
        $headers = @get_headers($url, false, $ctx);
        if ($headers === false) {
            return false;
        }
        $status = (int) (explode(' ', (string) ($headers[0] ?? ''))[1] ?? 0);
        return $status >= 200 && $status < 500;
    }
}
