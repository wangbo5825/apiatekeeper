<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 客户端身份：JWT subject > API Key > IP。
 */
final class Identity
{
    public string $ip;
    public string $apiKey;
    public string $jwtSubject;

    public function __construct(string $ip = '', string $apiKey = '', string $jwtSubject = '')
    {
        $this->ip = $ip;
        $this->apiKey = $apiKey;
        $this->jwtSubject = $jwtSubject;
    }

    public static function resolve(string $clientIp, array $headers): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $apiKey = trim((string) ($headers['x-api-key'] ?? ''));
        $jwtSubject = '';

        $auth = trim((string) ($headers['authorization'] ?? ''));
        if (stripos($auth, 'bearer ') === 0) {
            $token = trim(substr($auth, 7));
            if ($token !== '') {
                $jwtSubject = Jwt::safeSubject($token) ?? '';
            }
        }

        return new self($clientIp, $apiKey, $jwtSubject);
    }

    /** 匹配规则用的客户端键（用于 client_pattern 与统计分组）。 */
    public function key(): string
    {
        if ($this->jwtSubject !== '') {
            return 'user:' . $this->jwtSubject;
        }
        if ($this->apiKey !== '') {
            return 'key:' . $this->apiKey;
        }
        return 'ip:' . $this->ip;
    }

    /**
     * client_pattern 匹配：支持 *、ip:x.x.x.x、ip:cidr、key:xxx、user:xxx、
     * 逗号分隔多项。
     */
    public static function patternMatches(string $pattern, self $id): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern === '*') {
            return true;
        }
        foreach (explode(',', $pattern) as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            if ($one === 'ip:' . $id->ip || $one === 'key:' . $id->apiKey
                || $one === 'user:' . $id->jwtSubject) {
                return true;
            }
            if (preg_match('#^ip:([^/]+)/(\d{1,2})$#', $one, $m)) {
                if (self::ipInCidr($id->ip, $m[1], (int) $m[2])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $cidrLong = ip2long($cidr);
        if ($ipLong === false || $cidrLong === false || $mask < 0 || $mask > 32) {
            return false;
        }
        $netmask = $mask === 0 ? 0 : (0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF;
        return ($ipLong & $netmask) === ($cidrLong & $netmask);
    }
}
