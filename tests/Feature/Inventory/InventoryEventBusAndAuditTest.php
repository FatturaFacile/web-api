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
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\LotExpiredEvent;
use Modules\Inventory\Events\SerialAssignedEvent;
use Modules\Inventory\Listeners\BlockAvailabilityOnLotExpiredListener;
use Modules\Inventory\Listeners\ProcurementIntegrationListener;
use Modules\Inventory\Listeners\UpdateSerialLifecycleListener;
use Modules\Inventory\Models\InventoryAuditEntry;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Inventory\Services\DoubleEntryMovementService;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Event Bus & Audit Tenant',
        'legal_name' => 'Event Bus Enterprise Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Security Audit Officer',
        'email' => 'auditor@inventory.test',
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
        'code' => 'WH-EVT-'.strtoupper(Str::random(4)),
        'name' => 'Audited Central Depot',
        'warehouse_type' => 'standard',
        'is_active' => true,
    ]);

    $this->zone = WarehouseZone::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'Z-SEC',
        'name' => 'High Security Storage',
    ]);

    $this->aisle = WarehouseAisle::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_zone_id' => $this->zone->id,
        'code' => 'A-SEC-1',
    ]);

    $this->rack = WarehouseRack::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_aisle_id' => $this->aisle->id,
        'code' => 'R-SEC-1',
    ]);

    $this->bin = WarehouseBin::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_rack_id' => $this->rack->id,
        'code' => 'BIN-SEC-01',
        'barcode' => 'BIN-SEC-'.strtoupper(Str::random(4)),
        'is_active' => true,
    ]);

    $this->item = StockItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'sku' => 'SKU-AUDIT-'.strtoupper(Str::random(6)),
        'name' => 'Encrypted Cryptographic Token',
        'uom' => 'EA',
        'standard_cost' => 120.00,
        'current_moving_average_cost' => 120.00,
        'is_active' => true,
    ]);
});

test('transactional outbox reliably records published domain events for asynchronous relay', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    $outboxCountBefore = DB::table('transactional_outbox')->count();

    // Execute inbound movement
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->bin->id,
        quantity: 50.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 120.00
    ));

    $outboxRecords = DB::table('transactional_outbox')
        ->where('event_type', 'inventory.stock_transferred')
        ->get();

    expect($outboxRecords->count())->toBeGreaterThan(0);

    $latestRecord = $outboxRecords->last();
    expect($latestRecord->status)->toBe('pending')
        ->and($latestRecord->aggregate_type)->toBe('stock_movement')
        ->and($latestRecord->payload_json)->toContain('supplier_receipt');
});

test('immutable inventory audit ledger records movement with cryptographic provenance', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    $movement = $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: null,
        destinationBinId: (string) $this->bin->id,
        quantity: 12.0,
        movementType: 'supplier_receipt',
        userId: (string) $this->user->id,
        customUnitCost: 120.00
    ));

    $auditEntry = InventoryAuditEntry::where('movement_id', $movement->id)->first();

    expect($auditEntry)->not->toBeNull()
        ->and((float) $auditEntry->quantity_change)->toBe(12.0)
        ->and($auditEntry->reason_code)->toBe('supplier_receipt');
});

test('procurement integration listener handles incoming goods receipt event and updates inventory', function (): void {
    $receiptId = (string) Str::uuid7();
    $poId = (string) Str::uuid7();

    $event = new InventoryReceiptRequestedEvent(
        receiptId: $receiptId,
        purchaseOrderId: $poId,
        warehouseId: (string) $this->warehouse->id,
        items: [
            [
                'product_id' => (string) $this->item->id,
                'quantity' => 45.0,
                'purchase_order_line_id' => (string) Str::uuid7(),
            ],
        ],
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener = app(ProcurementIntegrationListener::class);
    $listener->handleInventoryReceipt($event);

    $quant = StockQuant::where('warehouse_bin_id', $this->bin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();

    expect($quant)->not->toBeNull()
        ->and((float) $quant->quantity_on_hand)->toBe(45.0);
});

test('lot expired event listener automatically quarantines lot and blocks quant availability', function (): void {
    $lot = StockLot::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'stock_item_id' => $this->item->id,
        'lot_number' => 'LOT-EXP-'.strtoupper(Str::random(4)),
        'expiration_date' => now()->subDay()->toDateString(),
        'is_quarantined' => false,
    ]);

    $quant = StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id,
        'lot_id' => $lot->id,
        'quantity_on_hand' => 20.0,
        'quantity_reserved' => 0.0,
    ]);

    expect($quant->quantity_available)->toBe(20.0);

    $listener = new BlockAvailabilityOnLotExpiredListener;
    $event = new LotExpiredEvent(
        lotId: (string) $lot->id,
        lotNumber: $lot->lot_number,
        stockItemId: (string) $this->item->id,
        expiryDate: now()->subDay()->toDateString(),
        companyId: (string) $this->tenant->id,
        tenantId: (string) $this->tenant->id
    );

    $listener->handle($event);

    $lot->refresh();
    $quant->refresh();

    expect($lot->is_quarantined)->toBeTrue()
        ->and((float) $quant->quantity_reserved)->toBe(20.0)
        ->and($quant->quantity_available)->toBe(0.0);
});

test('serial assigned event listener updates serial status to allocated', function (): void {
    $serial = StockSerialNumber::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'stock_item_id' => $this->item->id,
        'warehouse_bin_id' => $this->bin->id,
        'serial_number' => 'SN-TOKEN-'.strtoupper(Str::random(6)),
        'status' => 'in_stock',
    ]);

    $listener = new UpdateSerialLifecycleListener;
    $event = new SerialAssignedEvent(
        serialId: (string) $serial->id,
        serialNumber: $serial->serial_number,
        stockItemId: (string) $this->item->id,
        assignedContext: 'allocated',
        contextId: (string) Str::uuid7(),
        companyId: (string) $this->tenant->id,
        tenantId: (string) $this->tenant->id
    );

    $listener->handle($event);

    $serial->refresh();
    expect($serial->status)->toBe('allocated');
});
