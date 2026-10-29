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
use InvalidArgumentException;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Inventory\Services\DoubleEntryMovementService;
use Modules\Inventory\Services\StockReservationService;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Inventory Concurrency Tenant',
        'legal_name' => 'Concurrency Testing Enterprise Ltd',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Concurrency Test User',
        'email' => 'concurrency@inventory.test',
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
        'code' => 'WH-CONC-'.strtoupper(Str::random(4)),
        'name' => 'High Concurrency Warehouse',
        'warehouse_type' => 'standard',
        'is_active' => true,
    ]);

    $this->zone = WarehouseZone::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'Z-CONC',
        'name' => 'High Velocity Zone',
    ]);

    $this->aisle = WarehouseAisle::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_zone_id' => $this->zone->id,
        'code' => 'A-C1',
    ]);

    $this->rack = WarehouseRack::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_aisle_id' => $this->aisle->id,
        'code' => 'R-C1',
    ]);

    $this->bin = WarehouseBin::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'warehouse_rack_id' => $this->rack->id,
        'code' => 'BIN-CONC-01',
        'barcode' => 'BIN-CONC-'.strtoupper(Str::random(4)),
        'is_active' => true,
    ]);

    $this->item = StockItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'sku' => 'SKU-CONC-'.strtoupper(Str::random(6)),
        'name' => 'Concurrent Bottled Widget',
        'uom' => 'EA',
        'standard_cost' => 25.00,
        'current_moving_average_cost' => 25.00,
        'is_active' => true,
    ]);
});

test('pessimistic locking prevents double reservation beyond available pool', function (): void {
    /** @var StockReservationService $reservationService */
    $reservationService = app(StockReservationService::class);

    // Initial stock: 10 units on hand
    $quant = StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id,
        'quantity_on_hand' => 10.0,
        'quantity_reserved' => 0.0,
    ]);

    expect($quant->quantity_available)->toBe(10.0);

    // Transaction A allocates 8 units
    $resA = $reservationService->allocate(new StockReservationDTO(
        stockItemId: (string) $this->item->id,
        warehouseId: (string) $this->warehouse->id,
        quantity: 8.0,
        reservedForType: 'sales_orders',
        reservedForId: (string) Str::uuid7(),
        userId: (string) $this->user->id
    ));

    expect($resA)->toBeInstanceOf(StockReservation::class)
        ->and((float) $resA->quantity_reserved)->toBe(8.0);

    $quant->refresh();
    expect((float) $quant->quantity_on_hand)->toBe(10.0)
        ->and((float) $quant->quantity_reserved)->toBe(8.0)
        ->and($quant->quantity_available)->toBe(2.0);

    // Transaction B attempts to allocate 5 units when only 2 remain -> must be rejected
    expect(fn () => $reservationService->allocate(new StockReservationDTO(
        stockItemId: (string) $this->item->id,
        warehouseId: (string) $this->warehouse->id,
        quantity: 5.0,
        reservedForType: 'sales_orders',
        reservedForId: (string) Str::uuid7(),
        userId: (string) $this->user->id
    )))->toThrow(InvalidArgumentException::class);

    // Pool integrity verified: still 10 on hand, 8 reserved, 2 available
    $quant->refresh();
    expect((float) $quant->quantity_on_hand)->toBe(10.0)
        ->and((float) $quant->quantity_reserved)->toBe(8.0)
        ->and($quant->quantity_available)->toBe(2.0);
});

test('releasing reserved stock atomically reopens available quota for subsequent reservation', function (): void {
    /** @var StockReservationService $reservationService */
    $reservationService = app(StockReservationService::class);

    // Seed 10 units
    StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id,
        'quantity_on_hand' => 10.0,
        'quantity_reserved' => 0.0,
    ]);

    // Reserve 8 units
    $resA = $reservationService->allocate(new StockReservationDTO(
        stockItemId: (string) $this->item->id,
        warehouseId: (string) $this->warehouse->id,
        quantity: 8.0,
        reservedForType: 'sales_orders',
        reservedForId: (string) Str::uuid7(),
        userId: (string) $this->user->id
    ));

    // Release reservation
    $reservationService->release($resA);

    $resA->refresh();
    expect($resA->status)->toBe('released');

    $quant = StockQuant::where('warehouse_bin_id', $this->bin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();

    expect((float) $quant->quantity_reserved)->toBe(0.0)
        ->and($quant->quantity_available)->toBe(10.0);

    // Subsequent 5-unit reservation now succeeds without contention
    $resB = $reservationService->allocate(new StockReservationDTO(
        stockItemId: (string) $this->item->id,
        warehouseId: (string) $this->warehouse->id,
        quantity: 5.0,
        reservedForType: 'sales_orders',
        reservedForId: (string) Str::uuid7(),
        userId: (string) $this->user->id
    ));

    expect($resB->status)->toBe('active')
        ->and((float) $resB->quantity_reserved)->toBe(5.0);

    $quant->refresh();
    expect((float) $quant->quantity_reserved)->toBe(5.0)
        ->and($quant->quantity_available)->toBe(5.0);
});

test('pessimistic row locking on stock quant prevents negative inventory during concurrent transfers', function (): void {
    /** @var DoubleEntryMovementService $movementService */
    $movementService = app(DoubleEntryMovementService::class);

    // Initial stock: 15 units in bin
    StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id,
        'quantity_on_hand' => 15.0,
        'quantity_reserved' => 0.0,
    ]);

    // Transfer 1: Take 10 units -> succeeds
    $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: (string) $this->bin->id,
        destinationBinId: null,
        quantity: 10.0,
        movementType: 'scrap',
        userId: (string) $this->user->id
    ));

    $quant = StockQuant::where('warehouse_bin_id', $this->bin->id)
        ->where('stock_item_id', $this->item->id)
        ->first();
    expect((float) $quant->quantity_on_hand)->toBe(5.0);

    // Transfer 2: Attempting to take another 10 units when only 5 remain -> must fail atomically
    expect(fn () => $movementService->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $this->item->id,
        sourceBinId: (string) $this->bin->id,
        destinationBinId: null,
        quantity: 10.0,
        movementType: 'scrap',
        userId: (string) $this->user->id
    )))->toThrow(InvalidArgumentException::class);

    // Assert that stock remained at exactly 5 and was never over-depleted into negative territory
    $quant->refresh();
    expect((float) $quant->quantity_on_hand)->toBe(5.0)
        ->and($quant->quantity_available)->toBe(5.0);
});
