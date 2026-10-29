<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\TransferAssetDTO;
use Modules\AssetManagement\DTOs\ValuateAssetDTO;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetLocation;
use Modules\GeneralLedger\Models\JournalEntry;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'EAM Lifecycle Tenant', 'legal_name' => 'EAM Lifecycle Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'eam.manager@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Asset Manager', 'password' => bcrypt('secret')]
    );

    $this->category = AssetCategory::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'CAT-CNC-01',
        'name' => 'CNC Industrial Equipment',
        'asset_type' => 'tangible',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'useful_life_months' => 60,
        'statutory_rate_percent' => 20.00,
        'salvage_value_percent' => 10.00,
        'is_depreciable' => true,
        'is_active' => true,
    ]);

    $this->lifecycleService = app(AssetLifecycleServiceInterface::class);
});

test('registers fixed asset, writes audit ledger, and dispatches outbox events', function (): void {
    $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        name: 'Mazak Integrex Multi-Tasking CNC',
        categoryId: (string) $this->category->id,
        acquisitionCost: 120000.00,
        salvageValue: 12000.00,
        custodianUserId: (string) $this->user->id
    ), (string) $this->user->id);

    expect($asset->status)->toBe('registered')
        ->and((float) $asset->acquisition_cost)->toBe(120000.00)
        ->and((float) $asset->depreciable_base)->toBe(108000.00)
        ->and($asset->custodian_user_id)->toBe((string) $this->user->id);

    $outboxRegistered = TransactionalOutbox::where('event_type', 'eam.asset.registered')
        ->where('aggregate_id', (string) $asset->id)
        ->first();
    $outboxForecast = TransactionalOutbox::where('event_type', 'eam.treasury.purchase_forecast')
        ->where('aggregate_id', (string) $asset->id)
        ->first();

    expect($outboxRegistered)->not->toBeNull()
        ->and($outboxForecast)->not->toBeNull();

    $audit = AuditLedgerEntry::where('action', 'ASSET_REGISTERED')
        ->where('resource_id', (string) $asset->id)
        ->first();
    expect($audit)->not->toBeNull();
});

test('capitalizes asset and posts balanced double-entry journal to General Ledger', function (): void {
    $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        name: 'Automated Laser Cutter',
        categoryId: (string) $this->category->id,
        acquisitionCost: 60000.00,
        salvageValue: 6000.00
    ), (string) $this->user->id);

    $capitalized = $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: 60000.00,
        salvageValue: 6000.00
    ), (string) $this->user->id);

    expect($capitalized->status)->toBe('capitalized')
        ->and((float) $capitalized->capitalized_cost)->toBe(60000.00)
        ->and((float) $capitalized->carrying_amount)->toBe(60000.00)
        ->and((float) $capitalized->depreciable_base)->toBe(54000.00);

    $journal = JournalEntry::where('source_document_type', 'eam_fixed_asset')
        ->where('source_document_id', (string) $asset->id)
        ->first();

    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and((float) $journal->total_amount)->toBe(60000.00);
});

test('commissions and transfers asset across locations and custodians', function (): void {
    $locA = AssetLocation::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'PLANT-A',
        'name' => 'Plant A - Turin',
    ]);
    $locB = AssetLocation::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => 'PLANT-B',
        'name' => 'Plant B - Bologna',
    ]);

    $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        name: 'Industrial Forklift Toyota',
        categoryId: (string) $this->category->id,
        acquisitionCost: 35000.00,
        locationId: (string) $locA->id
    ), (string) $this->user->id);

    $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: 35000.00
    ), (string) $this->user->id);

    $commissioned = $this->lifecycleService->commissionAsset($asset, date('Y-m-d'), (string) $this->user->id);
    expect($commissioned->status)->toBe('in_service');

    $transfer = $this->lifecycleService->transferAsset(new TransferAssetDTO(
        assetId: (string) $asset->id,
        toLocationId: (string) $locB->id,
        toCustodianId: (string) $this->user->id,
        reason: 'Relocation to Bologna logistics center'
    ), (string) $this->user->id);

    expect($transfer->status)->toBe('completed')
        ->and($asset->fresh()->location_id)->toBe((string) $locB->id)
        ->and($asset->fresh()->custodian_user_id)->toBe((string) $this->user->id);
});

test('performs IAS 16 revaluation and IAS 36 impairment with GL postings', function (): void {
    $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        name: 'Industrial Warehouse Building',
        categoryId: (string) $this->category->id,
        acquisitionCost: 100000.00,
        salvageValue: 10000.00
    ), (string) $this->user->id);

    $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: 100000.00,
        salvageValue: 10000.00
    ), (string) $this->user->id);

    // 1. IAS 16 Revaluation upward to €125,000
    $revaluation = $this->lifecycleService->valuateAsset(new ValuateAssetDTO(
        assetId: (string) $asset->id,
        valuationType: 'revaluation',
        fairValue: 125000.00,
        reason: 'Market property appreciation'
    ), (string) $this->user->id);

    expect($revaluation->status)->toBe('posted')
        ->and((float) $revaluation->revaluation_reserve_delta)->toBe(25000.00)
        ->and((float) $asset->fresh()->carrying_amount)->toBe(125000.00)
        ->and($revaluation->journal_entry_id)->not->toBeNull();

    // 2. IAS 36 Impairment down to Recoverable Amount = max(90000, 95000) = €95,000
    $impairment = $this->lifecycleService->valuateAsset(new ValuateAssetDTO(
        assetId: (string) $asset->id,
        valuationType: 'impairment',
        fairValue: 90000.00,
        valueInUse: 95000.00,
        reason: 'Structural damage assessment'
    ), (string) $this->user->id);

    expect($impairment->status)->toBe('posted')
        ->and((float) $impairment->recoverable_amount)->toBe(95000.00)
        ->and((float) $impairment->impairment_loss_amount)->toBe(30000.00)
        ->and((float) $asset->fresh()->carrying_amount)->toBe(95000.00)
        ->and($impairment->journal_entry_id)->not->toBeNull();

    // 3. IAS 36 Impairment Reversal back up to €110,000
    $reversal = $this->lifecycleService->reverseImpairment(
        $asset->fresh(),
        110000.00,
        'Structural remediation completed',
        (string) $this->user->id
    );

    expect((float) $reversal->adjustment_amount)->toBe(15000.00)
        ->and((float) $asset->fresh()->carrying_amount)->toBe(110000.00)
        ->and((float) $asset->fresh()->impairment_loss_total)->toBe(15000.00);
});

test('disposes asset via sale, calculates gain/loss, posts GL entry, and emits cash forecast', function (): void {
    $asset = $this->lifecycleService->registerAsset(new RegisterAssetDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        name: 'Delivery Van Mercedes Sprinter',
        categoryId: (string) $this->category->id,
        acquisitionCost: 40000.00,
        salvageValue: 4000.00
    ), (string) $this->user->id);

    $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: 40000.00,
        salvageValue: 4000.00
    ), (string) $this->user->id);

    // Simulate €15,000 accumulated depreciation -> Carrying Amount = €25,000
    $asset->update([
        'accumulated_depreciation' => 15000.00,
        'carrying_amount' => 25000.00,
    ]);

    // Sell for €30,000 proceeds minus €1,000 disposal costs = €29,000 net -> €4,000 Gain
    $disposal = $this->lifecycleService->disposeAsset(new DisposeAssetDTO(
        assetId: (string) $asset->id,
        disposalType: 'sale',
        proceedsAmount: 30000.00,
        disposalCosts: 1000.00,
        reason: 'Fleet renewal sale'
    ), (string) $this->user->id);

    expect($disposal->status)->toBe('completed')
        ->and((float) $disposal->gain_loss_amount)->toBe(4000.00)
        ->and($disposal->journal_entry_id)->not->toBeNull()
        ->and($asset->fresh()->status)->toBe('sold')
        ->and((float) $asset->fresh()->carrying_amount)->toBe(0.00);

    $cashForecastEvent = TransactionalOutbox::where('event_type', 'eam.treasury.disposal_cash_forecast')
        ->where('aggregate_id', (string) $disposal->id)
        ->first();
    expect($cashForecastEvent)->not->toBeNull();
});
