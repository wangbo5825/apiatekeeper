<?php

/**
 * 本地测试辅助：生成 HS256 JWT。
 * 用法：php dev_make_token.php <secret> <sub> [issuer] [ttl_seconds]
 */

$secret = $argv[1] ?? 'local-secret';
$sub = $argv[2] ?? 'dev-user';
$iss = $argv[3] ?? 'apigate';
$ttl = (int) ($argv[4] ?? 3600);

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

$header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = b64url(json_encode([
    'sub' => $sub,
    'iss' => $iss,
    'iat' => time(),
    'exp' => time() + $ttl,
]));
$sig = b64url(hash_hmac('sha256', "$header.$payload", $secret, true));

echo "$header.$payload.$sig\n";
