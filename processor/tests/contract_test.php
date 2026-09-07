<?php

declare(strict_types=1);

use Apigate\App;
use Apigate\Contract;
use Apigate\Db;
use Apigate\Importer;

function test_contract_app_not_found(): void
{
    $resp = Contract::handle([
        'phase' => 'access', 'id' => 'n-1', 'method' => 'GET',
        'uri' => '/other/x', 'headers' => [], 'client_ip' => '127.0.0.1',
    ]);
    $body = json_decode($resp['body'], true);
    assert_eq('deny', $body['decision']);
    assert_eq(404, $body['deny']['status']);
}

function test_contract_access_filter_flow(): void
{
    $appId = make_app('orders', '/v1');
    insert_rule($appId, 'GET /cache/{id}', '*', 'cache', ['mode' => 'force', 'ttl' => 30]);

    $access = Contract::handle([
        'phase' => 'access', 'id' => 'req-1', 'method' => 'GET',
        'uri' => '/v1/cache/123?x=1', 'headers' => ['X-Api-Key' => 'k1'], 'client_ip' => '127.0.0.1',
    ]);
    assert_eq(200, $access['status']);
    $acc = json_decode($access['body'], true);
    assert_eq('allow', $acc['decision']);
    assert_eq('force', $acc['route']['cache']);
    assert_eq(30, $acc['route']['ttl']);

    $filter = Contract::handle([
        'phase' => 'filter', 'id' => 'req-1', 'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'bytes' => 123, 'duration_ms' => 5, 'upstream' => '10.0.0.1:9000',
        'body' => '{"ok":true}',
    ]);
    $f = json_decode($filter['body'], true);
    assert_eq(true, $f['recorded']);
    assert_eq('public, max-age=30', $f['headers']['Cache-Control']);
}

function test_contract_deny_auth(): void
{
    $appId = make_app();
    insert_rule($appId, 'POST /secure/*', '*', 'auth', ['type' => 'jwt']);
    $resp = Contract::handle([
        'phase' => 'access', 'id' => 'req-2', 'method' => 'POST',
        'uri' => '/v1/secure/x', 'headers' => [], 'client_ip' => '127.0.0.1',
    ]);
    $body = json_decode($resp['body'], true);
    assert_eq('deny', $body['decision']);
    assert_eq(401, $body['deny']['status']);
}

function test_contract_default_deny_unregistered(): void
{
    $appId = make_app('strict', '/s');
    App::update($appId, [
        'name' => 'strict', 'base_path' => '/s', 'match_mode' => 'prefix', 'match_value' => '',
        'default_allow' => 0, 'default_cache_enabled' => 0, 'default_cache_ttl' => 60,
        'auto_add_rules' => 1, 'status' => 1,
    ]);
    $resp = Contract::handle([
        'phase' => 'access', 'id' => 'req-3', 'method' => 'GET',
        'uri' => '/s/new', 'headers' => [], 'client_ip' => '127.0.0.1',
    ]);
    $body = json_decode($resp['body'], true);
    assert_eq('deny', $body['decision']);
    assert_eq(404, $body['deny']['status']);
}

function test_contract_auto_add_import(): void
{
    $appId = make_app('auto', '/v1');
    $resp = Contract::handle([
        'phase' => 'access', 'id' => 'req-auto-1', 'method' => 'GET',
        'uri' => '/v1/new/123', 'headers' => [], 'client_ip' => '127.0.0.1',
    ]);
    assert_eq('allow', json_decode($resp['body'], true)['decision']);

    Contract::handle([
        'phase' => 'filter', 'id' => 'req-auto-1', 'status' => 200,
        'headers' => [], 'bytes' => 10, 'duration_ms' => 1, 'upstream' => 'u', 'body' => '{}',
    ]);
    Importer::run();

    $stmt = Db::pdo()->prepare('SELECT * FROM apis WHERE app_id = ? AND method = ? AND template = ?');
    $stmt->execute([$appId, 'GET', '/new/{id}']);
    assert_true($stmt->fetch() !== false, 'auto added api should be registered by importer');
}

function test_contract_response_transform(): void
{
    $appId = make_app();
    insert_rule($appId, 'GET /order/{id}', '*', 'response_transform', [
        'op' => 'replace', 'path' => 'data.status', 'value' => 'ok',
    ], 0, 'app', null, 'filter');

    Contract::handle([
        'phase' => 'access', 'id' => 'rt-1', 'method' => 'GET',
        'uri' => '/v1/order/1', 'headers' => [], 'client_ip' => '127.0.0.1',
    ]);
    $filter = Contract::handle([
        'phase' => 'filter', 'id' => 'rt-1', 'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'bytes' => 30, 'duration_ms' => 2, 'upstream' => 'u',
        'body' => '{"data":{"status":"pending"}}',
    ]);
    $f = json_decode($filter['body'], true);
    $out = json_decode($f['body'], true);
    assert_eq('ok', $out['data']['status']);
}
