<?php

declare(strict_types=1);

use Apigate\App;
use Apigate\Context;
use Apigate\Db;
use Apigate\RuleEngine;

function make_app(string $name = 'test-app', string $base = '/v1'): int
{
    return App::create([
        'name' => $name,
        'base_path' => $base,
        'match_mode' => 'prefix',
        'match_value' => '',
        'default_allow' => 1,
        'default_cache_enabled' => 0,
        'default_cache_ttl' => 60,
        'auto_add_rules' => 1,
    ]);
}

function insert_rule(
    int $appId,
    string $apiPattern,
    string $clientPattern,
    string $type,
    array $params,
    int $priority = 0,
    string $scope = 'app',
    ?int $apiId = null,
    string $phase = 'access'
): int {
    Db::pdo()->prepare(
        'INSERT INTO rules (app_id, scope, api_id, phase, name, enabled, priority,
                            api_pattern, client_pattern, action_type, params)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
    )->execute([
        $appId, $scope, $apiId, $phase, 'r-' . $apiPattern, $priority,
        $apiPattern, $clientPattern, $type, json_encode($params),
    ]);
    RuleEngine::bumpVersion($appId);
    return (int) Db::pdo()->lastInsertId();
}

function make_ctx(int $appId, string $method = 'GET', string $uri = '/v1/users/5', string $ip = '127.0.0.1', string $userid = '', string $apikey = ''): Context
{
    $app = App::findById($appId);
    if ($app === null) {
        throw new RuntimeException('app not found: ' . $appId);
    }
    return new Context($app, $method, $uri, [], $ip, $userid, $apikey);
}
