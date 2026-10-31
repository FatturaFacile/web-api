<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\AssetDemoDataSeeder;
use Database\Seeders\AssetReferenceSeeder;
use Database\Seeders\AssetTestingSeeder;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\Asset;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'EAM API & Seeder Tenant', 'legal_name' => 'EAM API & Seeder Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'eam.api@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'EAM API Operator', 'password' => bcrypt('secret'), 'current_tenant_id' => $this->tenant->id]
    );
});

test('all 9 FixedAssetFactory states generate valid domain models', function (): void {
    $cat = AssetCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $baseAttrs = ['tenant_id' => $this->tenant->id, 'category_id' => $cat->id];

    expect(FixedAsset::factory()->draft()->create($baseAttrs)->status)->toBe('draft')
        ->and(FixedAsset::factory()->capitalized()->create($baseAttrs)->status)->toBe('capitalized')
        ->and(FixedAsset::factory()->active()->create($baseAttrs)->status)->toBe('active')
        ->and(FixedAsset::factory()->maintenance()->create($baseAttrs)->status)->toBe('under_maintenance')
        ->and(FixedAsset::factory()->revalued()->create($baseAttrs)->status)->toBe('revalued')
        ->and(FixedAsset::factory()->impaired()->create($baseAttrs)->status)->toBe('impaired')
        ->and(FixedAsset::factory()->disposed()->create($baseAttrs)->status)->toBe('disposed')
        ->and(FixedAsset::factory()->sold()->create($baseAttrs)->status)->toBe('sold')
        ->and(FixedAsset::factory()->retired()->create($baseAttrs)->status)->toBe('retired')
        ->and(Asset::factory()->active()->create($baseAttrs)->status)->toBe('active');
});

test('idempotent multilingual seeders execute repeatedly without duplication', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);

    $this->seed(AssetReferenceSeeder::class);
    $this->seed(AssetTestingSeeder::class);
    $this->seed(AssetDemoDataSeeder::class);

    app(TenantContext::class)->setTenant($this->tenant);

    $countAfterFirst = FixedAsset::where('tenant_id', $this->tenant->id)->count();
    expect($countAfterFirst)->toBeGreaterThanOrEqual(6);

    // Run a second time to verify 100% idempotency
    $this->seed(AssetReferenceSeeder::class);
    $this->seed(AssetTestingSeeder::class);
    $this->seed(AssetDemoDataSeeder::class);

    app(TenantContext::class)->setTenant($this->tenant);

    $countAfterSecond = FixedAsset::where('tenant_id', $this->tenant->id)->count();
    expect($countAfterSecond)->toBe($countAfterFirst);
});

test('enforces authentication and executes full asset lifecycle via REST API', function (): void {
    // Unauthenticated check
    $unauthResponse = $this->getJson('/api/v1/eam/assets');
    $unauthResponse->assertStatus(401);

    $this->actingAs($this->user);

    // 1. Create Category via API
    $catResponse = $this->postJson('/api/v1/eam/categories', [
        'code' => 'CAT-API-SERVERS',
        'name' => 'Enterprise AI Servers',
        'asset_type' => 'tangible',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 4,
    ]);
    $catResponse->assertStatus(201);
    $categoryId = $catResponse->json('data.id');

    // 2. Register Asset via API
    $storeResponse = $this->postJson('/api/v1/eam/assets', [
        'name' => 'HPE ProLiant DL380 Gen11',
        'category_id' => $categoryId,
        'acquisition_cost' => 24000.00,
        'salvage_value' => 0.00,
        'useful_life_years' => 4,
    ]);
    $storeResponse->assertStatus(201);
    $assetId = $storeResponse->json('data.id');

    // 3. Capitalize Asset via API
    $capResponse = $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", [
        'capitalized_cost' => 24000.00,
        'salvage_value' => 0.00,
    ]);
    $capResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'capitalized');

    // 4. Execute Depreciation Run via API
    $deprResponse = $this->postJson('/api/v1/eam/depreciation/runs', [
        'period_code' => '2026-10',
        'period_type' => 'monthly',
        'ledger_type' => 'statutory',
        'single_asset_id' => $assetId,
    ]);
    $deprResponse->assertStatus(201)
        ->assertJsonPath('data.total_depreciation_amount', 500);

    // 5. Create & Complete Maintenance Work Order via API
    $woResponse = $this->postJson('/api/v1/eam/maintenance/work-orders', [
        'asset_id' => $assetId,
        'maintenance_type' => 'preventive',
        'priority' => 'medium',
        'estimated_cost' => 300.00,
    ]);
    $woResponse->assertStatus(201);
    $woId = $woResponse->json('data.id');

    $completeWoResponse = $this->postJson("/api/v1/eam/maintenance/work-orders/{$woId}/complete", [
        'downtime_hours' => 1.5,
        'labor_cost' => 200.00,
        'parts_cost' => 100.00,
    ]);
    $completeWoResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    // 6. Dispose Asset via API
    $dispResponse = $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", [
        'disposal_type' => 'sale',
        'proceeds_amount' => 25000.00,
        'disposal_costs' => 500.00,
    ]);
    $dispResponse->assertStatus(201)
        ->assertJsonPath('data.status', 'completed');
});
