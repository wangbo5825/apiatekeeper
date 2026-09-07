<?php

declare(strict_types=1);

use Apigate\Jwt;

function test_jwt_hs256_roundtrip(): void
{
    $token = make_hs256_token(['sub' => 'u1', 'iss' => 'apigate'], 'test-secret');
    $payload = Jwt::decode($token);
    assert_eq('u1', $payload['sub']);
    assert_eq('u1', Jwt::safeSubject($token));
}

function test_jwt_bad_signature(): void
{
    $token = make_hs256_token(['sub' => 'u1'], 'wrong-secret');
    try {
        Jwt::decode($token);
        assert_true(false, 'should have thrown');
    } catch (RuntimeException) {
        assert_true(true);
    }
    assert_eq(null, Jwt::safeSubject($token));
}

function test_jwt_expired(): void
{
    $token = make_hs256_token(['sub' => 'u1'], 'test-secret', -10);
    assert_eq(null, Jwt::safeSubject($token));
}

function test_jwt_issuer_mismatch(): void
{
    $token = make_hs256_token(['sub' => 'u1', 'iss' => 'other'], 'test-secret');
    try {
        Jwt::decode($token);
        assert_true(false, 'should have thrown');
    } catch (RuntimeException) {
        assert_true(true);
    }
}

function test_jwt_rs256(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        echo "SKIP rs256 (keygen unavailable)\n";
        return;
    }
    assert_true($key !== false);
    $details = openssl_pkey_get_details($key);
    $pub = $details['key'];

    $header = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = ['sub' => 'rs-user', 'exp' => time() + 300];
    $p = b64url(json_encode($payload));
    $data = "$header.$p";
    openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
    $token = $header . '.' . $p . '.' . b64url($sig);

    $decoded = Jwt::decode($token, '', $pub);
    assert_eq('rs-user', $decoded['sub']);

    $bad = $header . '.' . $p . '.' . b64url(str_repeat('x', strlen($sig)));
    assert_eq(null, Jwt::safeSubject($bad));
}
