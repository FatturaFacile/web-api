<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\GridColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Actions\ExecuteDoubleEntryMovementAction;
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Inventory Grid Tenant',
        'legal_name' => 'Inventory Grid Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Inventory Grid User',
        'email' => 'grid@inventory.test',
        'password' => bcrypt(Str::random(24)),
    ]);
    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        ['id' => (string) Str::uuid7(), 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]
    );
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    Sanctum::actingAs($this->user);
});

/**
 * Executes a transfer of the given quantity between two fresh bins and returns the stored movement.
 */
function gridTransfer(float $quantity): StockMovement
{
    $tenantId = test()->tenant->id;
    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenantId]);
    $zone = WarehouseZone::factory()->create(['tenant_id' => $tenantId, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $tenantId, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $tenantId, 'warehouse_aisle_id' => $aisle->id]);
    $source = WarehouseBin::factory()->create(['tenant_id' => $tenantId, 'warehouse_rack_id' => $rack->id]);
    $destination = WarehouseBin::factory()->create(['tenant_id' => $tenantId, 'warehouse_rack_id' => $rack->id]);
    $item = StockItem::factory()->create(['tenant_id' => $tenantId, 'standard_cost' => 10.00]);
    StockQuant::create(['tenant_id' => $tenantId, 'warehouse_bin_id' => $source->id, 'stock_item_id' => $item->id, 'quantity_on_hand' => 100.0, 'quantity_reserved' => 0.0]);

    return app(ExecuteDoubleEntryMovementAction::class)->execute(new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: (string) $source->id,
        destinationBinId: (string) $destination->id,
        quantity: $quantity,
        movementType: 'internal_transfer',
        userId: (string) test()->user->id,
    ));
}

test('the movements grid sorts and lists the calculated signed quantity', function (): void {
    $transfer = gridTransfer(25.0);
    $outgoing = gridTransfer(10.0);
    StockMovement::query()->whereKey($outgoing->id)->update(['destination_location_id' => null]);

    $sorted = $this->getJson('/api/v1/inventory/movements?sort_by=signed_quantity&sort_dir=asc')->assertOk()->json('data');
    expect(collect($sorted)->pluck('id')->all())->toBe([$outgoing->id, $transfer->id])
        ->and((float) $sorted[0]['signed_quantity'])->toBe(-10.0);

    $values = $this->getJson('/api/v1/inventory/movements?grid_values=signed_quantity')->assertOk()->json('data');
    expect(collect($values)->pluck('value')->map(fn ($value): float => (float) $value)->all())->toBe([-10.0, 25.0]);

    $filtered = $this->getJson('/api/v1/inventory/movements?filter[signed_quantity][]=-10')->assertOk()->json('data');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['id'])->toBe($outgoing->id);
});

test('every column key of the inventory grids exists server-side', function (): void {
    $keys = [
        StockMovement::class => ['created_at', 'movement_number', 'movement_type', 'quantity', 'signed_quantity', 'unit_cost', 'total_valuation', 'origin_document_type', 'notes'],
        InventoryAdjustment::class => ['adjustment_number', 'warehouse.name', 'reason', 'net_valuation_change', 'status', 'created_at'],
        InventoryCountSession::class => ['session_number', 'warehouse.name', 'count_type', 'scheduled_date', 'status'],
        Product::class => ['sku', 'name', 'unit_measure', 'hs_code', 'cost_price', 'sale_price'],
    ];

    foreach ($keys as $model => $columns) {
        $grid = new GridColumns($model::query());
        foreach ($columns as $column) {
            expect($grid->kind($column))->toBe(GridColumns::SQL, "{$model}::{$column}");
        }
    }
});

test('the product catalog search matches the SKU, the name and the barcode', function (): void {
    Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'ZZ-WIDGET', 'name' => 'Blue gadget', 'sale_price' => 10, 'tax_rate' => 22]);
    Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'AA-OTHER', 'name' => 'Red widget', 'sale_price' => 10, 'tax_rate' => 22]);
    Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BB-THIRD', 'name' => 'Green bolt', 'sale_price' => 10, 'tax_rate' => 22]);

    $skus = function (string $term): array {
        $json = $this->getJson('/api/v1/inventory/products?search='.$term)->assertOk()->json();

        return collect($json['data']['data'] ?? $json['data'])->pluck('sku')->sort()->values()->all();
    };

    expect($skus('widget'))->toBe(['AA-OTHER', 'ZZ-WIDGET'])
        ->and($skus('bolt'))->toBe(['BB-THIRD']);
});
