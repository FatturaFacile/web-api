<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('OpenAPI: Specification endpoint serves valid OpenAPI schema covering enterprise domains', function () {
    $response = $this->get('/docs/api-docs.json');

    $response->assertStatus(200);

    $content = file_get_contents(storage_path('api-docs/api-docs.json'));
    $json = json_decode($content, true);

    expect($json)->toHaveKey('openapi')
        ->and($json['openapi'])->toStartWith('3.0')
        ->and($json)->toHaveKey('info')
        ->and($json)->toHaveKey('paths')
        ->and($json)->toHaveKey('components');

    $paths = array_keys($json['paths']);

    // Check coverage across core functional domains in the OpenAPI schema
    $hasAccounting = collect($paths)->some(fn ($p) => str_contains($p, 'accounting'));
    $hasBanking = collect($paths)->some(fn ($p) => str_contains($p, 'banking'));
    $hasCrm = collect($paths)->some(fn ($p) => str_contains($p, 'crm'));
    $hasDashboard = collect($paths)->some(fn ($p) => str_contains($p, 'dashboard'));
    $hasAuth = collect($paths)->some(fn ($p) => str_contains($p, 'auth'));
    $hasSales = collect($paths)->some(fn ($p) => str_contains($p, 'sales'));
    $hasPurchases = collect($paths)->some(fn ($p) => str_contains($p, 'purchases'));
    $hasInventory = collect($paths)->some(fn ($p) => str_contains($p, 'inventory'));
    $hasFieldService = collect($paths)->some(fn ($p) => str_contains($p, 'field-service'));
    $hasServiceReports = collect($paths)->some(fn ($p) => str_contains($p, 'service-reports'));
    $hasAppointments = collect($paths)->some(fn ($p) => str_contains($p, 'appointments'));
    $hasSubscriptions = collect($paths)->some(fn ($p) => str_contains($p, 'subscriptions'));
    $hasLoyalty = collect($paths)->some(fn ($p) => str_contains($p, 'loyalty-cards'));
    $hasEcommerce = collect($paths)->some(fn ($p) => str_contains($p, 'ecommerce'));
    $hasPos = collect($paths)->some(fn ($p) => str_contains($p, 'pos'));
    $hasHr = collect($paths)->some(fn ($p) => str_contains($p, 'hr'));

    expect($hasAccounting)->toBeTrue('OpenAPI schema missing accounting endpoints')
        ->and($hasBanking)->toBeTrue('OpenAPI schema missing banking endpoints')
        ->and($hasCrm)->toBeTrue('OpenAPI schema missing CRM endpoints')
        ->and($hasDashboard)->toBeTrue('OpenAPI schema missing dashboard endpoints')
        ->and($hasAuth)->toBeTrue('OpenAPI schema missing auth endpoints')
        ->and($hasSales)->toBeTrue('OpenAPI schema missing sales endpoints')
        ->and($hasPurchases)->toBeTrue('OpenAPI schema missing purchases endpoints')
        ->and($hasInventory)->toBeTrue('OpenAPI schema missing inventory endpoints')
        ->and($hasFieldService)->toBeTrue('OpenAPI schema missing field-service endpoints')
        ->and($hasServiceReports)->toBeTrue('OpenAPI schema missing service-reports endpoints')
        ->and($hasAppointments)->toBeTrue('OpenAPI schema missing appointments endpoints')
        ->and($hasSubscriptions)->toBeTrue('OpenAPI schema missing subscriptions endpoints')
        ->and($hasLoyalty)->toBeTrue('OpenAPI schema missing loyalty-cards endpoints')
        ->and($hasEcommerce)->toBeTrue('OpenAPI schema missing ecommerce endpoints')
        ->and($hasPos)->toBeTrue('OpenAPI schema missing pos endpoints')
        ->and($hasHr)->toBeTrue('OpenAPI schema missing hr endpoints');
});

test('OpenAPI: Specification includes standard components, schemas and security definitions', function () {
    $response = $this->get('/docs/api-docs.json');

    $response->assertStatus(200);

    $content = file_get_contents(storage_path('api-docs/api-docs.json'));
    $json = json_decode($content, true);
    $components = $json['components'];

    expect($components)->toHaveKey('schemas')
        ->and($components['schemas'])->toHaveKey('ApiSuccessResponse')
        ->and($json['info'])->toHaveKey('title')
        ->and($json['info']['title'])->toBe('FatturaFacile API');
});
