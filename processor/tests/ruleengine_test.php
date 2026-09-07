<?php

declare(strict_types=1);

use Apigate\Cache;
use Apigate\Context;
use Apigate\Db;
use Apigate\Identity;
use Apigate\Importer;
use Apigate\RuleEngine;
use Apigate\Variables;

function test_rule_specificity_order(): void
{
    $appId = make_app();
    insert_rule($appId, 'GET /users/{id}', '*', 'cache', ['mode' => 'force', 'ttl' => 10]);
    insert_rule($appId, 'GET /users/*', '*', 'cache', ['mode' => 'no-cache']);
    insert_rule($appId, 'GET /users/list', '*', 'cache', ['mode' => 'auto']);

    $id = new Identity('127.0.0.1');
    $matched = RuleEngine::match($appId, 'GET /users/5', $id);
    assert_eq(2, count($matched));
    assert_eq('GET /users/{id}', $matched[0]['api_pattern']);

    $res = RuleEngine::evaluate($matched, make_ctx($appId), $id);
    assert_eq('force', $res['context']['cache_policy']);
    assert_eq(10, $res['route']['ttl']);
}

function test_rule_client_pattern(): void
{
    $appId = make_app();
    insert_rule($appId, 'GET /private/*', 'ip:127.0.0.1', 'cache', ['mode' => 'no-cache']);
    assert_eq(1, count(RuleEngine::match($appId, 'GET /private/x', new Identity('127.0.0.1'))));
    assert_eq(0, count(RuleEngine::match($appId, 'GET /private/x', new Identity('10.1.2.3'))));
}

function test_rule_scope_api(): void
{
    $appId = make_app();
    $apiId = Importer::registerApi($appId, 'GET', '/orders/{id}');
    insert_rule($appId, '*', '*', 'auth', ['type' => 'jwt'], 0, 'api', $apiId);
    $id = new Identity('127.0.0.1');
    assert_eq(1, count(RuleEngine::match($appId, 'GET /orders/5', $id, 'access', $apiId)));
    assert_eq(0, count(RuleEngine::match($appId, 'GET /orders/5', $id)));
}

function test_rule_cache_bump(): void
{
    $appId = make_app();
    $v1 = RuleEngine::version($appId);
    insert_rule($appId, 'GET /x/*', '*', 'cache', ['mode' => 'auto']);
    assert_true(RuleEngine::version($appId) > $v1);
    Cache::clearAll();
    assert_eq(1, count(RuleEngine::match($appId, 'GET /x/y', new Identity('127.0.0.1'))));
}

function test_rule_rate_limit_dim(): void
{
    $appId = make_app();
    insert_rule($appId, 'POST /orders', '*', 'rate_limit', [
        'window' => 60, 'limit' => 2, 'burst' => 0, 'keys' => ['req.ip', 'req.userid'],
    ]);
    $id = new Identity('127.0.0.1');
    $ctx = make_ctx($appId, 'POST', '/v1/orders', '127.0.0.1', 'u-1');
    $matched = RuleEngine::match($appId, 'POST /orders', $id);
    assert_eq(null, RuleEngine::evaluate($matched, $ctx, $id)['deny']);
    assert_eq(null, RuleEngine::evaluate($matched, $ctx, $id)['deny']);
    $deny = RuleEngine::evaluate($matched, $ctx, $id)['deny'];
    assert_eq(429, $deny['status']);

    // 不同 userid 计数隔离
    $ctx2 = make_ctx($appId, 'POST', '/v1/orders', '127.0.0.1', 'u-2');
    assert_eq(null, RuleEngine::evaluate($matched, $ctx2, new Identity('127.0.0.1'))['deny']);
}

function test_rule_variable_save_and_check(): void
{
    $appId = make_app();
    Variables::create($appId, 'code', 'req.ip', 'save_variable', 300);
    insert_rule($appId, 'GET /ticket', '*', 'save_variable', ['name' => 'code', 'from' => 'req.query:code']);
    insert_rule($appId, 'GET /ticket/{id}', '*', 'variable_check', ['name' => 'code']);

    $id = new Identity('127.0.0.1');
    $capture = RuleEngine::match($appId, 'GET /ticket', $id);
    RuleEngine::evaluate($capture, make_ctx($appId, 'GET', '/v1/ticket?code=abc123', '127.0.0.1'), $id);

    $check = RuleEngine::match($appId, 'GET /ticket/9', $id);
    $ok = RuleEngine::evaluate($check, make_ctx($appId, 'GET', '/v1/ticket/9', '127.0.0.1'), $id);
    assert_eq(null, $ok['deny']);

    $deny = RuleEngine::evaluate($check, make_ctx($appId, 'GET', '/v1/ticket/9', '10.9.9.9'), new Identity('10.9.9.9'));
    assert_eq(403, $deny['deny']['status']);
}

function test_rule_stats_target(): void
{
    $appId = make_app();
    Variables::create($appId, 'visit_count', 'req.ip', 'stats', 86400);
    insert_rule($appId, '*', '*', 'stats', ['dimensions' => ['req.ip'], 'target' => 'visit_count']);
    $id = new Identity('127.0.0.1');
    $matched = RuleEngine::match($appId, 'GET /anything', $id);
    $ctx = make_ctx($appId, 'GET', '/v1/anything', '127.0.0.1');
    RuleEngine::evaluate($matched, $ctx, $id);
    assert_eq('1', $ctx->getVariable('visit_count'));
}

function test_rule_change_target(): void
{
    $appId = make_app();
    insert_rule($appId, 'GET /export/*', '*', 'change_target', [
        'upstream' => '订单备用组', 'strip_prefix' => 1, 'set_headers' => ['X-App' => 'orders'],
    ]);
    $id = new Identity('127.0.0.1');
    $res = RuleEngine::evaluate(
        RuleEngine::match($appId, 'GET /export/x', $id),
        make_ctx($appId, 'GET', '/v1/export/x'),
        $id
    );
    assert_eq('订单备用组', $res['route']['backend']);
    assert_eq(1, $res['route']['strip_prefix']);
    assert_eq('orders', $res['rewrite']['headers']['X-App']);
}
