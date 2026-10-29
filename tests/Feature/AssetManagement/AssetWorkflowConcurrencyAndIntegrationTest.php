<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\Listeners\WorkflowApprovalAssetListener;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'EAM Integration Tenant', 'legal_name' => 'EAM Integration Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'eam.integrator@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Integration Architect', 'password' => bcrypt('secret')]
    );

    $this->category = AssetCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->lifecycleService = app(AssetLifecycleServiceInterface::class);
    $this->depreciationEngine = app(DepreciationEngineServiceInterface::class);
    $this->projectAssetService = app(ProjectAssetIntegrationServiceInterface::class);
});

test('prevents duplicate capitalization, duplicate depreciation, and duplicate disposal', function (): void {
    $asset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 12000.00,
        'capitalized_cost' => 12000.00,
        'carrying_amount' => 12000.00,
        'depreciable_base' => 12000.00,
    ]);

    // 1. Duplicate Capitalization Guard
    expect(fn () => $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
        assetId: (string) $asset->id,
        capitalizedCost: 12000.00
    )))->toThrow(InvalidArgumentException::class);

    // 2. Duplicate Depreciation Guard for same period
    $this->depreciationEngine->calculateSingleAssetDepreciation($asset, '2026-10', 'monthly', 'statutory');
    expect(fn () => $this->depreciationEngine->calculateSingleAssetDepreciation($asset, '2026-10', 'monthly', 'statutory'))
        ->toThrow(InvalidArgumentException::class);

    // 3. Duplicate Disposal Guard
    $this->lifecycleService->disposeAsset(new DisposeAssetDTO(
        assetId: (string) $asset->id,
        disposalType: 'retirement'
    ));
    expect(fn () => $this->lifecycleService->disposeAsset(new DisposeAssetDTO(
        assetId: (string) $asset->id,
        disposalType: 'scrap'
    )))->toThrow(InvalidArgumentException::class);
});

test('consumes GoodsReceivedEvent, PurchaseLiabilityReadyEvent, and InventoryValuationReadyEvent for asset acquisition', function (): void {
    $poId = (string) Str::uuid7();
    $receiptId = (string) Str::uuid7();

    // 1. GoodsReceivedEvent creates registered fixed asset
    event(new GoodsReceivedEvent(
        receiptId: $receiptId,
        receiptNumber: 'GRN-2026-900',
        purchaseOrderId: $poId,
        supplierDeliveryNoteRef: 'DDT-9988',
        receivedLines: [
            [
                'purchase_order_line_id' => (string) Str::uuid7(),
                'quantity_received' => 1.0,
                'quantity_accepted' => 1.0,
                'unit_cost' => 45000.00,
                'is_capital_asset' => true,
                'description' => 'Robotic Welding Arm ABB',
                'lot_number' => 'SN-ABB-2026',
            ],
        ],
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    $poAsset = FixedAsset::where('tenant_id', $this->tenant->id)
        ->where('purchase_order_id', $poId)
        ->first();

    expect($poAsset)->not->toBeNull()
        ->and($poAsset->status)->toBe('registered')
        ->and((float) $poAsset->acquisition_cost)->toBe(45000.00);

    // 2. PurchaseLiabilityReadyEvent auto-capitalizes the asset
    event(new PurchaseLiabilityReadyEvent(
        matchLogId: (string) Str::uuid7(),
        purchaseOrderId: $poId,
        supplierId: (string) Str::uuid7(),
        netAmount: 45000.00,
        taxAmount: 9900.00,
        grossAmount: 54900.00,
        currency: 'EUR',
        vendorInvoiceNumber: 'INV-ABB-001',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($poAsset->fresh()->status)->toBe('capitalized')
        ->and((float) $poAsset->fresh()->capitalized_cost)->toBe(45000.00);

    // 3. InventoryValuationReadyEvent converts capitalizable inventory item to asset
    event(new InventoryValuationReadyEvent(
        valuationId: (string) Str::uuid7(),
        warehouseId: (string) Str::uuid7(),
        period: '2026-10',
        totalValuationAmount: 18500.00,
        valuationMethod: 'FIFO',
        valuationSummary: [
            'capitalizable_items' => [
                [
                    'stock_item_id' => (string) Str::uuid7(),
                    'item_name' => 'Diesel Power Generator 150kVA',
                    'valuation_cost' => 18500.00,
                    'serial_number' => 'SN-GEN-150',
                ],
            ],
        ],
        companyId: (string) $this->tenant->id,
        tenantId: (string) $this->tenant->id
    ));

    $invAsset = FixedAsset::where('tenant_id', $this->tenant->id)
        ->where('acquisition_type', 'inventory_conversion')
        ->first();

    expect($invAsset)->not->toBeNull()
        ->and((float) $invAsset->acquisition_cost)->toBe(18500.00);
});

test('allocates equipment to project, attributes utilization cost, and releases on ProjectClosureReadyEvent', function (): void {
    $projectId = (string) Str::uuid7();
    $asset = FixedAsset::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
    ]);

    $allocation = $this->projectAssetService->allocateAssetToProject(new AllocateProjectAssetDTO(
        tenantId: (string) $this->tenant->id,
        assetId: (string) $asset->id,
        projectId: $projectId,
        allocatedFrom: date('Y-m-d'),
        hourlyRate: 100.00,
        dailyRate: 500.00,
        utilizedHours: 10.0,
        utilizedDays: 2.0
    ), (string) $this->user->id);

    expect((float) $allocation->attributed_cost)->toBe(2000.00)
        ->and($asset->fresh()->project_id)->toBe($projectId);

    $updatedAlloc = $this->projectAssetService->recordUtilization($allocation, 5.0, 1.0);
    expect((float) $updatedAlloc->attributed_cost)->toBe(3000.00);

    // Emit ProjectClosureReadyEvent -> automatically releases asset
    event(new ProjectClosureReadyEvent(
        projectId: $projectId,
        finalContractValue: 100000.00,
        finalCost: 75000.00,
        unbilledWipBalance: 0.00,
        retainedEarningsImpact: 25000.00,
        fiscalYear: '2026',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($allocation->fresh()->status)->toBe('released')
        ->and($asset->fresh()->project_id)->toBeNull();
});

test('handles WorkflowInstanceCompletedEvent and PeriodClosedEvent', function (): void {
    $workflowInstanceId = (string) Str::uuid7();
    $asset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'workflow_instance_id' => $workflowInstanceId,
        'approval_status' => 'pending_approval',
        'is_locked' => true,
    ]);

    app(WorkflowApprovalAssetListener::class)->handleWorkflowApproved(new WorkflowInstanceCompletedEvent(
        instanceId: $workflowInstanceId,
        status: 'approved',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($asset->fresh()->approval_status)->toBe('approved')
        ->and($asset->fresh()->is_locked)->toBeFalse();

    // PeriodClosedEvent triggers monthly depreciation run if not yet executed
    event(new PeriodClosedEvent(
        fiscalPeriodId: (string) Str::uuid7(),
        fiscalYearId: (string) Str::uuid7(),
        periodNumber: 12,
        periodName: 'December',
        closedBy: (string) $this->user->id,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    ));

    expect($asset->fresh()->depreciationSchedules()->count())->toBeGreaterThanOrEqual(1);
});
