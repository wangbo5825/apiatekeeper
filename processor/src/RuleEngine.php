<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 规则引擎 v2：按应用加载（APCu 快照 + 版本失效）、应用级/API 级多规则、
 * access/filter 两阶段、8+ 种规则类型。
 */
final class RuleEngine
{
    private const VER_KEY = 'gk:rules_ver:%d';
    private const SNAP_KEY = 'gk:rules:%d:%d';
    private const API_REG = 'gk:api_reg:%d';
    private const TTL = 3600;

    /** 规则变更后使该应用快照失效。 */
    public static function bumpVersion(int $appId): void
    {
        $key = sprintf(self::VER_KEY, $appId);
        Cache::incr($key, 1, 0, 1);
        Cache::delete(sprintf(self::SNAP_KEY, $appId, self::version($appId)));
    }

    public static function version(int $appId): int
    {
        return (int) Cache::get(sprintf(self::VER_KEY, $appId), 1);
    }

    /** @return array<int,array<string,mixed>> 应用规则快照。 */
    public static function all(int $appId, bool $onlyEnabled = false): array
    {
        $snap = sprintf(self::SNAP_KEY, $appId, self::version($appId));
        $rules = Cache::get($snap);
        if (!is_array($rules)) {
            $stmt = Db::pdo()->prepare('SELECT * FROM rules WHERE app_id = ? ORDER BY priority DESC, id ASC');
            $stmt->execute([$appId]);
            $rules = $stmt->fetchAll();
            foreach ($rules as &$r) {
                $r['params'] = json_decode((string) $r['params'], true) ?: [];
            }
            unset($r);
            Cache::set($snap, $rules, self::TTL);
        }
        if ($onlyEnabled) {
            return array_values(array_filter($rules, static fn (array $r): bool => (int) $r['enabled'] === 1));
        }
        return $rules;
    }

    /**
     * 匹配规则：应用级（scope=app）+ API 级（scope=api）。
     * 排序：API 具体度 > 客户端具体度 > priority > id。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function match(int $appId, string $apiKey, Identity $id, string $phase = 'access', int $apiId = 0): array
    {
        $matched = [];
        foreach (self::all($appId, true) as $rule) {
            if ((string) $rule['phase'] !== $phase) {
                continue;
            }
            $scope = (string) $rule['scope'];
            if ($scope === 'api') {
                $bound = $rule['api_id'] !== null && (int) $rule['api_id'] > 0;
                if ($bound) {
                    if ((int) $rule['api_id'] !== $apiId) {
                        continue;
                    }
                } elseif (!self::apiMatches((string) $rule['api_pattern'], $apiKey)) {
                    continue;
                }
            } elseif (!self::apiMatches((string) $rule['api_pattern'], $apiKey)) {
                continue;
            }
            if (!Identity::patternMatches((string) $rule['client_pattern'], $id)) {
                continue;
            }
            $matched[] = $rule;
        }
        usort($matched, static function (array $a, array $b): int {
            $sa = self::specificityOf($a);
            $sb = self::specificityOf($b);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            $p = (int) $b['priority'] <=> (int) $a['priority'];
            return $p !== 0 ? $p : ((int) $a['id'] <=> (int) $b['id']);
        });
        return $matched;
    }

    private static function specificityOf(array $rule): int
    {
        $apiScore = 0;
        $parts = explode(' ', (string) $rule['api_pattern']);
        for ($i = 1; $i < count($parts); $i++) {
            $apiScore += Normalizer::specificity($parts[$i]);
        }
        $clientScore = ((string) $rule['client_pattern'] === '*' || $rule['client_pattern'] === '') ? 0 : 100;
        return $apiScore * 1000 + $clientScore;
    }

    public static function apiMatches(string $pattern, string $apiKey): bool
    {
        if ($pattern === '*' || $pattern === '') {
            return true;
        }
        $parts = explode(' ', $pattern, 2);
        $method = strtoupper($parts[0]);
        $template = $parts[1] ?? '/';
        [$keyMethod, $keyPath] = array_pad(explode(' ', $apiKey, 2), 2, '');
        if ($method !== '*' && $method !== $keyMethod) {
            return false;
        }
        return Normalizer::templateMatches($template, $keyPath);
    }

    /**
     * 执行 access 阶段规则。
     *
     * @param array<int,array<string,mixed>> $matched
     * @return array{deny:?array{status:int,headers:array,body:string}, route:array, rewrite:array, context:array}
     */
    public static function evaluate(array $matched, Context $ctx, Identity $id): array
    {
        $route = [];
        $rewrite = [];
        $context = ['cache_policy' => '', 'cache_ttl' => 0];

        foreach ($matched as $rule) {
            $type = (string) $rule['action_type'];
            $params = (array) $rule['params'];

            if ($type === 'auth' && !Auth::check($params, $ctx, $id)) {
                return self::deny($route, $rewrite, $context, 401, ['WWW-Authenticate' => 'Bearer realm="gatekeeper"'], '{"error":"unauthorized"}');
            }

            if ($type === 'blacklist' && Blacklist::hits($params, $ctx)) {
                return self::deny($route, $rewrite, $context, 403, [], '{"error":"forbidden"}');
            }

            if ($type === 'rate_limit') {
                $rl = RateLimit::check($rule, $ctx, $params);
                if ($rl['allowed'] === false) {
                    return self::deny($route, $rewrite, $context, 429, ['Retry-After' => (string) $rl['retry_after']], '{"error":"rate limited"}');
                }
                if (!empty($params['auto_blacklist']) && $rl['exceeded_once']) {
                    Blacklist::addAuto($ctx, (int) ($params['auto_blacklist_duration'] ?? 3600));
                }
            }

            if ($type === 'variable_check') {
                if (!self::variableCheck($params, $ctx)) {
                    return self::deny($route, $rewrite, $context, 403, [], '{"error":"forbidden"}');
                }
            }

            if ($type === 'save_variable') {
                $from = (string) ($params['from'] ?? '');
                $name = (string) ($params['name'] ?? '');
                if ($name !== '') {
                    $value = self::resolveValue($from, $ctx);
                    if ($value !== null) {
                        $ctx->setVariable($name, $value);
                    }
                }
            }

            if ($type === 'change_target') {
                if (!empty($params['upstream']) && empty($route['backend'])) {
                    $route['backend'] = (string) $params['upstream'];
                }
                if (!empty($params['rewrite_path'])) {
                    $route['rewrite_path'] = (string) $params['rewrite_path'];
                }
                if (!empty($params['strip_prefix'])) {
                    $route['strip_prefix'] = 1;
                }
                if (!empty($params['set_headers']) && is_array($params['set_headers'])) {
                    foreach ($params['set_headers'] as $h => $v) {
                        $rewrite['headers'][$h] = self::resolveValue((string) $v, $ctx) ?? (string) $v;
                    }
                }
            }

            if ($type === 'stats') {
                self::applyStats($params, $ctx);
            }

            if ($type === 'cache') {
                $mode = (string) ($params['mode'] ?? 'auto');
                if ($context['cache_policy'] === '' && in_array($mode, ['force', 'no-cache', 'auto'], true)) {
                    $context['cache_policy'] = $mode;
                    $context['cache_ttl'] = (int) ($params['ttl'] ?? 60);
                    $route['cache'] = $mode;
                    if ($mode === 'force') {
                        $route['ttl'] = $context['cache_ttl'];
                    }
                }
            }
        }

        if ($id->key() !== '') {
            $rewrite['headers']['X-Apigate-Client'] = $id->key();
        }
        return ['deny' => null, 'route' => $route, 'rewrite' => $rewrite, 'context' => $context];
    }

    /** 统计计数：按维度（变量引用）自增；可选写入声明变量。 */
    private static function applyStats(array $params, Context $ctx): void
    {
        $dims = (array) ($params['dimensions'] ?? []);
        foreach ($dims as $d) {
            $v = $ctx->resolve((string) $d) ?? 'unknown';
            $ckey = sprintf('gk:stat:%d:%s=%s', $ctx->appId, (string) $d, $v);
            $count = Cache::incr($ckey, 1, (int) ($params['ttl'] ?? 86400));
            $target = (string) ($params['target'] ?? '');
            if ($target !== '') {
                $ctx->setVariable($target, (string) $count);
            }
        }
    }

    private static function variableCheck(array $params, Context $ctx): bool
    {
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            return true;
        }
        $value = $ctx->getVariable($name);
        if ($value === null) {
            return false;
        }
        $require = $params['require_value'] ?? null;
        if ($require !== null && (string) $require !== $value) {
            return false;
        }
        if (!empty($params['min_ttl'])) {
            // APCu 剩余 TTL 不易直接读取；此处按实现能力留接口
        }
        return true;
    }

    /** 值解析：req:/dim: 引用或静态字符串。 */
    public static function resolveValue(string $ref, Context $ctx): ?string
    {
        if (str_starts_with($ref, 'req.') || str_starts_with($ref, 'dim:') || str_starts_with($ref, 'var:')) {
            if (str_starts_with($ref, 'var:')) {
                return $ctx->getVariable(substr($ref, 4));
            }
            return $ctx->resolve($ref);
        }
        return $ref;
    }

    /** 应用内 API 注册表（内存）："METHOD template" => id。 */
    public static function apiRegistry(int $appId): array
    {
        $reg = Cache::get(sprintf(self::API_REG, $appId));
        if (!is_array($reg)) {
            $reg = [];
            $stmt = Db::pdo()->prepare('SELECT id, method, template FROM apis WHERE app_id = ?');
            $stmt->execute([$appId]);
            foreach ($stmt->fetchAll() as $row) {
                $reg[strtoupper((string) $row['method']) . ' ' . (string) $row['template']] = (int) $row['id'];
            }
            Cache::set(sprintf(self::API_REG, $appId), $reg, 30);
        }
        return $reg;
    }

    public static function apiId(int $appId, string $apiKey): int
    {
        return self::apiRegistry($appId)[$apiKey] ?? 0;
    }

    public static function invalidateApiRegistry(int $appId): void
    {
        Cache::delete(sprintf(self::API_REG, $appId));
    }

    /**
     * @param array{status:int,headers:array,body:string} ...$deny
     */
    private static function deny(array $route, array $rewrite, array $context, int $status, array $headers, string $body): array
    {
        return [
            'deny' => ['status' => $status, 'headers' => $headers, 'body' => $body],
            'route' => $route,
            'rewrite' => $rewrite,
            'context' => $context,
        ];
    }
}
