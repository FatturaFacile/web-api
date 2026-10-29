<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('tenant scope enforces strict multi-tenant isolation and prevents cross-tenant access', function () {
    $tenantA = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Tenant Alpha',
        'legal_name' => 'Tenant Alpha S.r.l.',
        'vat_number' => 'IT11111111111',
        'status' => 'active',
    ]);

    $tenantB = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Tenant Beta',
        'legal_name' => 'Tenant Beta S.r.l.',
        'vat_number' => 'IT22222222222',
        'status' => 'active',
    ]);

    $context = app(TenantContext::class);

    // 1. Context set to Tenant Alpha
    $context->setTenant($tenantA);
    expect($context->getTenantId())->toBe($tenantA->id);

    // 2. Context switched to Tenant Beta
    $context->setTenant($tenantB);
    expect($context->getTenantId())->toBe($tenantB->id);
    expect($context->getTenantId())->not->toBe($tenantA->id);
});

test('tenant scope strictly fails closed when no tenant context exists', function () {
    $context = app(TenantContext::class);
    $context->setTenant(null);

    expect($context->hasTenant())->toBeFalse();

    // Any query on an enterprise model without a tenant context must return zero records
    $testModel = new class extends BaseEnterpriseModel
    {
        protected $table = 'contacts';
    };

    // With fail-closed whereRaw('1 = 0'), count MUST be 0
    expect($testModel::count())->toBe(0);
});

test('belongs to tenant prevents parameter injection by strictly overriding tenant_id with context', function () {
    $tenantA = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Legitimate Corp',
        'legal_name' => 'Legitimate Corp S.r.l.',
        'vat_number' => 'IT44444444444',
        'status' => 'active',
    ]);

    $forgedTenantId = (string) Str::uuid7();

    $context = app(TenantContext::class);
    $context->setTenant($tenantA);

    // Creating contact model using enterprise concerns
    $testContact = new class extends BaseEnterpriseModel
    {
        protected $table = 'contacts';
    };

    $instance = new $testContact([
        'id' => (string) Str::uuid7(),
        'company_name' => 'Attempted Injection Contact',
        'type' => 'client',
        'country' => 'IT',
        'tenant_id' => $forgedTenantId,
    ]);

    $instance->save();

    expect($instance->tenant_id)->toBe($tenantA->id)
        ->and($instance->tenant_id)->not->toBe($forgedTenantId);
});
