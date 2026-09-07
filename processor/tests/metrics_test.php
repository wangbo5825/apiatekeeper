<?php

declare(strict_types=1);

use Apigate\Metrics;

function test_metrics_counters_render(): void
{
    $appId = make_app();
    Metrics::request($appId, 200);
    Metrics::request($appId, 500);
    Metrics::denied($appId, 'rate_limit');
    Metrics::autoAdded($appId);
    Metrics::latency($appId, 20);
    Metrics::latency($appId, 200);

    $out = Metrics::render();
    assert_contains('gatekeeper_http_requests_total{app="' . $appId . '"} 2', $out);
    assert_contains('gatekeeper_http_errors_total{app="' . $appId . '"} 1', $out);
    assert_contains('gatekeeper_denied_total{app="' . $appId . '",reason="rate_limit"} 1', $out);
    assert_contains('gatekeeper_auto_added_apis_total{app="' . $appId . '"} 1', $out);
    assert_contains('gatekeeper_http_duration_seconds_bucket{app="' . $appId . '",le="0.050"} 1', $out);
    assert_contains('gatekeeper_http_duration_seconds_bucket{app="' . $appId . '",le="0.500"} 2', $out);
    assert_contains('gatekeeper_log_import_lag_seconds', $out);
}

function test_metrics_admin_endpoint(): void
{
    $appId = make_app();
    Metrics::request($appId, 200);
    $resp = admin_req('GET', '/admin/api/metrics');
    assert_eq(200, $resp['status']);
    assert_contains('gatekeeper_http_requests_total', $resp['body']);
    assert_contains('text/plain', $resp['headers']['Content-Type']);
}
