<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'EAM Depreciation Tenant', 'legal_name' => 'EAM Depreciation Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'eam.accountant@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Fixed Asset Accountant', 'password' => bcrypt('secret')]
    );

    $this->category = AssetCategory::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'CAT-DEPR',
        'name' => 'Depreciable Equipment',
        'asset_type' => 'tangible',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'useful_life_months' => 60,
        'statutory_rate_percent' => 20.00,
        'salvage_value_percent' => 0.00,
        'is_depreciable' => true,
        'is_active' => true,
    ]);

    $this->depreciationEngine = app(DepreciationEngineServiceInterface::class);
});

test('calculates straight line, declining balance, double declining balance, and units of production accurately', function (): void {
    // 1. Straight Line: Cost 60,000, Salvage 0, Life 5 years (60 months) -> Monthly = 1,000, Yearly = 12,000
    $slAsset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 60000.00,
        'capitalized_cost' => 60000.00,
        'salvage_value' => 0.00,
        'depreciable_base' => 60000.00,
        'carrying_amount' => 60000.00,
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'useful_life_months' => 60,
    ]);

    expect($this->depreciationEngine->calculateAssetDepreciationAmount($slAsset, 'monthly'))->toBe(1000.00)
        ->and($this->depreciationEngine->calculateAssetDepreciationAmount($slAsset, 'yearly'))->toBe(12000.00);

    // 2. Declining Balance: Carrying 48,000, Rate 20% -> Yearly = 9,600, Monthly = 800
    $dbAsset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 48000.00,
        'capitalized_cost' => 48000.00,
        'salvage_value' => 0.00,
        'depreciable_base' => 48000.00,
        'carrying_amount' => 48000.00,
        'depreciation_method' => 'declining_balance',
        'depreciation_rate_percent' => 20.00,
        'useful_life_years' => 5,
    ]);

    expect($this->depreciationEngine->calculateAssetDepreciationAmount($dbAsset, 'yearly'))->toBe(9600.00)
        ->and($this->depreciationEngine->calculateAssetDepreciationAmount($dbAsset, 'monthly'))->toBe(800.00);

    // 3. Double Declining Balance (DDB): Carrying 60,000, Life 5 years (DDB rate = 40%) -> Yearly = 24,000, Monthly = 2,000
    $ddbAsset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 60000.00,
        'capitalized_cost' => 60000.00,
        'salvage_value' => 0.00,
        'depreciable_base' => 60000.00,
        'carrying_amount' => 60000.00,
        'depreciation_method' => 'double_declining_balance',
        'useful_life_years' => 5,
    ]);

    expect($this->depreciationEngine->calculateAssetDepreciationAmount($ddbAsset, 'yearly'))->toBe(24000.00)
        ->and($this->depreciationEngine->calculateAssetDepreciationAmount($ddbAsset, 'monthly'))->toBe(2000.00);

    // 4. Units of Production: Depreciable Base 50,000, Expected Units 10,000 (€5/unit), Produced = 350 -> 1,750
    $uopAsset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 55000.00,
        'capitalized_cost' => 55000.00,
        'salvage_value' => 5000.00,
        'depreciable_base' => 50000.00,
        'carrying_amount' => 55000.00,
        'depreciation_method' => 'units_of_production',
        'total_expected_units' => 10000.00,
    ]);

    expect($this->depreciationEngine->calculateAssetDepreciationAmount($uopAsset, 'monthly', 350.0))->toBe(1750.00);
});

test('enforces salvage value floor and marks asset as fully depreciated', function (): void {
    $asset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 10000.00,
        'capitalized_cost' => 10000.00,
        'salvage_value' => 2000.00,
        'depreciable_base' => 8000.00,
        'accumulated_depreciation' => 7500.00,
        'carrying_amount' => 2500.00, // Only €500 left above salvage value of €2,000
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 4,
        'useful_life_months' => 48,
    ]);

    $schedule = $this->depreciationEngine->calculateSingleAssetDepreciation(
        asset: $asset,
        periodCode: '2026-10',
        periodType: 'yearly',
        ledgerType: 'statutory',
        autoPostToGl: true,
        actorUserId: (string) $this->user->id
    );

    // Even though yearly straight-line is 2,000, it must cap at 500 so carrying amount stops at 2,000 salvage value
    expect((float) $schedule->depreciation_amount)->toBe(500.00)
        ->and((float) $schedule->closing_book_value)->toBe(2000.00)
        ->and($schedule->status)->toBe('posted')
        ->and($schedule->journal_entry_id)->not->toBeNull()
        ->and($asset->fresh()->is_fully_depreciated)->toBeTrue();
});

test('executes batch monthly depreciation run and posts GL journal entries', function (): void {
    FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 24000.00,
        'capitalized_cost' => 24000.00,
        'salvage_value' => 0.00,
        'depreciable_base' => 24000.00,
        'carrying_amount' => 24000.00,
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 4,
        'useful_life_months' => 48, // 500 / month
    ]);

    FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 36000.00,
        'capitalized_cost' => 36000.00,
        'salvage_value' => 0.00,
        'depreciable_base' => 36000.00,
        'carrying_amount' => 36000.00,
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 3,
        'useful_life_months' => 36, // 1,000 / month
    ]);

    $run = $this->depreciationEngine->executeDepreciationRun(new RunDepreciationDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        periodCode: '2026-11',
        periodType: 'monthly',
        ledgerType: 'statutory',
        autoPostToGl: true
    ), (string) $this->user->id);

    expect($run->status)->toBe('posted')
        ->and($run->assets_processed_count)->toBe(2)
        ->and((float) $run->total_depreciation_amount)->toBe(1500.00);
});
