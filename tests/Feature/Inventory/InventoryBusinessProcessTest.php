<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Inventory\Services\DoubleEntryMovementService;
use Modules\Inventory\Services\InventoryCountService;
use Modules\Inventory\Services\StockValuationService;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Inventory Process Test Tenant',
        'legal_name' => 'Inventory Process Enterprise',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Warehouse Logistics Operator',
        'email' => 'operator@inventory-process.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->warehouse = Warehouse::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'code' => 'WH-BP-'.strtoupper(Str::random(4)),
        'name' => 'Main Distribution Center',
        'warehouse_type' => 'standard',
        'is_active' => true,
    ]);

    $this->zone = WarehouseZone::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'Z-INBOUND',
        'name' => 'Inbound Staging Zone',
        'zone_type' => 'receiving',
    ]);

    $this->aisle = WarehouseAisle::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_zone_id' => $this->zone->id,
        'code' => 'A1',
    ]);

    $this->rack = WarehouseRack::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_aisle_id' => $this->aisle->id,
        'code' => 'R1',
    ]);

    $this->stagingBin = WarehouseBin::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_rack_id' => $this->rack->id,
        'code' => 'BIN-STAGING-01',
        'barcode' => 'BIN-STG-01-'.strtoupper(Str::random(4)),
        'bin_type' => 'staging',
        'is_active' => true,
    ]);

    $this->storageBin = WarehouseBin::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_rack_id' => $this->rack->id,
        'code' => 'BIN-STORAGE-01',
        'barcode' => 'BIN-STO-01-'.strtoupper(Str::random(4)),
        'bin_type' => 'standard',
        'is_active' => true,
    ]);

    $this->item = StockItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'sku' => 'SKU-BP-'.strtoupper(Str::random(6)),
        'name' => 'Precision Industrial Sensor',
        'uom' => 'EA',
        'current_moving_average_cost' => 50.00,
        'standard_cost' => 50.00,
        'is_active' => true,
    ]);
});

test('path 1: inbound receipt from supplier increments stock quant in staging bin', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    $dto = new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->stagingBin->id,
        quantity: 100.0,
        movementType: 'supplier_receipt',
        originDocumentType: 'purchase_orders',
        originDocumentId: (string) Str::uuid7(),
        userId: (string) $this->user->id,
        customUnitCost: 45.00
    );

    $movement = $movementService->executeTransfer($dto);

    expect($movement)->toBeInstanceOf(StockMovement::class)
        ->and($movement->movement_type)->toBe('supplier_receipt')
        ->and((float) $movement->quantity)->toBe(100.0);

    $quant = StockQuant::query()
        ->where('warehouse_bin_id', $this->stagingBin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();

    expect($quant)->not->toBeNull()
        ->and((float) $quant->quantity_on_hand)->toBe(100.0)
        ->and((float) $quant->quantity_reserved)->toBe(0.0)
        ->and($quant->quantity_available)->toBe(100.0);
});

test('path 2: internal transfer from staging bin to storage bin moves stock cleanly', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    // Initial stock in staging
    $inboundDto = new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->stagingBin->id,
        quantity: 50.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 50.00
    );
    $movementService->executeTransfer($inboundDto);

    // Transfer 30 units to storage bin
    $transferDto = new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: (string) $this->stagingBin->id,
        destinationBinId: (string) $this->storageBin->id,
        quantity: 30.0,
        movementType: 'internal_transfer',
        userId: (string) $this->user->id,
        customUnitCost: 50.00
    );

    $transferMovement = $movementService->executeTransfer($transferDto);

    expect($transferMovement)->toBeInstanceOf(StockMovement::class);

    $sourceQuant = StockQuant::where('warehouse_bin_id', $this->stagingBin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();

    $destQuant = StockQuant::where('warehouse_bin_id', $this->storageBin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();

    expect((float) $sourceQuant->quantity_on_hand)->toBe(20.0)
        ->and((float) $destQuant->quantity_on_hand)->toBe(30.0);
});

test('path 3: fifo valuation calculation and moving average cost update', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);
    /** @var StockValuationService $valuationService */
    $valuationService = app(StockValuationService::class);

    // Batch 1: 10 units @ €40
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->storageBin->id,
        quantity: 10.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 40.00
    ));

    // Batch 2: 10 units @ €60
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->storageBin->id,
        quantity: 10.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 60.00
    ));

    // Depleting 15 units under FIFO: 10 units @ €40 + 5 units @ €60 = €400 + €300 = €700
    $fifoValuation = $valuationService->calculateFifoValuation($this->item, 15.0);
    expect($fifoValuation)->toBe(700.0);

    // Compute moving average cost: 20 units current @ €50 average, new receipt 10 units @ €80
    // Total: (20 * 50 + 10 * 80) / 30 = (1000 + 800) / 30 = 1800 / 30 = 60.00
    $newMac = $valuationService->computeItemMovingAverageCost($this->item, 10.0, 80.0);
    expect($newMac)->toBe(60.0);
});

test('path 4: cycle count session recording and discrepancy reconciliation', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);
    /** @var InventoryCountService $countService */
    $countService = app(InventoryCountService::class);

    // Setup 25 units in storage
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->storageBin->id,
        quantity: 25.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 50.00
    ));

    $sessionDto = new InventoryCountSessionDTO(
        warehouseId: (string) $this->warehouse->id,
        countType: 'cycle_count',
        binIds: [(string) $this->storageBin->id],
        scheduledDate: now()->toDateString(),
        userId: (string) $this->user->id
    );

    $session = $countService->createSession($sessionDto);
    expect($session)->toBeInstanceOf(InventoryCountSession::class)
        ->and($session->lines)->toHaveCount(1);

    $line = $session->lines->first();
    expect((float) $line->system_quantity)->toBe(25.0);

    // Physical count found 27 units (+2 variance = +€100, <= €500 threshold so auto-approves)
    $countService->recordCount((string) $line->id, 27.0, (string) $this->user->id);

    $adjustment = $countService->reconcileSession($session, (string) $this->user->id);

    expect($adjustment)->toBeInstanceOf(InventoryAdjustment::class)
        ->and($adjustment->status)->toBe('approved')
        ->and((float) $adjustment->net_valuation_change)->toBe(100.0);

    // Recheck quant on hand
    $updatedQuant = StockQuant::where('warehouse_bin_id', $this->storageBin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();
    expect((float) $updatedQuant->quantity_on_hand)->toBe(27.0);
});

test('path 5: outbound issuance to project or customer decrements stock quant', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    // Seed stock
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->storageBin->id,
        quantity: 40.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 50.00
    ));

    // Outbound issue (destinationBinId = null)
    $outboundDto = new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: (string) $this->storageBin->id,
        destinationBinId: null,
        quantity: 15.0,
        movementType: 'project_issue',
        originDocumentType: 'projects',
        originDocumentId: (string) Str::uuid7(),
        userId: (string) $this->user->id,
        customUnitCost: 50.00
    );

    $movement = $movementService->executeTransfer($outboundDto);
    expect($movement)->toBeInstanceOf(StockMovement::class)
        ->and($movement->movement_type)->toBe('project_issue')
        ->and((float) $movement->quantity)->toBe(15.0);

    $quant = StockQuant::where('warehouse_bin_id', $this->storageBin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();
    expect((float) $quant->quantity_on_hand)->toBe(25.0);
});
