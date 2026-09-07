<?php

declare(strict_types=1);

use Apigate\AdminApi;

function admin_req(string $method, string $path, array $body = []): array
{
    return AdminApi::handle($method, $path, $body, ['x-admin-token' => 'test-token']);
}

function test_admin_auth(): void
{
    $resp = AdminApi::handle('GET', '/admin/api/health', [], ['x-admin-token' => 'wrong']);
    assert_eq(401, $resp['status']);
    $resp2 = admin_req('GET', '/admin/api/health');
    assert_eq(200, $resp2['status']);
}

function test_admin_apps_crud_and_conflict(): void
{
    $created = admin_req('POST', '/admin/api/apps', [
        'name' => '订单服务', 'base_path' => '/v1', 'match_mode' => 'prefix',
        'default_allow' => 1, 'auto_add_rules' => 1,
    ]);
    assert_eq(200, $created['status']);
    $appId = json_decode($created['body'], true)['id'];

    // 相同 base_path 冲突
    $dup = admin_req('POST', '/admin/api/apps', ['name' => 'x', 'base_path' => '/v1']);
    assert_eq(409, $dup['status']);

    $list = admin_req('GET', '/admin/api/apps');
    assert_contains('订单服务', $list['body']);

    $updated = admin_req('PUT', "/admin/api/apps/$appId", [
        'name' => '订单服务v2', 'base_path' => '/v1', 'match_mode' => 'prefix',
        'default_allow' => 0, 'auto_add_rules' => 1,
    ]);
    assert_eq(200, $updated['status']);

    $deleted = admin_req('DELETE', "/admin/api/apps/$appId");
    assert_eq(200, $deleted['status']);
}

function test_admin_app_apis_and_conflict(): void
{
    $appId = make_app();
    $a = admin_req('POST', "/admin/api/apps/$appId/apis", ['method' => 'GET', 'template' => '/orders/{id}', 'display_name' => '订单详情']);
    assert_eq(200, $a['status']);

    // 模板互相覆盖 → 409
    $conflict = admin_req('POST', "/admin/api/apps/$appId/apis", ['method' => 'GET', 'template' => '/orders/123']);
    assert_eq(409, $conflict['status']);

    // 不同方法不冲突
    $ok = admin_req('POST', "/admin/api/apps/$appId/apis", ['method' => 'POST', 'template' => '/orders']);
    assert_eq(200, $ok['status']);

    $list = admin_req('GET', "/admin/api/apps/$appId/apis");
    assert_contains('/orders/{id}', $list['body']);
}

function test_admin_app_rules_variables_dims(): void
{
    $appId = make_app();
    $rule = admin_req('POST', "/admin/api/apps/$appId/rules", [
        'name' => '创建订单限流',
        'api_pattern' => 'POST /orders',
        'client_pattern' => '*',
        'action_type' => 'rate_limit',
        'params' => ['window' => 60, 'limit' => 100, 'keys' => ['req.ip', 'req.userid']],
    ]);
    assert_eq(200, $rule['status']);
    assert_contains('创建订单限流', admin_req('GET', "/admin/api/apps/$appId/rules")['body']);

    $var = admin_req('POST', "/admin/api/apps/$appId/variables", [
        'name' => 'last_url', 'key_dim' => 'req.ip', 'source' => 'save_variable', 'ttl' => 3600,
    ]);
    assert_eq(200, $var['status']);
    assert_contains('last_url', admin_req('GET', "/admin/api/apps/$appId/variables")['body']);

    $dim = admin_req('POST', "/admin/api/apps/$appId/dimensions", [
        'name' => 'dim:user', 'chain' => ['req.userid', 'req.header:X-User-Id'],
    ]);
    assert_eq(200, $dim['status']);
    assert_contains('dim:user', admin_req('GET', "/admin/api/apps/$appId/dimensions")['body']);
}

function test_admin_app_blacklist_tokens_stats(): void
{
    $appId = make_app();
    $bl = admin_req('POST', "/admin/api/apps/$appId/blacklist", ['dimension' => 'req.ip', 'value' => '5.5.5.5']);
    assert_eq(200, $bl['status']);
    assert_contains('5.5.5.5', admin_req('GET', "/admin/api/apps/$appId/blacklist")['body']);

    $tok = admin_req('POST', "/admin/api/apps/$appId/tokens", ['value' => 'tok-abc', 'owner' => 'svc']);
    assert_eq(200, $tok['status']);
    assert_contains('tok-abc', admin_req('GET', "/admin/api/apps/$appId/tokens")['body']);

    assert_eq(200, admin_req('GET', "/admin/api/apps/$appId/clients")['status']);
    assert_eq(200, admin_req('POST', "/admin/api/apps/$appId/stats", ['bucket' => 'hour'])['status']);
}

function test_admin_app_upstream_targets(): void
{
    $appId = make_app();
    $g = admin_req('POST', "/admin/api/apps/$appId/upstreams", [
        'name' => '订单上游组', 'strategy' => 'round_robin', 'path_mode' => 'strip',
    ]);
    assert_eq(200, $g['status']);
    $gid = json_decode($g['body'], true)['id'];

    $t = admin_req('POST', "/admin/api/apps/$appId/upstreams/$gid/targets", [
        'address' => 'http://10.0.0.1:9000', 'role' => 'round_robin', 'weight' => 100,
    ]);
    assert_eq(200, $t['status']);

    $list = admin_req('GET', "/admin/api/apps/$appId/upstreams/$gid/targets");
    assert_contains('10.0.0.1:9000', $list['body']);

    $tid = json_decode($list['body'], true)[0]['id'];
    $del = admin_req('DELETE', "/admin/api/apps/$appId/upstreams/$gid/targets/$tid");
    assert_eq(200, $del['status']);
}
