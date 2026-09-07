<?php

declare(strict_types=1);

use Apigate\ResponseTransformer;
use Apigate\Variables;

function test_response_replace(): void
{
    $appId = make_app();
    [$body, $ct] = ResponseTransformer::apply(
        ['op' => 'replace', 'path' => 'data.status', 'value' => 'ok'],
        '{"data":{"status":"pending"}}',
        'application/json',
        make_ctx($appId)
    );
    assert_eq('ok', json_decode($body, true)['data']['status']);
    assert_eq('application/json', $ct);
}

function test_response_replace_with_var(): void
{
    $appId = make_app();
    $ctx = make_ctx($appId, 'GET', '/v1/x', '10.1.1.1');
    [$body] = ResponseTransformer::apply(
        ['op' => 'replace', 'path' => 'client_ip', 'value' => 'req.ip'],
        '{"client_ip":""}',
        'application/json',
        $ctx
    );
    assert_eq('10.1.1.1', json_decode($body, true)['client_ip']);
}

function test_response_save_variable(): void
{
    $appId = make_app();
    Variables::create($appId, 'last_token', 'req.userid', 'response_transform', 3600);
    $ctx = make_ctx($appId, 'POST', '/v1/login', '1.1.1.1', 'u-1');
    ResponseTransformer::apply(
        ['op' => 'save_variable', 'path' => 'data.token', 'target' => 'last_token'],
        '{"data":{"token":"tok-1"}}',
        'application/json',
        $ctx
    );
    assert_eq('tok-1', $ctx->getVariable('last_token'));
}

function test_response_convert_json_to_xml(): void
{
    [$body, $ct] = ResponseTransformer::apply(
        ['op' => 'convert', 'format' => 'json_to_xml', 'root' => 'response'],
        '{"code":200}',
        'application/json',
        make_ctx(make_app())
    );
    assert_eq('application/xml', $ct);
    assert_contains('<response>', $body);
    assert_contains('<code>200</code>', $body);
}

function test_response_convert_xml_to_json(): void
{
    [$body, $ct] = ResponseTransformer::apply(
        ['op' => 'convert', 'format' => 'xml_to_json'],
        '<?xml version="1.0"?><response><code>200</code></response>',
        'application/xml',
        make_ctx(make_app())
    );
    assert_eq('application/json', $ct);
    $data = json_decode($body, true);
    assert_true(is_array($data));
}

function test_response_invalid_json_passthrough(): void
{
    [$body, $ct] = ResponseTransformer::apply(
        ['op' => 'replace', 'path' => 'a', 'value' => 'b'],
        'not-json',
        'text/plain',
        make_ctx(make_app())
    );
    assert_eq('not-json', $body);
    assert_eq('text/plain', $ct);
}
