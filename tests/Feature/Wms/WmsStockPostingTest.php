<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Wms\Contracts\WmsStockGateway;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Services\InventoryWmsStockGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Posting '.Str::random(4), 'legal_name' => 'Posting Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->operator = User::create(['name' => 'Operator '.Str::random(4), 'email' => Str::random(10).'@wms-posting.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $this->operator->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->operator);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);
    $this->warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->item = postingItem($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function postingItem(Tenant $tenant): StockItem
{
    $category = StockItemCategory::factory()->create(['tenant_id' => $tenant->id]);

    return StockItem::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
}

function postingBin(Tenant $tenant, Warehouse $warehouse): WarehouseBin
{
    $zone = WarehouseZone::factory()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $tenant->id, 'warehouse_aisle_id' => $aisle->id]);

    return WarehouseBin::factory()->create(['tenant_id' => $tenant->id, 'warehouse_rack_id' => $rack->id, 'bin_type' => 'storage']);
}

function postingStock(Tenant $tenant, WarehouseBin $bin, StockItem $item, string $quantity): void
{
    DB::table('stock_quants')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'warehouse_bin_id' => $bin->id, 'stock_item_id' => $item->id,
        'quantity_on_hand' => $quantity, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function postingOnHand(WarehouseBin $bin, StockItem $item): string
{
    return bcadd((string) DB::table('stock_quants')->where('warehouse_bin_id', $bin->id)->where('stock_item_id', $item->id)->sum('quantity_on_hand'), '0', 4);
}

/**
 * Creates a task through the API and starts it.
 *
 * @param  array<string, mixed>  $attributes
 */
function postingStartedTask(string $type, StockItem $item, Warehouse $warehouse, array $attributes = []): string
{
    $id = test()->postJson('/api/v1/wms/tasks', [
        'warehouse_id' => $warehouse->id, 'task_type' => $type, 'stock_item_id' => $item->id, 'planned_quantity' => '10', ...$attributes,
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/wms/tasks/$id/start")->assertOk();

    return $id;
}

function postingMovements(string $taskId): int
{
    return DB::table('stock_movements')->where('origin_document_type', InventoryWmsStockGateway::OriginType)->where('origin_document_id', $taskId)->count();
}

test('confirming a move task posts one double-entry movement into the inventory and stores its reference', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $destination = postingBin($this->tenant, $this->warehouse);
    postingStock($this->tenant, $source, $this->item, '25');

    $id = postingStartedTask('move', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $destination->id]);
    $response = $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertOk()->assertJsonPath('data.status', 'completed');

    expect(postingOnHand($source, $this->item))->toBe('15.0000')
        ->and(postingOnHand($destination, $this->item))->toBe('10.0000')
        ->and(postingMovements($id))->toBe(1);

    $task = WmsTask::query()->findOrFail($id);
    $movement = DB::table('stock_movements')->where('origin_document_id', $id)->first();

    expect($task->stock_movement_id)->toBe($movement->id)
        ->and($task->stock_posting_key)->toBe('wms_task:'.$id)
        ->and($task->stock_posted_at)->not->toBeNull()
        ->and($movement->source_location_id)->toBe($source->id)
        ->and($movement->destination_location_id)->toBe($destination->id);
    $response->assertJsonPath('data.stock_movement_id', $movement->id);
});

test('posting is idempotent: a retried confirmation and a repeated gateway call never post twice', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $destination = postingBin($this->tenant, $this->warehouse);
    postingStock($this->tenant, $source, $this->item, '25');

    $id = postingStartedTask('replenish', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $destination->id]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertOk();
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertUnprocessable()->assertJsonValidationErrors(['status']);

    // A retry after a lost response: the task is back in progress but already holds its movement.
    WmsTask::query()->whereKey($id)->update(['status' => WmsTask::InProgress, 'completed_at' => null]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertOk();

    $movementId = (string) WmsTask::query()->findOrFail($id)->stock_movement_id;
    $again = app(WmsStockGateway::class)->transfer($id, (string) $this->item->id, (string) $source->id, (string) $destination->id, '10', null);

    expect($again)->toBe($movementId)
        ->and(postingMovements($id))->toBe(1)
        ->and(postingOnHand($source, $this->item))->toBe('15.0000')
        ->and(postingOnHand($destination, $this->item))->toBe('10.0000');
});

test('a movement the inventory refuses fails the confirmation and leaves the task in progress', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $destination = postingBin($this->tenant, $this->warehouse);
    postingStock($this->tenant, $source, $this->item, '4');

    $id = postingStartedTask('move', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $destination->id]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

    $task = WmsTask::query()->findOrFail($id);

    expect($task->status)->toBe(WmsTask::InProgress)
        ->and($task->stock_movement_id)->toBeNull()
        ->and($task->confirmed_quantity)->toBe('0.0000')
        ->and(postingMovements($id))->toBe(0)
        ->and(postingOnHand($source, $this->item))->toBe('4.0000');

    // Once the stock is there, the very same task confirms.
    DB::table('stock_quants')->where('warehouse_bin_id', $source->id)->update(['quantity_on_hand' => 24]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
    expect(postingMovements($id))->toBe(1);
});

test('a gateway that rejects the posting is reported as a validation error and rolls the task back', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $destination = postingBin($this->tenant, $this->warehouse);

    app()->bind(WmsStockGateway::class, fn () => new class implements WmsStockGateway
    {
        public function transfer(string $taskId, string $stockItemId, string $sourceBinId, string $destinationBinId, string $quantity, ?string $userId): string
        {
            throw new BusinessRuleException('Bin frozen by a count.');
        }

        public function receivingBinId(string $warehouseId): string
        {
            return '';
        }
    });

    $id = postingStartedTask('move', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $destination->id]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertUnprocessable()->assertJsonValidationErrors(['quantity'])
        ->assertJsonFragment(['The stock movement was rejected: Bin frozen by a count.']);

    expect(WmsTask::query()->findOrFail($id)->status)->toBe(WmsTask::InProgress);
});

test('a short confirmation posts only the confirmed quantity', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $destination = postingBin($this->tenant, $this->warehouse);
    postingStock($this->tenant, $source, $this->item, '25');

    $id = postingStartedTask('move', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $destination->id]);
    $this->postJson("/api/v1/wms/tasks/$id/short", ['quantity' => '6', 'reason' => 'Only six on the shelf'])->assertOk()->assertJsonPath('data.status', 'short_completed');

    expect(postingOnHand($source, $this->item))->toBe('19.0000')
        ->and(postingOnHand($destination, $this->item))->toBe('6.0000')
        ->and(postingMovements($id))->toBe(1);
});

test('a putaway without a source bin moves the goods out of the receiving bin of the warehouse', function (): void {
    $receiving = WarehouseBin::query()->findOrFail(app(WmsStockGateway::class)->receivingBinId((string) $this->warehouse->id));
    $destination = postingBin($this->tenant, $this->warehouse);
    // The receiving bin is the default one: the lowest code of the warehouse.
    $destination->update(['code' => 'ZZZ-PUTAWAY']);
    postingStock($this->tenant, $receiving, $this->item, '12');

    $id = postingStartedTask('putaway', $this->item, $this->warehouse, ['destination_bin_id' => $destination->id]);
    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertOk();

    expect(postingOnHand($receiving, $this->item))->toBe('2.0000')
        ->and(postingOnHand($destination, $this->item))->toBe('10.0000')
        ->and(postingMovements($id))->toBe(1);
});

test('a pick moves stock only towards a staging bin, and a count never moves stock', function (): void {
    $source = postingBin($this->tenant, $this->warehouse);
    $staging = postingBin($this->tenant, $this->warehouse);
    postingStock($this->tenant, $source, $this->item, '30');

    $toStaging = postingStartedTask('pick', $this->item, $this->warehouse, ['source_bin_id' => $source->id, 'destination_bin_id' => $staging->id]);
    $this->postJson("/api/v1/wms/tasks/$toStaging/confirm")->assertOk();
    expect(postingOnHand($staging, $this->item))->toBe('10.0000')->and(postingMovements($toStaging))->toBe(1);

    $issued = postingStartedTask('pick', $this->item, $this->warehouse, ['source_bin_id' => $source->id]);
    $this->postJson("/api/v1/wms/tasks/$issued/confirm")->assertOk()->assertJsonPath('data.stock_movement_id', null);

    $count = postingStartedTask('count', $this->item, $this->warehouse, ['source_bin_id' => $source->id]);
    $this->postJson("/api/v1/wms/tasks/$count/confirm")->assertOk()->assertJsonPath('data.stock_movement_id', null);

    expect(postingMovements($issued))->toBe(0)
        ->and(postingMovements($count))->toBe(0)
        ->and(postingOnHand($source, $this->item))->toBe('20.0000');
});
