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

    expect($hasAccounting)->toBeTrue('OpenAPI schema missing accounting endpoints')
        ->and($hasBanking)->toBeTrue('OpenAPI schema missing banking endpoints')
        ->and($hasCrm)->toBeTrue('OpenAPI schema missing CRM endpoints')
        ->and($hasDashboard)->toBeTrue('OpenAPI schema missing dashboard endpoints')
        ->and($hasAuth)->toBeTrue('OpenAPI schema missing auth endpoints');
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
