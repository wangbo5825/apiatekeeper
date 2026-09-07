<?php

declare(strict_types=1);

use Apigate\Dimensions;
use Apigate\Variables;

function test_variable_declare_and_set_get(): void
{
    $appId = make_app();
    Variables::create($appId, 'last_url', 'req.ip', 'save_variable', 3600);
    $ctx = make_ctx($appId, 'GET', '/v1/orders/1', '10.1.1.1');
    $ctx->setVariable('last_url', '/orders/1');
    assert_eq('/orders/1', $ctx->getVariable('last_url'));

    // 不同 key（不同 IP）隔离
    $ctx2 = make_ctx($appId, 'GET', '/v1/orders/1', '10.1.1.2');
    assert_eq(null, $ctx2->getVariable('last_url'));
}

function test_dimension_chain_resolution(): void
{
    $appId = make_app();
    Dimensions::create($appId, 'dim:user', ['req.userid', 'req.header:X-User-Id', 'req.query:uid']);

    $ctx = make_ctx($appId, 'GET', '/v1/x', '127.0.0.1');
    $ctx->headers['x-user-id'] = 'u-9';
    assert_eq('u-9', $ctx->resolve('dim:user'));

    $ctx2 = make_ctx($appId, 'GET', '/v1/x?uid=u-8', '127.0.0.1');
    assert_eq('u-8', $ctx2->resolve('dim:user'));

    $ctx3 = make_ctx($appId, 'GET', '/v1/x', '127.0.0.1');
    assert_eq(null, $ctx3->resolve('dim:user'));
}

function test_variable_keyed_by_semantic_dim(): void
{
    $appId = make_app();
    Dimensions::create($appId, 'dim:user', ['req.userid']);
    Variables::create($appId, 'last_ip', 'dim:user', 'save_variable', 3600);

    $ctx = make_ctx($appId, 'GET', '/v1/x', '10.1.2.3', 'u-1');
    $ctx->setVariable('last_ip', '10.1.2.3');

    // 同一 userid 不同 IP 也能读到
    $ctx2 = make_ctx($appId, 'GET', '/v1/x', '9.9.9.9', 'u-1');
    assert_eq('10.1.2.3', $ctx2->getVariable('last_ip'));
}

function test_variable_missing_key_returns_null(): void
{
    $appId = make_app();
    Variables::create($appId, 'v1', 'req.userid', 'save_variable', 3600);
    $ctx = make_ctx($appId, 'GET', '/v1/x', '1.1.1.1'); // 无 userid → key 缺失
    assert_eq(null, $ctx->getVariable('v1'));
}
