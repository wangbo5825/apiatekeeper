<?php

declare(strict_types=1);

namespace Apigate;

use RuntimeException;

/**
 * 纯 PHP JWT（HS256/HS384/HS512、RS256/RS384/RS512），零依赖。
 * 校验失败抛异常；safeSubject 用于身份识别时静默失败。
 */
final class Jwt
{
    public static function decode(string $token, string $secret = '', string $publicKey = ''): array
    {
        $secret = $secret !== '' ? $secret : (string) Config::get('auth.jwt_secret', '');
        $publicKey = $publicKey !== '' ? $publicKey : (string) Config::get('auth.jwt_public_key', '');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('malformed token');
        }
        [$headB64, $payB64, $sigB64] = $parts;
        $header = json_decode(self::b64urlDecode($headB64), true);
        $payload = json_decode(self::b64urlDecode($payB64), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('invalid jwt json');
        }
        $alg = (string) ($header['alg'] ?? '');
        $sig = self::b64urlDecode($sigB64);
        if (!self::verifySignature($alg, $headB64 . '.' . $payB64, $sig, $secret, $publicKey)) {
            throw new RuntimeException('signature mismatch');
        }

        $now = time();
        if (isset($payload['exp']) && (int) $payload['exp'] <= $now) {
            throw new RuntimeException('token expired');
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            throw new RuntimeException('token not yet valid');
        }
        $iss = (string) Config::get('auth.default_issuer', '');
        $aud = (string) Config::get('auth.default_audience', '');
        if ($iss !== '' && isset($payload['iss']) && $payload['iss'] !== $iss) {
            throw new RuntimeException('issuer mismatch');
        }
        if ($aud !== '' && isset($payload['aud'])) {
            $auds = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
            if (!in_array($aud, $auds, true)) {
                throw new RuntimeException('audience mismatch');
            }
        }
        return $payload;
    }

    /** 身份识别用：解析成功返回 payload 的 sub，失败返回 null。 */
    public static function safeSubject(string $token): ?string
    {
        try {
            $payload = self::decode(
                $token,
                (string) Config::get('auth.jwt_secret', ''),
                (string) Config::get('auth.jwt_public_key', '')
            );
        } catch (\Throwable) {
            return null;
        }
        return isset($payload['sub']) ? (string) $payload['sub'] : null;
    }

    private static function verifySignature(string $alg, string $data, string $sig, string $secret, string $publicKey): bool
    {
        if (str_starts_with($alg, 'HS')) {
            if ($secret === '') {
                throw new RuntimeException('jwt secret not configured');
            }
            $algo = match ($alg) {
                'HS256' => 'sha256',
                'HS384' => 'sha384',
                'HS512' => 'sha512',
                default => throw new RuntimeException('unsupported alg: ' . $alg),
            };
            return hash_equals(hash_hmac($algo, $data, $secret, true), $sig);
        }
        if (str_starts_with($alg, 'RS')) {
            if ($publicKey === '') {
                throw new RuntimeException('jwt public key not configured');
            }
            $algo = match ($alg) {
                'RS256' => OPENSSL_ALGO_SHA256,
                'RS384' => OPENSSL_ALGO_SHA384,
                'RS512' => OPENSSL_ALGO_SHA512,
                default => throw new RuntimeException('unsupported alg: ' . $alg),
            };
            return openssl_verify($data, $sig, $publicKey, $algo) === 1;
        }
        throw new RuntimeException('unsupported alg: ' . $alg);
    }

    private static function b64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        return $out === false ? '' : $out;
    }
}
