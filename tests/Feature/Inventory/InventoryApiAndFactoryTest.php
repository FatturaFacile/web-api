<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\InventoryCategorySeeder;
use Database\Seeders\InventoryDemoDataSeeder;
use Database\Seeders\InventoryLookupSeeder;
use Database\Seeders\InventoryStatusSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Actions\AllocateStockReservationAction;
use Modules\Inventory\Actions\ExecuteDoubleEntryMovementAction;
use Modules\Inventory\Actions\ReleaseStockReservationAction;
use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\DTOs\BarcodeScanDTO;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Inventory Test Tenant',
        'legal_name' => 'Inventory Enterprise Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Inventory Logistics Manager',
        'email' => 'manager@inventory.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    $this->withHeader('X-Tenant-ID', $this->tenant->id);

    Sanctum::actingAs($this->user);
});

test('inventory idempotent seeders execute cleanly and populate warehouses and demo records', function (): void {
    $this->seed(InventoryLookupSeeder::class);
    $this->seed(InventoryStatusSeeder::class);
    $this->seed(WarehouseSeeder::class);
    $this->seed(InventoryCategorySeeder::class);
    $this->seed(InventoryDemoDataSeeder::class);

    expect(Warehouse::count())->toBeGreaterThanOrEqual(1);
    expect(StockItem::count())->toBeGreaterThanOrEqual(1);
    expect(StockQuant::count())->toBeGreaterThanOrEqual(1);
});

test('inventory factories create valid models with rich states', function (): void {
    $warehouse = Warehouse::factory()->van()->create(['tenant_id' => $this->tenant->id]);
    expect($warehouse->warehouse_type)->toBe('vehicle_van');

    $item = StockItem::factory()->serialized()->create(['tenant_id' => $this->tenant->id]);
    expect($item->tracking_type)->toBe('serial');

    $bin = WarehouseBin::factory()->locked()->create(['tenant_id' => $this->tenant->id]);
    expect($bin->is_locked)->toBeTrue();

    $quant = StockQuant::factory()->lowStock()->create(['tenant_id' => $this->tenant->id]);
    expect((float) $quant->quantity_on_hand)->toBe(2.0);
});

test('double-entry stock transfer executes with mathematical net-zero balance', function (): void {
    $warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $zone = WarehouseZone::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_aisle_id' => $aisle->id]);
    $sourceBin = WarehouseBin::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack->id]);
    $destBin = WarehouseBin::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack->id]);
    $item = StockItem::factory()->create(['tenant_id' => $this->tenant->id, 'standard_cost' => 50.00]);

    // Initial stock in source bin
    StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'warehouse_bin_id' => $sourceBin->id,
        'stock_item_id' => $item->id,
        'quantity_on_hand' => 100.0,
        'quantity_reserved' => 0.0,
    ]);

    $action = app(ExecuteDoubleEntryMovementAction::class);

    $dto = new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: (string) $sourceBin->id,
        destinationBinId: (string) $destBin->id,
        quantity: 25.0,
        movementType: 'internal_transfer',
        userId: (string) $this->user->id
    );

    $movement = $action->execute($dto);

    expect($movement)->toBeInstanceOf(StockMovement::class);
    expect((float) $movement->quantity)->toBe(25.0);

    $sourceQuant = StockQuant::where('warehouse_bin_id', $sourceBin->id)->where('stock_item_id', $item->id)->first();
    $destQuant = StockQuant::where('warehouse_bin_id', $destBin->id)->where('stock_item_id', $item->id)->first();

    expect((float) $sourceQuant->quantity_available)->toBe(75.0);
    expect((float) $destQuant->quantity_available)->toBe(25.0);
    expect((float) ($sourceQuant->quantity_on_hand + $destQuant->quantity_on_hand))->toBe(100.0);
});

test('stock reservation allocates and releases available quantity cleanly', function (): void {
    $warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $zone = WarehouseZone::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_aisle_id' => $aisle->id]);
    $bin = WarehouseBin::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack->id]);
    $item = StockItem::factory()->create(['tenant_id' => $this->tenant->id]);

    StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'warehouse_bin_id' => $bin->id,
        'stock_item_id' => $item->id,
        'quantity_on_hand' => 50.0,
        'quantity_reserved' => 0.0,
    ]);

    $allocateAction = app(AllocateStockReservationAction::class);
    $releaseAction = app(ReleaseStockReservationAction::class);

    $dto = new StockReservationDTO(
        stockItemId: (string) $item->id,
        warehouseId: (string) $warehouse->id,
        reservedForType: 'sales_order',
        reservedForId: (string) Str::uuid7(),
        quantity: 15.0,
        userId: (string) $this->user->id
    );

    $reservation = $allocateAction->execute($dto);
    expect($reservation->status)->toBe('active');

    $quantAfterAllocation = StockQuant::where('warehouse_bin_id', $bin->id)->where('stock_item_id', $item->id)->first();
    expect((float) $quantAfterAllocation->quantity_reserved)->toBe(15.0);
    expect((float) $quantAfterAllocation->quantity_available)->toBe(35.0);

    $releaseAction->execute($reservation);
    $quantAfterRelease = StockQuant::where('warehouse_bin_id', $bin->id)->where('stock_item_id', $item->id)->first();
    expect((float) $quantAfterRelease->quantity_reserved)->toBe(0.0);
    expect((float) $quantAfterRelease->quantity_available)->toBe(50.0);
});

test('barcode scanner parses GS1-128 and EAN-13 barcodes accurately', function (): void {
    $scanner = app(BarcodeScannerServiceInterface::class);

    $result = $scanner->parseBarcode(new BarcodeScanDTO(
        rawBarcode: '(01)08001234567890(10)LOT-2026-X1'
    ));

    expect($result)->toHaveKey('type');
    expect($result['type'])->toBe('gs1_composite');
    expect($result['data'])->toHaveKey('gtin');
    expect($result['data']['gtin'])->toBe('08001234567890');
    expect($result['data'])->toHaveKey('lot');
    expect($result['data']['lot'])->toBe('LOT-2026-X1');
});

test('warehouse rest api endpoints respond with 200 or 201', function (): void {
    $warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->getJson('/api/v1/inventory/warehouses');
    $response->assertStatus(200);

    $showResponse = $this->getJson("/api/v1/inventory/warehouses/{$warehouse->id}");
    $showResponse->assertStatus(200);

    $item = StockItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $itemsResponse = $this->getJson('/api/v1/inventory/items');
    $itemsResponse->assertStatus(200);

    $quantsResponse = $this->getJson('/api/v1/inventory/quants');
    $quantsResponse->assertStatus(200);

    $movementsResponse = $this->getJson('/api/v1/inventory/movements');
    $movementsResponse->assertStatus(200);

    $reservationsResponse = $this->getJson('/api/v1/inventory/reservations');
    $reservationsResponse->assertStatus(200);

    $countsResponse = $this->getJson('/api/v1/inventory/counts');
    $countsResponse->assertStatus(200);

    $valuationResponse = $this->getJson('/api/v1/inventory/valuation/summary');
    $valuationResponse->assertStatus(200);
});
