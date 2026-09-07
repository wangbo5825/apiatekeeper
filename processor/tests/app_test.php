<?php

declare(strict_types=1);

use Apigate\App;

function test_app_match_prefix(): void
{
    $id = make_app('orders', '/v1');
    $app = App::find('/v1/orders/1', []);
    assert_true($app !== null, 'should match /v1 prefix');
    assert_eq($id, (int) $app['id']);
    assert_eq(null, App::find('/v2/x', []));
    assert_eq(null, App::find('/v1x', []), '/v1x 不应命中 /v1');
    assert_true(App::find('/v1', []) !== null, 'base 精确匹配');
}

function test_app_match_host(): void
{
    App::create([
        'name' => 'pay', 'base_path' => '/pay', 'match_mode' => 'host',
        'match_value' => 'pay.example.com',
        'default_allow' => 1, 'default_cache_enabled' => 0, 'default_cache_ttl' => 60, 'auto_add_rules' => 1,
    ]);
    assert_true(App::find('/pay/x', ['Host' => 'pay.example.com']) !== null);
    assert_eq(null, App::find('/pay/x', ['Host' => 'other.example.com']));
}

function test_app_relative_path(): void
{
    assert_eq('/orders/1', App::relativePath('/v1', '/v1/orders/1'));
    assert_eq('/', App::relativePath('/v1', '/v1'));
    assert_eq('/orders', App::relativePath('/', '/orders'));
}

function test_app_path_conflict(): void
{
    make_app('a', '/v1');
    assert_true(App::pathConflict(0, '/v1', 'prefix', '') !== null, '完全重叠应冲突');
    assert_true(App::pathConflict(0, '/v1/orders', 'prefix', '') !== null, '前缀包含应冲突');
    assert_eq(null, App::pathConflict(0, '/v1x', 'prefix', ''), '/v1x 与 /v1 不应冲突');
}

function test_app_default_config(): void
{
    $id = make_app();
    $app = App::findById($id);
    assert_true($app !== null);
    assert_true(App::defaultAllow($app));
    assert_true(App::autoAdd($app));
    assert_true(!App::cacheEnabled($app));
}
