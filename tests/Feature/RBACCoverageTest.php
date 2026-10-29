<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenantA = Tenant::firstOrCreate(
        ['name' => 'tenant_rbac_a'],
        [
            'legal_name' => 'RBAC Holding Alpha Srl',
            'fiscal_code' => 'IT11122233344',
            'vat_number' => 'IT11122233344',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );

    $this->tenantB = Tenant::firstOrCreate(
        ['name' => 'tenant_rbac_b'],
        [
            'legal_name' => 'RBAC Subsidiary Beta Srl',
            'fiscal_code' => 'IT44433322211',
            'vat_number' => 'IT44433322211',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );

    $this->user = User::create([
        'name' => 'Multi Tenant Executive',
        'email' => 'executive_'.Str::random(8).'@enterprise-rbac.test',
        'password' => bcrypt('password'),
    ]);
});

test('RBAC: binds users to distinct roles across separate tenants with tenant-level isolation', function () {
    // User is Admin in Tenant A
    $this->user->tenants()->attach($this->tenantA->id, [
        'id' => (string) Str::uuid7(),
        'role' => 'admin',
        'permissions' => json_encode(['*']),
    ]);

    // User is restricted to 'sales_manager' in Tenant B
    $this->user->tenants()->attach($this->tenantB->id, [
        'id' => (string) Str::uuid7(),
        'role' => 'sales_manager',
        'permissions' => json_encode(['sales.*', 'crm.*']),
    ]);

    expect($this->user->belongsToTenant($this->tenantA->id))->toBeTrue()
        ->and($this->user->belongsToTenant($this->tenantB->id))->toBeTrue()
        ->and($this->user->getRoleInTenant($this->tenantA->id))->toBe('admin')
        ->and($this->user->getRoleInTenant($this->tenantB->id))->toBe('sales_manager');

    $unrelatedTenantId = (string) Str::uuid7();
    expect($this->user->belongsToTenant($unrelatedTenantId))->toBeFalse()
        ->and($this->user->getRoleInTenant($unrelatedTenantId))->toBeNull();
});

test('RBAC: Enterprise reference lookup catalog contains standardized role definitions with multilingual translations', function () {
    (new RoleSeeder)->run();

    $category = LookupCategory::where('code', 'enterprise_role')->first();

    expect($category)->not->toBeNull()
        ->and($category->is_system)->toBeTrue();

    $adminRole = LookupItem::where('category_id', $category->id)
        ->where('code', 'admin')
        ->first();

    expect($adminRole)->not->toBeNull();

    $translations = $adminRole->translations()->get()->keyBy('locale');

    expect($translations)->toHaveKey('it')
        ->and($translations)->toHaveKey('en')
        ->and($translations)->toHaveKey('de')
        ->and($translations)->toHaveKey('fr')
        ->and($translations['it']->label)->toBe('Amministratore di Sistema')
        ->and($translations['en']->label)->toBe('System Administrator');
});
