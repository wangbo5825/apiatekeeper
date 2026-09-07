<?php

declare(strict_types=1);

use Apigate\Normalizer;

function test_normalize_path(): void
{
    assert_eq('/api/users/{id}', Normalizer::path('/api/users/123'));
    assert_eq('/api/users/{id}', Normalizer::path('/api/users/550e8400-e29b-41d4-a716-446655440000'));
    assert_eq('/api/orders/{id}', Normalizer::path('/api/orders/2026-08-22'));
    assert_eq('/api/static', Normalizer::path('/api/static'));
    assert_eq('/', Normalizer::path('/'));
}

function test_template_matches(): void
{
    assert_true(Normalizer::templateMatches('/api/users/{id}', '/api/users/123'));
    assert_true(Normalizer::templateMatches('/api/users/{id}', '/api/users/abc'));
    assert_true(Normalizer::templateMatches('/api/*', '/api/anything/here'));
    assert_true(!Normalizer::templateMatches('/api/users/{id}', '/api/orders/123'));
    assert_true(!Normalizer::templateMatches('/api/users', '/api/users/1'));
}

function test_specificity(): void
{
    assert_true(Normalizer::specificity('/api/users/{id}') > Normalizer::specificity('/api/*'));
    assert_true(Normalizer::specificity('/api/users/list') > Normalizer::specificity('/api/users/{id}'));
}
