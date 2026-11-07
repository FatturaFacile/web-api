<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\InventoryAuditEntry;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Inventory\Services\DoubleEntryMovementService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Quant Slot Srl', 'legal_name' => 'Quant Slot Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->warehouse = Warehouse::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'code' => 'WH-SLOT', 'name' => 'Slot warehouse', 'warehouse_type' => 'standard', 'is_active' => true]);
    $zone = WarehouseZone::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'code' => 'Z1', 'name' => 'Zone']);
    $aisle = WarehouseAisle::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_zone_id' => $zone->id, 'code' => 'A1']);
    $rack = WarehouseRack::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_aisle_id' => $aisle->id, 'code' => 'R1']);
    $this->bin = WarehouseBin::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack->id, 'code' => 'B1', 'barcode' => 'BIN-SLOT-1', 'is_active' => true]);
    $this->item = StockItem::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'sku' => 'SKU-SLOT', 'name' => 'Widget', 'uom' => 'EA', 'standard_cost' => 5, 'current_moving_average_cost' => 5, 'is_active' => true]);
});

/**
 * @return array<string, mixed>
 */
function lotlessQuant(object $test, float $onHand, float $reserved = 0.0): array
{
    $row = [
        'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'warehouse_bin_id' => $test->bin->id,
        'stock_item_id' => $test->item->id, 'lot_id' => null, 'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('stock_quants')->insert($row);

    return $row;
}

test('the database keeps one quant without lot per bin and item', function (): void {
    lotlessQuant($this, 3);

    expect(fn () => DB::transaction(fn () => lotlessQuant($this, 2)))->toThrow(UniqueConstraintViolationException::class);
});

test('receipts into a slot add up on its one lot-less quant and a movement without a user has no audit actor', function (): void {
    $service = app(DoubleEntryMovementService::class);

    foreach ([4.0, 6.0] as $quantity) {
        $service->executeTransfer(new StockTransferDTO(stockItemId: $this->item->id, sourceBinId: null, destinationBinId: $this->bin->id, quantity: $quantity, movementType: 'receipt'));
    }

    $quants = StockQuant::where('warehouse_bin_id', $this->bin->id)->where('stock_item_id', $this->item->id)->get();

    expect($quants)->toHaveCount(1)
        ->and((float) $quants->first()->quantity_on_hand)->toBe(10.0)
        ->and(InventoryAuditEntry::where('stock_item_id', $this->item->id)->pluck('actor_user_id')->unique()->all())->toBe([null]);
});

test('a receipt into a slot whose quant was deleted brings the quant back empty instead of failing', function (): void {
    $quant = lotlessQuant($this, 7);
    StockQuant::whereKey($quant['id'])->firstOrFail()->delete();

    app(DoubleEntryMovementService::class)->executeTransfer(new StockTransferDTO(stockItemId: $this->item->id, sourceBinId: null, destinationBinId: $this->bin->id, quantity: 2, movementType: 'receipt'));

    expect((float) StockQuant::whereKey($quant['id'])->firstOrFail()->quantity_on_hand)->toBe(2.0);
});

test('the migration merges the duplicate lot-less quants of a slot, keeping quantities and reservations', function (): void {
    DB::statement('DROP INDEX uq_quant_slot_nulls');
    $kept = lotlessQuant($this, 4, 1);
    $this->travel(1)->seconds();
    $duplicate = lotlessQuant($this, 6, 2);
    $reservation = (string) Str::uuid7();
    DB::table('stock_reservations')->insert(['id' => $reservation, 'tenant_id' => $this->tenant->id, 'stock_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'reserved_for_type' => 'sales_orders', 'reserved_for_id' => (string) Str::uuid7(), 'quantity_reserved' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('stock_reservation_allocations')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'stock_reservation_id' => $reservation, 'stock_quant_id' => $duplicate['id'], 'warehouse_bin_id' => $this->bin->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2027_04_10_000200_make_stock_quant_slot_unique_without_lot.php'))->up();

    $quants = DB::table('stock_quants')->where('stock_item_id', $this->item->id)->get();

    expect($quants)->toHaveCount(1)
        ->and($quants->first()->id)->toBe($kept['id'])
        ->and((float) $quants->first()->quantity_on_hand)->toBe(10.0)
        ->and((float) $quants->first()->quantity_reserved)->toBe(3.0)
        ->and(DB::table('stock_reservation_allocations')->value('stock_quant_id'))->toBe($kept['id'])
        ->and(fn () => DB::transaction(fn () => lotlessQuant($this, 1)))->toThrow(UniqueConstraintViolationException::class);
});

test('stock movements are indexed by product', function (): void {
    expect(DB::table('pg_indexes')->where('tablename', 'stock_movements')->where('indexname', 'stock_movements_product_id_index')->exists())->toBeTrue();
});
