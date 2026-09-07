<?php

declare(strict_types=1);

use Apigate\Db;
use Apigate\Upstream;

function make_group(int $appId, string $name, string $strategy = 'round_robin', string $pathMode = 'strip'): int
{
    Db::pdo()->prepare(
        'INSERT INTO upstream_groups (app_id, name, strategy, path_mode, check_path)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$appId, $name, $strategy, $pathMode, '/healthz']);
    return (int) Db::pdo()->lastInsertId();
}

function add_target(int $groupId, string $address, string $role = 'round_robin'): int
{
    Db::pdo()->prepare(
        'INSERT INTO upstream_targets (group_id, address, role) VALUES (?, ?, ?)'
    )->execute([$groupId, $address, $role]);
    return (int) Db::pdo()->lastInsertId();
}

function test_upstream_round_robin(): void
{
    $appId = make_app();
    $g = make_group($appId, 'default');
    add_target($g, 'http://10.0.0.1:9000');
    add_target($g, 'http://10.0.0.2:9000');

    $r1 = Upstream::resolve($appId);
    $r2 = Upstream::resolve($appId);
    assert_true($r1 !== null && $r2 !== null);
    assert_true($r1['address'] !== $r2['address'], '轮询应轮换目标');
    foreach ([$r1['address'], $r2['address']] as $a) {
        assert_true(in_array($a, ['10.0.0.1:9000', '10.0.0.2:9000'], true));
    }
}

function test_upstream_passive_health(): void
{
    $appId = make_app();
    $g = make_group($appId, 'default');
    $t1 = add_target($g, 'http://10.0.0.1:9000');
    add_target($g, 'http://10.0.0.2:9000');

    foreach ([1, 2, 3] as $i) {
        Upstream::markResult($appId, $g, $t1, false, 3, 2);
    }
    $seen = [];
    for ($i = 0; $i < 4; $i++) {
        $seen[Upstream::resolve($appId)['address']] = true;
    }
    assert_true(!isset($seen['10.0.0.1:9000']), '不健康目标不应被选中');
    assert_true(isset($seen['10.0.0.2:9000']));

    // 连续 2 次成功恢复
    Upstream::markResult($appId, $g, $t1, true, 3, 2);
    Upstream::markResult($appId, $g, $t1, true, 3, 2);
    $recovered = false;
    for ($i = 0; $i < 6; $i++) {
        if (Upstream::resolve($appId)['address'] === '10.0.0.1:9000') {
            $recovered = true;
            break;
        }
    }
    assert_true($recovered, '恢复后应重新参与选择');
}

function test_upstream_active_standby(): void
{
    $appId = make_app();
    $g = make_group($appId, 'db', 'active_standby');
    $active = add_target($g, 'http://10.0.0.1:9000', 'active');
    add_target($g, 'http://10.0.0.2:9000', 'standby');

    assert_eq('10.0.0.1:9000', Upstream::resolve($appId)['address']);
    foreach ([1, 2, 3] as $i) {
        Upstream::markResult($appId, $g, $active, false, 3, 2);
    }
    assert_eq('10.0.0.2:9000', Upstream::resolve($appId)['address'], '主不健康应切到备');
}

function test_upstream_map_path(): void
{
    $appId = make_app('a', '/v1');
    $g = make_group($appId, 'default', 'round_robin', 'strip');
    $row = Db::pdo()->query('SELECT * FROM upstream_groups WHERE id = ' . $g)->fetch();
    assert_true($row !== false);

    assert_eq('/orders/1', Upstream::mapPath($row, '/v1', '/v1/orders/1'));

    Db::pdo()->prepare(
        'UPDATE upstream_groups SET path_mode = ?, prefix_from = ?, prefix_to = ? WHERE id = ?'
    )->execute(['replace', '/v1', '/api/v1', $g]);
    $row2 = Db::pdo()->query('SELECT * FROM upstream_groups WHERE id = ' . $g)->fetch();
    assert_eq('/api/v1/orders/1', Upstream::mapPath($row2, '/v1', '/v1/orders/1'));
    // 规则转写优先于缺省映射
    assert_eq('/orders/1', Upstream::mapPath($row2, '/v1', '/v1/orders/1', 'strip:base'));
    assert_eq('/x/orders/1', Upstream::mapPath($row2, '/v1', '/v1/orders/1', 'replace:/v1:/x'));
    // add 模式
    Db::pdo()->prepare('UPDATE upstream_groups SET path_mode = ?, prefix_from = ?, prefix_to = ? WHERE id = ?')
        ->execute(['add', '', '/gw', $g]);
    $row3 = Db::pdo()->query('SELECT * FROM upstream_groups WHERE id = ' . $g)->fetch();
    assert_eq('/gw/v1/orders/1', Upstream::mapPath($row3, '/v1', '/v1/orders/1'));
}

function test_upstream_backend_by_name(): void
{
    $appId = make_app();
    $g1 = make_group($appId, 'A');
    add_target($g1, 'http://10.0.0.1:9000');
    $g2 = make_group($appId, 'B');
    add_target($g2, 'http://10.0.0.2:9000');

    assert_eq('10.0.0.2:9000', Upstream::resolve($appId, 'B')['address']);
    assert_eq('10.0.0.1:9000', Upstream::resolve($appId, 'A')['address']);
}
