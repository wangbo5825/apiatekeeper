<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 管理端 REST API（V3）。
 * 资源模型：/admin/api/apps/{id}/<resource>；旧全局端点保留（app_id=0）。
 */
final class AdminApi
{
    private const RULE_TYPES = [
        'stats', 'cache', 'rate_limit', 'auth', 'save_variable',
        'change_target', 'blacklist', 'variable_check', 'response_transform',
    ];

    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function handle(string $method, string $path, ?array $body, array $headers): array
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        // parts = [admin, api, <resource>, <id>, <sub>, <subId>]
        $resource = $parts[2] ?? '';
        $id = isset($parts[3]) ? (int) $parts[3] : null;
        $sub = $parts[4] ?? '';
        $subId = $parts[5] ?? null;
        $subSub = $parts[6] ?? '';
        $subSubId = $parts[7] ?? null;

        try {
            // 公开端点：健康检查、安装状态、安装向导、登录、登出
            if ($resource === 'health') {
                return self::json(200, ['status' => 'ok']);
            }
            if ($resource === 'status') {
                return self::json(200, [
                    'installed' => AdminAuth::installed(),
                    'authed' => AdminAuth::currentUserId() > 0,
                ]);
            }
            if ($resource === 'install') {
                return self::install($method, $body);
            }
            if ($resource === 'login') {
                return self::login($method, $body);
            }
            if ($resource === 'logout') {
                return self::logout($method);
            }
            if (!self::authorized()) {
                return self::json(401, ['error' => 'unauthorized']);
            }
            if ($resource === 'password') {
                return self::password($method, $body);
            }
            if ($resource === 'system') {
                return self::systemStatus($method);
            }
            if ($resource === 'cache') {
                return self::cache($method, $body);
            }
            if ($resource === 'metrics') {
                return [
                    'status' => 200,
                    'headers' => ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
                    'body' => Metrics::render(),
                ];
            }
            // 全局统计端点：/admin/api/stats（GET 列表 / POST 聚合）。
            // 注意 /admin/api/stats/aggregate 的 aggregate 不是数字 id，
            // 不能落入下方旧全局端点（会把 "aggregate" 强转 int 当 $subId 传，
            // 触发 ?string 类型错误）。
            if ($resource === 'stats') {
                return self::stats(0, $method, $body);
            }
            if ($resource === 'apps') {
                return self::apps($method, $id, $sub, $subId, $subSub, $subSubId, $body);
            }
            // 旧全局端点（app_id = 0）
            return self::appResource(0, $resource, $method, $id, '', null, $body);
        } catch (\Throwable $e) {
            return self::json(500, ['error' => $e->getMessage()]);
        }
    }

    /** 系统页：记录链路实时状态（日志 / 导入水位 / 滞后 / 计数）。 */
    private static function systemStatus(string $method): array
    {
        if ($method !== 'GET') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        $pdo = Db::pdo();
        $logPath = Recorder::logPath();
        $lines = 0;
        $bytes = 0;
        $mtime = null;
        if (is_file($logPath)) {
            $lines = (int) (trim((string) @shell_exec('wc -l < ' . escapeshellarg($logPath))) ?: 0);
            $bytes = (int) filesize($logPath);
            $mtime = date('Y-m-d H:i:s', (int) filemtime($logPath));
        }
        $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute(['agg_since']);
        $row = $stmt->fetch();
        $processedUntil = $row ? (string) $row['value'] : '';
        $lag = 0;
        if ($processedUntil !== '') {
            $ts = strtotime($processedUntil);
            if ($ts !== false) {
                $lag = max(0, time() - $ts);
            }
        }
        return self::json(200, [
            'log' => [
                'dir' => Recorder::logsDir(),
                'today_file' => basename($logPath),
                'lines' => $lines,
                'bytes' => $bytes,
                'last_write' => $mtime,
            ],
            'import' => [
                'processed_until' => $processedUntil,
                'lag_seconds' => $lag,
            ],
            'counts' => [
                'request_logs' => (int) $pdo->query('SELECT COUNT(*) c FROM request_logs')->fetch()['c'],
                'apis' => (int) $pdo->query('SELECT COUNT(*) c FROM apis')->fetch()['c'],
                'apps' => (int) $pdo->query('SELECT COUNT(*) c FROM apps')->fetch()['c'],
            ],
        ]);
    }

    private static function install(string $method, ?array $body): array
    {
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        if (AdminAuth::installed()) {
            return self::json(400, ['error' => '系统已安装，请直接登录']);
        }
        try {
            $r = AdminAuth::install(
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? '')
            );
        } catch (\Throwable $e) {
            return self::json(400, ['error' => $e->getMessage()]);
        }
        return self::json(200, [
            'ok' => true,
            'username' => $r['username'],
            'cron' => $r['cron'],
            'cron_ok' => $r['cron_ok'],
            'cron_php' => $r['cron_php'],
        ]);
    }

    private static function login(string $method, ?array $body): array
    {
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        if (!AdminAuth::installed()) {
            return self::json(400, ['error' => '尚未安装，请先完成初始化']);
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (AdminAuth::failCount($ip) >= AdminAuth::MAX_ATTEMPTS) {
            return self::json(429, ['error' => '登录尝试过多，请稍后再试']);
        }
        $token = AdminAuth::login(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? '')
        );
        if ($token === null) {
            if ($ip !== '') {
                AdminAuth::registerFailure($ip);
            }
            return self::json(401, ['error' => '用户名或密码错误']);
        }
        if ($ip !== '') {
            AdminAuth::resetFailures($ip);
        }
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
                'Set-Cookie' => AdminAuth::cookieHeader($token),
            ],
            'body' => json_encode(['ok' => true, 'username' => trim((string) ($body['username'] ?? ''))]),
        ];
    }

    private static function password(string $method, ?array $body): array
    {
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        $old = (string) ($body['old_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        if (strlen($new) < 8) {
            return self::json(400, ['error' => '新密码至少 8 位']);
        }
        $uid = AdminAuth::currentUserId();
        if ($uid <= 0 || !AdminAuth::changePassword($uid, $old, $new)) {
            return self::json(400, ['error' => '原密码错误']);
        }
        return self::json(200, ['ok' => true]);
    }

    private static function logout(string $method): array
    {
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        AdminAuth::logout();
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
                'Set-Cookie' => AdminAuth::cookieHeader('', 0),
            ],
            'body' => json_encode(['ok' => true]),
        ];
    }

    private static function apps(string $method, ?int $id, string $sub, ?string $subId, string $subSub = '', ?string $subSubId = null, ?array $body = null): array
    {
        if ($sub !== '') {
            if ($id === null) {
                return self::json(400, ['error' => 'app id required']);
            }
            return self::appResource($id, $sub, $method, $subId, $subSub, $subSubId, $body);
        }
        if ($method === 'GET' && $id === null) {
            return self::json(200, App::all());
        }
        if ($method === 'POST' && $id === null) {
            $data = self::appInput($body);
            $conflict = App::pathConflict(0, $data['base_path'], $data['match_mode'], $data['match_value']);
            if ($conflict !== null) {
                return self::json(409, ['error' => 'base_path 冲突', 'conflict' => $conflict]);
            }
            return self::json(200, ['id' => App::create($data)]);
        }
        if ($id !== null && $method === 'PUT') {
            $data = self::appInput($body);
            $conflict = App::pathConflict($id, $data['base_path'], $data['match_mode'], $data['match_value']);
            if ($conflict !== null) {
                return self::json(409, ['error' => 'base_path 冲突', 'conflict' => $conflict]);
            }
            App::update($id, $data);
            return self::json(200, ['ok' => true]);
        }
        if ($id !== null && $method === 'DELETE') {
            App::delete($id);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    /** @return array<string,mixed> */
    private static function appInput(?array $body): array
    {
        $base = (string) ($body['base_path'] ?? '/');
        if ($base === '' || $base[0] !== '/') {
            throw new \RuntimeException('base_path 必须以 / 开头');
        }
        return [
            'name' => (string) ($body['name'] ?? ''),
            'base_path' => $base,
            'match_mode' => (string) ($body['match_mode'] ?? 'prefix'),
            'match_value' => (string) ($body['match_value'] ?? ''),
            'default_allow' => (int) ($body['default_allow'] ?? 1),
            'default_cache_enabled' => (int) ($body['default_cache_enabled'] ?? 0),
            'default_cache_ttl' => (int) ($body['default_cache_ttl'] ?? 60),
            'auto_add_rules' => (int) ($body['auto_add_rules'] ?? 1),
            'status' => (int) ($body['status'] ?? 1),
        ];
    }

    private static function appResource(int $appId, string $resource, string $method, ?string $subId, string $subSub = '', ?string $subSubId = null, ?array $body = null): array
    {
        $rid = $subId !== null ? (int) $subId : null;
        return match ($resource) {
            'apis' => self::apis($appId, $method, $rid, $body),
            'clients' => self::clients($appId, $method),
            'stats' => self::stats($appId, $method, $body),
            'rules' => self::rules($appId, $method, $rid, $body),
            'variables' => self::variables($appId, $method, $subId, $body),
            'dimensions' => self::dimensions($appId, $method, $subId, $body),
            'blacklist' => self::blacklist($appId, $method, $rid, $body),
            'tokens' => self::tokens($appId, $method, $rid, $body),
            'upstreams' => self::upstreams($appId, $method, $rid, $subSub, $subSubId, $body),
            default => self::json(404, ['error' => 'not found']),
        };
    }

    private static function apis(int $appId, string $method, ?int $id, ?array $body): array
    {
        $pdo = Db::pdo();
        if ($method === 'GET' && $id === null) {
            $stmt = $pdo->prepare(
                "SELECT a.*, COALESCE(s.requests,0) AS requests, COALESCE(s.errors,0) AS errors,
                        COALESCE(s.denied,0) AS denied,
                        CASE WHEN COALESCE(s.requests,0) > 0 THEN s.total_ms / s.requests ELSE 0 END AS avg_ms
                 FROM apis a
                 LEFT JOIN (SELECT api_id, SUM(requests) requests, SUM(errors) errors,
                                   SUM(denied) denied, SUM(total_ms) total_ms
                            FROM api_stats WHERE app_id = ? GROUP BY api_id) s ON s.api_id = a.id
                 WHERE a.app_id = ?
                 ORDER BY s.requests DESC, a.id ASC"
            );
            $stmt->execute([$appId, $appId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST' && $id === null) {
            $methodName = strtoupper((string) ($body['method'] ?? ''));
            $template = (string) ($body['template'] ?? '');
            $conflict = self::apiConflict($appId, 0, $methodName, $template);
            if ($conflict !== null) {
                return self::json(409, ['error' => 'URL 冲突', 'conflict' => $conflict]);
            }
            $pdo->prepare('INSERT INTO apis (app_id, method, template, display_name, auto) VALUES (?, ?, ?, ?, 0)')
                ->execute([$appId, $methodName, $template, (string) ($body['display_name'] ?? '')]);
            $newId = (int) $pdo->lastInsertId();
            RuleEngine::invalidateApiRegistry($appId);
            return self::json(200, ['id' => $newId]);
        }
        if ($method === 'PUT' && $id !== null) {
            $methodName = strtoupper((string) ($body['method'] ?? ''));
            $template = (string) ($body['template'] ?? '');
            $conflict = self::apiConflict($appId, $id, $methodName, $template);
            if ($conflict !== null) {
                return self::json(409, ['error' => 'URL 冲突', 'conflict' => $conflict]);
            }
            $pdo->prepare('UPDATE apis SET method=?, template=?, display_name=?, updated_at=datetime(\'now\') WHERE id=? AND app_id=?')
                ->execute([$methodName, $template, (string) ($body['display_name'] ?? ''), $id, $appId]);
            RuleEngine::invalidateApiRegistry($appId);
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $pdo->prepare('DELETE FROM apis WHERE id=? AND app_id=?')->execute([$id, $appId]);
            RuleEngine::invalidateApiRegistry($appId);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function apiConflict(int $appId, int $exceptId, string $method, string $template): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM apis WHERE app_id = ? AND id != ?');
        $stmt->execute([$appId, $exceptId]);
        foreach ($stmt->fetchAll() as $other) {
            if ($method !== '*' && $method !== strtoupper((string) $other['method'])) {
                continue;
            }
            $t = (string) $other['template'];
            if ($t === $template
                || Normalizer::templateMatches($template, $t)
                || Normalizer::templateMatches($t, $template)) {
                return $other;
            }
        }
        return null;
    }

    private static function clients(int $appId, string $method): array
    {
        if ($method !== 'GET') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        $stmt = Db::pdo()->prepare(
            "SELECT client_key, SUM(requests) requests, SUM(errors) errors, SUM(denied) denied,
                    SUM(total_ms) total_ms,
                    CASE WHEN SUM(requests) > 0 THEN SUM(total_ms)/SUM(requests) ELSE 0 END AS avg_ms
             FROM client_stats WHERE app_id = ? GROUP BY client_key ORDER BY requests DESC"
        );
        $stmt->execute([$appId]);
        return self::json(200, $stmt->fetchAll());
    }

    private static function stats(int $appId, string $method, ?array $body): array
    {
        if ($method === 'GET') {
            $stmt = Db::pdo()->prepare(
                'SELECT bucket, SUM(requests) requests, SUM(errors) errors, SUM(denied) denied
                 FROM api_stats WHERE app_id = ? GROUP BY bucket ORDER BY bucket ASC'
            );
            $stmt->execute([$appId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST') {
            return self::json(200, Aggregator::run((string) ($body['bucket'] ?? 'hour'), $appId));
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function rules(int $appId, string $method, ?int $id, ?array $body): array
    {
        $pdo = Db::pdo();
        if ($method === 'GET' && $id === null) {
            return self::json(200, RuleEngine::all($appId));
        }
        if ($method === 'POST' && $id === null) {
            $type = (string) ($body['action_type'] ?? '');
            if (!in_array($type, self::RULE_TYPES, true)) {
                return self::json(400, ['error' => 'unknown action_type: ' . $type]);
            }
            $apiId = isset($body['api_id']) ? (int) $body['api_id'] : null;
            $scope = $apiId !== null && $apiId > 0 ? 'api' : 'app';
            $phase = $type === 'response_transform' ? 'filter' : 'access';
            $pdo->prepare(
                'INSERT INTO rules (app_id, scope, api_id, phase, name, description, enabled, priority, api_pattern, client_pattern, action_type, params)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $appId, $scope, $apiId, $phase,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (int) ($body['enabled'] ?? 1),
                (int) ($body['priority'] ?? 0),
                (string) ($body['api_pattern'] ?? '*'),
                (string) ($body['client_pattern'] ?? '*'),
                $type,
                json_encode($body['params'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            RuleEngine::bumpVersion($appId);
            return self::json(200, ['id' => (int) $pdo->lastInsertId()]);
        }
        if ($method === 'PUT' && $id !== null) {
            $pdo->prepare(
                'UPDATE rules SET name=?, description=?, enabled=?, priority=?, api_pattern=?, client_pattern=?, action_type=?, params=?, updated_at=datetime(\'now\')
                 WHERE id=? AND app_id=?'
            )->execute([
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (int) ($body['enabled'] ?? 1),
                (int) ($body['priority'] ?? 0),
                (string) ($body['api_pattern'] ?? '*'),
                (string) ($body['client_pattern'] ?? '*'),
                (string) ($body['action_type'] ?? ''),
                json_encode($body['params'] ?? [], JSON_UNESCAPED_UNICODE),
                $id, $appId,
            ]);
            RuleEngine::bumpVersion($appId);
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $pdo->prepare('DELETE FROM rules WHERE id=? AND app_id=?')->execute([$id, $appId]);
            RuleEngine::bumpVersion($appId);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function variables(int $appId, string $method, ?string $name, ?array $body): array
    {
        if ($method === 'GET' && $name === null) {
            return self::json(200, Variables::all($appId));
        }
        if ($method === 'POST' && $name === null) {
            Variables::create(
                $appId,
                (string) ($body['name'] ?? ''),
                (string) ($body['key_dim'] ?? 'req.ip'),
                (string) ($body['source'] ?? ''),
                (int) ($body['ttl'] ?? 300)
            );
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $name !== null) {
            Variables::delete($appId, $name);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function dimensions(int $appId, string $method, ?string $name, ?array $body): array
    {
        if ($method === 'GET' && $name === null) {
            return self::json(200, Dimensions::all($appId));
        }
        if ($method === 'POST' && $name === null) {
            Dimensions::create($appId, (string) ($body['name'] ?? ''), (array) ($body['chain'] ?? []));
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $name !== null) {
            Dimensions::delete($appId, $name);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function blacklist(int $appId, string $method, ?int $id, ?array $body): array
    {
        if ($method === 'GET' && $id === null) {
            $stmt = Db::pdo()->prepare('SELECT * FROM blacklist WHERE app_id = ? ORDER BY id DESC');
            $stmt->execute([$appId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST' && $id === null) {
            Blacklist::add(
                $appId,
                (string) ($body['dimension'] ?? 'req.ip'),
                (string) ($body['value'] ?? ''),
                (string) ($body['reason'] ?? ''),
                isset($body['expires_at']) ? (string) $body['expires_at'] : null
            );
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $id !== null) {
            Blacklist::remove($appId, $id);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function tokens(int $appId, string $method, ?int $id, ?array $body): array
    {
        $pdo = Db::pdo();
        if ($method === 'GET' && $id === null) {
            $stmt = $pdo->prepare('SELECT * FROM tokens WHERE app_id = ? ORDER BY id DESC');
            $stmt->execute([$appId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST' && $id === null) {
            $pdo->prepare('INSERT INTO tokens (app_id, value, owner, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([
                    $appId,
                    (string) ($body['value'] ?? 'tok_' . bin2hex(random_bytes(8))),
                    (string) ($body['owner'] ?? ''),
                    isset($body['expires_at']) ? (string) $body['expires_at'] : null,
                ]);
            Auth::invalidateTokens($appId);
            return self::json(200, ['id' => (int) $pdo->lastInsertId()]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $pdo->prepare('DELETE FROM tokens WHERE id=? AND app_id=?')->execute([$id, $appId]);
            Auth::invalidateTokens($appId);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function upstreams(int $appId, string $method, ?int $id, string $subSub = '', ?string $subSubId = null, ?array $body = null): array
    {
        $pdo = Db::pdo();
        if ($subSub === 'targets') {
            if ($id === null) {
                return self::json(400, ['error' => 'group id required']);
            }
            return self::upstreamTargets($appId, $id, $method, $subSubId, $body);
        }
        if ($method === 'GET' && $id === null) {
            $stmt = $pdo->prepare('SELECT * FROM upstream_groups WHERE app_id = ? ORDER BY id ASC');
            $stmt->execute([$appId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST' && $id === null) {
            $pdo->prepare(
                'INSERT INTO upstream_groups
                 (app_id, name, strategy, path_mode, prefix_from, prefix_to, check_mode,
                  check_interval, check_timeout, check_path, fail_threshold, recover_threshold)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $appId,
                (string) ($body['name'] ?? ''),
                (string) ($body['strategy'] ?? 'round_robin'),
                (string) ($body['path_mode'] ?? 'strip'),
                (string) ($body['prefix_from'] ?? ''),
                (string) ($body['prefix_to'] ?? ''),
                (string) ($body['check_mode'] ?? 'active'),
                (int) ($body['check_interval'] ?? 10),
                (int) ($body['check_timeout'] ?? 2),
                (string) ($body['check_path'] ?? '/healthz'),
                (int) ($body['fail_threshold'] ?? 3),
                (int) ($body['recover_threshold'] ?? 2),
            ]);
            Upstream::invalidate($appId);
            return self::json(200, ['id' => (int) $pdo->lastInsertId()]);
        }
        if ($method === 'PUT' && $id !== null) {
            $pdo->prepare(
                'UPDATE upstream_groups SET name=?, strategy=?, path_mode=?, prefix_from=?, prefix_to=?,
                        check_mode=?, check_interval=?, check_timeout=?, check_path=?,
                        fail_threshold=?, recover_threshold=?
                 WHERE id=? AND app_id=?'
            )->execute([
                (string) ($body['name'] ?? ''),
                (string) ($body['strategy'] ?? 'round_robin'),
                (string) ($body['path_mode'] ?? 'strip'),
                (string) ($body['prefix_from'] ?? ''),
                (string) ($body['prefix_to'] ?? ''),
                (string) ($body['check_mode'] ?? 'active'),
                (int) ($body['check_interval'] ?? 10),
                (int) ($body['check_timeout'] ?? 2),
                (string) ($body['check_path'] ?? '/healthz'),
                (int) ($body['fail_threshold'] ?? 3),
                (int) ($body['recover_threshold'] ?? 2),
                $id,
                $appId,
            ]);
            Upstream::invalidate($appId);
            return self::json(200, ['ok' => true]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $pdo->prepare('DELETE FROM upstream_groups WHERE id=? AND app_id=?')->execute([$id, $appId]);
            Upstream::invalidate($appId);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function upstreamTargets(int $appId, int $groupId, string $method, ?string $tid, ?array $body): array
    {
        $pdo = Db::pdo();
        if ($method === 'GET') {
            $stmt = $pdo->prepare('SELECT * FROM upstream_targets WHERE group_id = ? ORDER BY id ASC');
            $stmt->execute([$groupId]);
            return self::json(200, $stmt->fetchAll());
        }
        if ($method === 'POST') {
            $pdo->prepare(
                'INSERT INTO upstream_targets (group_id, address, role, weight, enabled)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $groupId,
                (string) ($body['address'] ?? ''),
                (string) ($body['role'] ?? 'round_robin'),
                (int) ($body['weight'] ?? 100),
                (int) ($body['enabled'] ?? 1),
            ]);
            Upstream::invalidate($appId);
            return self::json(200, ['id' => (int) $pdo->lastInsertId()]);
        }
        if ($method === 'DELETE' && $tid !== null) {
            $pdo->prepare('DELETE FROM upstream_targets WHERE id = ? AND group_id = ?')
                ->execute([(int) $tid, $groupId]);
            Upstream::invalidate($appId);
            return self::json(200, ['ok' => true]);
        }
        return self::json(405, ['error' => 'method not allowed']);
    }

    private static function cache(string $method, ?array $body): array
    {
        if ($method !== 'POST') {
            return self::json(405, ['error' => 'method not allowed']);
        }
        $url = (string) Config::get('souin.admin_url', '');
        if ($url === '') {
            return self::json(501, ['error' => 'souin admin url not configured']);
        }
        // 原型实现：记录 PURGE 意图，实际对接 Souin admin API
        return self::json(200, ['purged' => (string) ($body['key'] ?? '*')]);
    }

    private static function authorized(): bool
    {
        return AdminAuth::currentUserId() > 0;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private static function json(int $status, mixed $data): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
