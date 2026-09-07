<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 请求上下文：请求级变量解析 + 语义维度解析 + 系统变量读写。
 * 系统变量的 key 由维度解析结果（当前请求的值）决定。
 */
final class Context
{
    public int $appId;
    public string $method;
    public string $rawPath = '';
    public string $relPath = '';
    public string $template = '/';
    public string $ip = '';
    public string $userid = '';
    public string $apikey = '';

    /** @var array<string,string> 小写请求头 */
    public array $headers = [];
    /** @var array<string,string|array> query 参数 */
    public array $query = [];

    /** @var array<string,string>|null 路径参数缓存 */
    private ?array $pathParams = null;

    public function __construct(
        array $app,
        string $method,
        string $uri,
        array $headers,
        string $clientIp,
        string $userid = '',
        string $apikey = ''
    ) {
        $this->appId = (int) $app['id'];
        $this->method = strtoupper($method);
        $this->rawPath = (string) parse_url($uri, PHP_URL_PATH);
        $this->relPath = App::relativePath((string) $app['base_path'], $this->rawPath);
        $this->template = Normalizer::path($this->relPath);
        $this->ip = $clientIp;
        $this->userid = $userid;
        $this->apikey = $apikey;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $qs = (string) parse_url($uri, PHP_URL_QUERY);
        parse_str($qs, $this->query);
    }

    /** 解析请求级变量引用（req.*）。 */
    public function getRequestVar(string $ref): ?string
    {
        return match (true) {
            $ref === 'req.ip' => $this->ip !== '' ? $this->ip : null,
            $ref === 'req.url' => $this->template,
            $ref === 'req.raw_url' => $this->relPath,
            $ref === 'req.full_path' => $this->rawPath,
            $ref === 'req.method' => $this->method,
            $ref === 'req.userid' => $this->userid !== '' ? $this->userid : null,
            $ref === 'req.apikey' => $this->apikey !== '' ? $this->apikey : null,
            str_starts_with($ref, 'req.header:') => $this->headers[strtolower(substr($ref, 11))] ?? null,
            str_starts_with($ref, 'req.query:') => $this->queryValue(substr($ref, 10)),
            str_starts_with($ref, 'req.path:') => $this->pathParams()[substr($ref, 9)] ?? null,
            default => null,
        };
    }

    /** 解析维度引用：请求级变量或语义维度（dim:*）。 */
    public function resolve(string $ref): ?string
    {
        if (str_starts_with($ref, 'dim:')) {
            return Dimensions::resolve(substr($ref, 4), $this);
        }
        $v = $this->getRequestVar($ref);
        return ($v === null || $v === '') ? null : $v;
    }

    /** 读取系统变量（经声明 + 维度解析 key）。 */
    public function getVariable(string $name): ?string
    {
        $decl = Variables::declaration($this->appId, $name);
        if ($decl === null) {
            return null;
        }
        $key = $this->resolve((string) $decl['key_dim']);
        if ($key === null) {
            return null;
        }
        $v = Cache::get(self::varKey($this->appId, $name, $key));
        return is_string($v) ? $v : null;
    }

    /** 写入系统变量（经声明；key 缺失时跳过）。 */
    public function setVariable(string $name, string $value): void
    {
        $decl = Variables::declaration($this->appId, $name);
        if ($decl === null) {
            return;
        }
        $key = $this->resolve((string) $decl['key_dim']);
        if ($key === null) {
            return;
        }
        Cache::set(self::varKey($this->appId, $name, $key), $value, (int) $decl['ttl']);
    }

    public static function varKey(int $appId, string $name, string $key): string
    {
        return 'gk:var:' . $appId . ':' . $name . ':' . md5($key);
    }

    private function queryValue(string $name): ?string
    {
        $v = $this->query[$name] ?? null;
        if (is_scalar($v)) {
            return (string) $v;
        }
        return $v === null ? null : json_encode($v);
    }

    /** 从模板与原始相对路径提取路径参数。 */
    private function pathParams(): array
    {
        if ($this->pathParams !== null) {
            return $this->pathParams;
        }
        $this->pathParams = [];
        $tpl = explode('/', trim($this->template, '/'));
        $raw = explode('/', trim($this->relPath, '/'));
        foreach ($tpl as $i => $seg) {
            if (str_starts_with($seg, '{') && str_ends_with($seg, '}') && isset($raw[$i])) {
                $this->pathParams[trim($seg, '{}')] = $raw[$i];
            }
        }
        return $this->pathParams;
    }
}
