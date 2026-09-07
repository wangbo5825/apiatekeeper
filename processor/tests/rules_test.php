<?php

declare(strict_types=1);

use Apigate\Blacklist;
use Apigate\RateLimit;

function test_rate_limit_window(): void
{
    $appId = make_app();
    $rule = ['id' => 1, 'app_id' => $appId, 'action_type' => 'rate_limit'];
    $params = ['window' => 60, 'limit' => 2, 'keys' => ['req.ip']];
    $ctx = make_ctx($appId, 'GET', '/v1/api', '1.2.3.4');

    assert_eq(true, RateLimit::check($rule, $ctx, $params)['allowed']);
    assert_eq(true, RateLimit::check($rule, $ctx, $params)['allowed']);
    $third = RateLimit::check($rule, $ctx, $params);
    assert_eq(false, $third['allowed']);
    assert_eq(true, $third['exceeded_once']);
}

function test_rate_limit_param_key(): void
{
    $appId = make_app();
    $rule = ['id' => 2, 'app_id' => $appId, 'action_type' => 'rate_limit'];
    $params = ['window' => 60, 'limit' => 1, 'keys' => ['req.query:code']];

    $a = make_ctx($appId, 'GET', '/v1/api?code=a', '1.2.3.4');
    assert_eq(true, RateLimit::check($rule, $a, $params)['allowed']);
    assert_eq(false, RateLimit::check($rule, $a, $params)['allowed']);

    // 不同参数值不受影响
    $b = make_ctx($appId, 'GET', '/v1/api?code=b', '1.2.3.4');
    assert_eq(true, RateLimit::check($rule, $b, $params)['allowed']);
}

function test_blacklist_hit(): void
{
    $appId = make_app();
    Blacklist::add($appId, 'req.ip', '9.9.9.9', 'test');
    $ctx = make_ctx($appId, 'GET', '/v1/api', '9.9.9.9');
    assert_eq(true, Blacklist::hits(['dimensions' => ['req.ip']], $ctx));
    $ctx2 = make_ctx($appId, 'GET', '/v1/api', '1.1.1.1');
    assert_eq(false, Blacklist::hits(['dimensions' => ['req.ip']], $ctx2));
}

function test_blacklist_auto_memory_immediate(): void
{
    $appId = make_app();
    $ctx = make_ctx($appId, 'GET', '/v1/api', '8.8.8.8');
    Blacklist::addAuto($ctx, 3600);
    // 不依赖 DB 落库，APCu 快照立即生效
    assert_eq(true, Blacklist::hits(['dimensions' => ['req.ip']], $ctx));
}
