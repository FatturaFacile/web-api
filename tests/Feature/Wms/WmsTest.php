<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Wms\Models\WmsTask;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = wmsTenant('Logistica');
    $this->owner = wmsMember($this->tenant, 'owner');
    wmsAct($this->owner, $this->tenant);
    $this->warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

function wmsTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function wmsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@wms-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function wmsAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * A bin of the warehouse with its zone, aisle and rack.
 *
 * @param  array<string, mixed>  $attributes
 */
function wmsBin(Tenant $tenant, Warehouse $warehouse, array $attributes = [], ?WarehouseZone $zone = null): WarehouseBin
{
    $zone ??= WarehouseZone::factory()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $tenant->id, 'warehouse_aisle_id' => $aisle->id]);

    return WarehouseBin::factory()->create(['tenant_id' => $tenant->id, 'warehouse_rack_id' => $rack->id, 'bin_type' => 'storage', ...$attributes]);
}

function wmsItem(Tenant $tenant): StockItem
{
    $category = StockItemCategory::factory()->create(['tenant_id' => $tenant->id]);

    return StockItem::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
}

function wmsStock(Tenant $tenant, WarehouseBin $bin, StockItem $item, string $quantity): void
{
    DB::table('stock_quants')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'warehouse_bin_id' => $bin->id, 'stock_item_id' => $item->id,
        'quantity_on_hand' => $quantity, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function wmsPicks(Tenant $tenant, Warehouse $warehouse, WarehouseBin $bin, StockItem $item, int $count, string $when = '-2 days'): void
{
    $product = Product::create(['tenant_id' => $tenant->id, 'sku' => 'P-'.Str::random(8), 'name' => 'Product']);

    for ($i = 0; $i < $count; $i++) {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'type' => 'out', 'quantity' => 1, 'stock_item_id' => $item->id, 'source_location_id' => $bin->id, 'destination_location_id' => null,
            'movement_type' => 'sale_issue', 'executed_at' => now()->modify($when), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wmsTask(Warehouse $warehouse, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/wms/tasks', [
        'warehouse_id' => $warehouse->id,
        'task_type' => 'pick',
        'stock_item_id' => $overrides['stock_item_id'] ?? wmsItem(Tenant::findOrFail($warehouse->tenant_id))->id,
        'source_bin_id' => $overrides['source_bin_id'] ?? wmsBin(Tenant::findOrFail($warehouse->tenant_id), $warehouse)->id,
        'planned_quantity' => '10',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wmsDoor(Warehouse $warehouse, array $overrides = []): array
{
    return test()->postJson('/api/v1/wms/dock-doors', ['warehouse_id' => $warehouse->id, 'code' => 'D'.random_int(100, 999), ...$overrides])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wmsAppointment(string $doorId, string $from, string $to, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/wms/dock-appointments', [
        'dock_door_id' => $doorId, 'carrier_name' => 'Trans Alpi', 'direction' => 'inbound', 'starts_at' => $from, 'ends_at' => $to, ...$overrides,
    ]);
}

test('dock doors are unique per warehouse, filtered and protected while they have live appointments', function (): void {
    $door = wmsDoor($this->warehouse, ['code' => 'IN-1', 'direction' => 'inbound']);

    $this->postJson('/api/v1/wms/dock-doors', ['warehouse_id' => $this->warehouse->id, 'code' => 'IN-1'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/v1/wms/dock-doors', ['warehouse_id' => $this->warehouse->id, 'code' => 'X', 'direction' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors(['direction']);
    $other = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->postJson('/api/v1/wms/dock-doors', ['warehouse_id' => $other->id, 'code' => 'IN-1'])->assertCreated();

    $this->getJson('/api/v1/wms/dock-doors?warehouse_id='.$this->warehouse->id.'&direction=inbound&search=in-')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.warehouse.id', $this->warehouse->id);
    $this->putJson('/api/v1/wms/dock-doors/'.$door['id'], ['name' => 'Inbound one', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->putJson('/api/v1/wms/dock-doors/'.$door['id'], ['is_active' => true]);

    wmsAppointment($door['id'], '2027-06-16 08:00:00', '2027-06-16 09:00:00')->assertCreated();
    $this->deleteJson('/api/v1/wms/dock-doors/'.$door['id'])->assertUnprocessable()->assertJsonValidationErrors(['id']);
    $this->putJson('/api/v1/wms/dock-doors/'.$door['id'], ['direction' => 'outbound'])->assertUnprocessable()->assertJsonValidationErrors(['direction']);

    $free = wmsDoor($this->warehouse);
    $this->deleteJson('/api/v1/wms/dock-doors/'.$free['id'])->assertNoContent();
    $this->getJson('/api/v1/wms/dock-doors/'.$free['id'])->assertNotFound();
});

test('records stay inside the company and a foreign warehouse is refused', function (): void {
    $task = wmsTask($this->warehouse)->assertCreated()->json('data');
    $door = wmsDoor($this->warehouse);

    $other = wmsTenant('Altra');
    $foreignWarehouse = Warehouse::factory()->create(['tenant_id' => $other->id]);
    wmsAct(wmsMember($other, 'owner'), $other);

    $this->getJson('/api/v1/wms/tasks/'.$task['id'])->assertNotFound();
    $this->getJson('/api/v1/wms/tasks')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/wms/tasks/'.$task['id'].'/start')->assertNotFound();
    $this->getJson('/api/v1/wms/dock-doors/'.$door['id'])->assertNotFound();
    $this->postJson('/api/v1/wms/dock-doors', ['warehouse_id' => $this->warehouse->id, 'code' => 'Z1'])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
    expect($foreignWarehouse->tenant_id)->toBe($other->id);
});

test('a read-only member reads and never writes', function (): void {
    $task = wmsTask($this->warehouse)->assertCreated()->json('data');

    wmsAct(wmsMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/wms/tasks')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/wms/dashboard')->assertOk();
    $this->postJson('/api/v1/wms/tasks', ['warehouse_id' => $this->warehouse->id, 'task_type' => 'count', 'planned_quantity' => '1'])->assertForbidden();
    $this->postJson('/api/v1/wms/tasks/'.$task['id'].'/start')->assertForbidden();
    $this->deleteJson('/api/v1/wms/tasks/'.$task['id'])->assertForbidden();
});

test('a task is validated against its type, its warehouse and its bins', function (): void {
    $item = wmsItem($this->tenant);
    $bin = wmsBin($this->tenant, $this->warehouse);
    $otherWarehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreignBin = wmsBin($this->tenant, $otherWarehouse);
    $locked = wmsBin($this->tenant, $this->warehouse, ['is_locked' => true]);

    $base = ['warehouse_id' => $this->warehouse->id, 'planned_quantity' => '5'];
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'putaway', 'stock_item_id' => $item->id])->assertUnprocessable()->assertJsonValidationErrors(['destination_bin_id']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'pick', 'stock_item_id' => $item->id, 'source_bin_id' => $foreignBin->id])->assertUnprocessable()->assertJsonValidationErrors(['source_bin_id']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'move', 'stock_item_id' => $item->id, 'source_bin_id' => $bin->id, 'destination_bin_id' => $locked->id])->assertUnprocessable()->assertJsonValidationErrors(['destination_bin_id']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'move', 'stock_item_id' => $item->id, 'source_bin_id' => $bin->id, 'destination_bin_id' => $bin->id])->assertUnprocessable()->assertJsonValidationErrors(['destination_bin_id']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'planned_quantity' => '0', 'task_type' => 'count', 'source_bin_id' => $bin->id])->assertUnprocessable()->assertJsonValidationErrors(['planned_quantity']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'count', 'source_bin_id' => $bin->id, 'priority' => 101])->assertUnprocessable()->assertJsonValidationErrors(['priority']);
    $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'count', 'source_bin_id' => $bin->id, 'assigned_to' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);

    $member = wmsMember($this->tenant, 'warehouse');
    $created = $this->postJson('/api/v1/wms/tasks', [...$base, 'task_type' => 'count', 'source_bin_id' => $bin->id, 'assigned_to' => $member->id])
        ->assertCreated()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.remaining_quantity', '5.0000')->assertJsonPath('data.source_bin.id', $bin->id)->json('data');

    $this->putJson('/api/v1/wms/tasks/'.$created['id'], ['priority' => 80, 'assigned_to' => null])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.priority', 80);
    $this->getJson('/api/v1/wms/tasks?task_type=count&status=open&warehouse_id='.$this->warehouse->id)->assertOk()->assertJsonCount(1, 'data');
});

test('a task follows its state machine: assign, start, confirm, short, skip and cancel', function (): void {
    $task = wmsTask($this->warehouse)->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
    $member = wmsMember($this->tenant, 'warehouse');
    $id = $task['id'];

    $this->postJson("/api/v1/wms/tasks/$id/confirm")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/wms/tasks/$id/assign", ['user_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);
    $this->postJson("/api/v1/wms/tasks/$id/assign", ['user_id' => $member->id])->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assigned_to', $member->id);

    // Only the assignee starts an assigned task.
    $this->postJson("/api/v1/wms/tasks/$id/start")->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);
    wmsAct($member, $this->tenant);
    $this->postJson("/api/v1/wms/tasks/$id/start")->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.started_at', now()->toIso8601String());
    $this->putJson("/api/v1/wms/tasks/$id", ['priority' => 10])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->deleteJson("/api/v1/wms/tasks/$id")->assertUnprocessable();

    $this->postJson("/api/v1/wms/tasks/$id/confirm", ['quantity' => '11'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $this->postJson("/api/v1/wms/tasks/$id/confirm", ['quantity' => '9'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $this->postJson("/api/v1/wms/tasks/$id/short", ['quantity' => '10', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $this->postJson("/api/v1/wms/tasks/$id/short", ['quantity' => '4'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/wms/tasks/$id/short", ['quantity' => '4', 'reason' => 'Bin was short'])->assertOk()
        ->assertJsonPath('data.status', 'short_completed')->assertJsonPath('data.confirmed_quantity', '4.0000')->assertJsonPath('data.remaining_quantity', '6.0000')->assertJsonPath('data.short_reason', 'Bin was short');
    $this->postJson("/api/v1/wms/tasks/$id/cancel")->assertUnprocessable();

    $full = wmsTask($this->warehouse)->assertCreated()->json('data');
    $this->postJson('/api/v1/wms/tasks/'.$full['id'].'/start')->assertOk();
    $this->postJson('/api/v1/wms/tasks/'.$full['id'].'/confirm')->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.confirmed_quantity', '10.0000')->assertJsonPath('data.remaining_quantity', '0.0000');

    $cancelled = wmsTask($this->warehouse)->assertCreated()->json('data');
    $this->postJson('/api/v1/wms/tasks/'.$cancelled['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson('/api/v1/wms/tasks/'.$cancelled['id'])->assertNoContent();
});

test('skipping sends a task back to the queue with a lower priority and parks it after three skips', function (): void {
    $task = wmsTask($this->warehouse, ['priority' => 50])->assertCreated()->json('data');
    $id = $task['id'];

    $this->postJson("/api/v1/wms/tasks/$id/skip")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/wms/tasks/$id/start")->assertOk();
    $this->postJson("/api/v1/wms/tasks/$id/skip", ['reason' => 'Pallet blocked'])->assertOk()
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.priority', 40)->assertJsonPath('data.assigned_to', null)->assertJsonPath('data.skip_count', 1)->assertJsonPath('data.skip_reason', 'Pallet blocked');
    $this->postJson("/api/v1/wms/tasks/$id/skip", ['reason' => 'Again'])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.priority', 30);
    $this->postJson("/api/v1/wms/tasks/$id/skip", ['reason' => 'Third time'])->assertOk()->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.skip_count', 3);
    $this->postJson("/api/v1/wms/tasks/$id/start")->assertUnprocessable();
});

test('claiming the next task resumes the work in progress, then follows priority and due date and skips planned waves', function (): void {
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data', null);

    $low = wmsTask($this->warehouse, ['priority' => 10])->json('data');
    $lateHigh = wmsTask($this->warehouse, ['priority' => 90, 'due_at' => '2027-06-20T10:00:00+00:00'])->json('data');
    $soonHigh = wmsTask($this->warehouse, ['priority' => 90, 'due_at' => '2027-06-16T10:00:00+00:00'])->json('data');
    $undatedHigh = wmsTask($this->warehouse, ['priority' => 90])->json('data');

    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $soonHigh['id'])->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.assigned_to', $this->owner->id);
    // The task in progress comes back until it is closed.
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $soonHigh['id']);
    $this->postJson('/api/v1/wms/tasks/'.$soonHigh['id'].'/confirm')->assertOk();

    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $lateHigh['id']);
    $this->postJson('/api/v1/wms/tasks/'.$lateHigh['id'].'/confirm')->assertOk();
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $undatedHigh['id']);
    $this->postJson('/api/v1/wms/tasks/'.$undatedHigh['id'].'/skip', ['reason' => 'Later'])->assertOk();

    // A task assigned to someone else is not offered; a planned wave holds its tasks back.
    $someone = wmsMember($this->tenant, 'warehouse');
    $this->postJson('/api/v1/wms/tasks/'.$undatedHigh['id'].'/assign', ['user_id' => $someone->id])->assertOk();
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $low['id']);
    $this->postJson('/api/v1/wms/tasks/'.$low['id'].'/confirm')->assertOk();
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data', null);
});

test('slotting ranks items by pick velocity, recommends moves to free bins of the right type and creates a move task on acceptance', function (): void {
    [$hot, $warm, $tepid, $cold] = [wmsItem($this->tenant), wmsItem($this->tenant), wmsItem($this->tenant), wmsItem($this->tenant)];
    $bulkBin = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'bulk', 'code' => 'BULK-1']);
    $warmBin = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'storage', 'code' => 'STO-1']);
    $tepidBin = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'storage', 'code' => 'STO-2']);
    $coldBin = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'bulk', 'code' => 'BULK-2']);
    $nearPick = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'picking', 'code' => 'PICK-1', 'pick_sequence' => 1]);
    $farPick = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'picking', 'code' => 'PICK-2', 'pick_sequence' => 9]);
    wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'picking', 'code' => 'PICK-LOCKED', 'pick_sequence' => 0, 'is_locked' => true]);
    wmsStock($this->tenant, $bulkBin, $hot, '40');
    wmsStock($this->tenant, $warmBin, $warm, '20');
    wmsStock($this->tenant, $tepidBin, $tepid, '8');
    wmsStock($this->tenant, $coldBin, $cold, '5');
    wmsPicks($this->tenant, $this->warehouse, $bulkBin, $hot, 70);
    wmsPicks($this->tenant, $this->warehouse, $warmBin, $warm, 20);
    wmsPicks($this->tenant, $this->warehouse, $tepidBin, $tepid, 10);
    // Picks older than the period do not count.
    wmsPicks($this->tenant, $this->warehouse, $coldBin, $cold, 5, '-90 days');

    $this->postJson('/api/v1/wms/slotting-recommendations/generate', [])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
    $response = $this->postJson('/api/v1/wms/slotting-recommendations/generate', ['warehouse_id' => $this->warehouse->id, 'period_days' => 30])->assertOk()
        ->assertJsonPath('data.summary.analysed_items', 4)->assertJsonPath('data.summary.recommendations', 2)->assertJsonPath('data.summary.without_free_bin', 0);
    $byItem = collect($response->json('data.recommendations'))->keyBy('stock_item_id');

    // The hottest item (A) goes from bulk to the picking bin closest to dispatch; the locked bin is never chosen. The
    // second item is still class A (70 percent of the picks come before it). The class B and C items already sit where
    // they belong.
    expect($byItem[$hot->id])->toMatchArray(['abc_class' => 'A', 'velocity' => '2.3333', 'status' => 'pending', 'target_bin_type' => 'picking'])
        ->and($byItem[$hot->id]['to_bin']['code'])->toBe('PICK-1')
        ->and($byItem[$hot->id]['from_bin']['code'])->toBe('BULK-1')
        ->and($byItem[$warm->id]['abc_class'])->toBe('A')
        ->and($byItem[$warm->id]['to_bin']['code'])->toBe('PICK-2')
        ->and($byItem->has($tepid->id))->toBeFalse()
        ->and($byItem->has($cold->id))->toBeFalse();
    $this->getJson('/api/v1/wms/slotting-recommendations?warehouse_id='.$this->warehouse->id.'&status=pending&abc_class=A')->assertOk()->assertJsonCount(2, 'data');

    // A rule overrides the defaults (class B goes to picking too); the pending recommendations are replaced.
    $third = wmsBin($this->tenant, $this->warehouse, ['bin_type' => 'picking', 'code' => 'PICK-3', 'pick_sequence' => 5]);
    $this->postJson('/api/v1/wms/slotting-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'B to picking', 'abc_class' => 'B', 'target_bin_type' => 'picking', 'priority' => 10])->assertCreated();
    $second = $this->postJson('/api/v1/wms/slotting-recommendations/generate', ['warehouse_id' => $this->warehouse->id])->assertOk()->assertJsonPath('data.summary.recommendations', 3);
    $recommendations = collect($second->json('data.recommendations'))->keyBy('stock_item_id');
    expect($recommendations[$hot->id]['to_bin']['code'])->toBe('PICK-1')
        ->and($recommendations[$warm->id]['to_bin']['code'])->toBe('PICK-3')
        ->and($recommendations[$tepid->id])->toMatchArray(['abc_class' => 'B', 'target_bin_type' => 'picking'])
        ->and($recommendations[$tepid->id]['to_bin']['code'])->toBe('PICK-2');
    $this->getJson('/api/v1/wms/slotting-recommendations?status=pending')->assertOk()->assertJsonCount(3, 'data');

    $accepted = $this->postJson('/api/v1/wms/slotting-recommendations/'.$recommendations[$hot->id]['id'].'/accept')->assertOk()->assertJsonPath('data.status', 'accepted')->json('data');
    $task = WmsTask::findOrFail($accepted['task_id']);
    expect($task->task_type)->toBe('move')->and($task->source_bin_id)->toBe($bulkBin->id)->and($task->destination_bin_id)->toBe($nearPick->id)
        ->and($task->stock_item_id)->toBe($hot->id)->and($task->planned_quantity)->toBe('40.0000')->and($task->status)->toBe('open');
    $this->postJson('/api/v1/wms/slotting-recommendations/'.$recommendations[$hot->id]['id'].'/accept')->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson('/api/v1/wms/slotting-recommendations/'.$recommendations[$warm->id]['id'].'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson('/api/v1/wms/slotting-recommendations/'.$recommendations[$warm->id]['id'].'/reject')->assertUnprocessable();

    // The stock left the bin meanwhile: the recommendation cannot be accepted any more.
    DB::table('stock_quants')->where('warehouse_bin_id', $tepidBin->id)->update(['quantity_on_hand' => 0]);
    $this->postJson('/api/v1/wms/slotting-recommendations/'.$recommendations[$tepid->id]['id'].'/accept')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    expect([$farPick->id, $third->id])->each->not->toBeNull();
});

test('slotting rules validate their velocity bounds and filters', function (): void {
    $this->postJson('/api/v1/wms/slotting-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'Bad', 'target_bin_type' => 'picking', 'min_velocity' => '5', 'max_velocity' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['min_velocity']);
    $this->postJson('/api/v1/wms/slotting-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'Bad', 'target_bin_type' => 'attic'])->assertUnprocessable()->assertJsonValidationErrors(['target_bin_type']);
    $rule = $this->postJson('/api/v1/wms/slotting-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'Fast', 'abc_class' => 'A', 'target_bin_type' => 'picking', 'min_velocity' => '1.5'])->assertCreated()->json('data');
    $this->putJson('/api/v1/wms/slotting-rules/'.$rule['id'], ['max_velocity' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['min_velocity']);
    $this->getJson('/api/v1/wms/slotting-rules?abc_class=A&is_active=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/wms/slotting-rules?abc_class=B')->assertOk()->assertJsonCount(0, 'data');
});

test('a cross-dock order matches inbound goods to the outbound side, line by line', function (): void {
    $item = wmsItem($this->tenant);
    $second = wmsItem($this->tenant);
    $inDoor = wmsDoor($this->warehouse, ['direction' => 'inbound']);
    $outDoor = wmsDoor($this->warehouse, ['direction' => 'outbound']);
    $payload = [
        'warehouse_id' => $this->warehouse->id, 'inbound_reference' => 'PO-77', 'outbound_reference' => 'SO-88',
        'inbound_door_id' => $inDoor['id'], 'outbound_door_id' => $outDoor['id'],
        'lines' => [['stock_item_id' => $item->id, 'inbound_quantity' => '10'], ['stock_item_id' => $second->id, 'inbound_quantity' => '4', 'outbound_quantity' => '4']],
    ];

    $this->postJson('/api/v1/wms/cross-dock-orders', [...$payload, 'lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson('/api/v1/wms/cross-dock-orders', [...$payload, 'lines' => [['stock_item_id' => $item->id, 'inbound_quantity' => '10'], ['stock_item_id' => $item->id, 'inbound_quantity' => '1']]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.stock_item_id']);
    $this->postJson('/api/v1/wms/cross-dock-orders', [...$payload, 'lines' => [['stock_item_id' => $item->id, 'inbound_quantity' => '3', 'outbound_quantity' => '5']]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.outbound_quantity']);
    $this->postJson('/api/v1/wms/cross-dock-orders', [...$payload, 'inbound_door_id' => $outDoor['id']])->assertUnprocessable()->assertJsonValidationErrors(['inbound_door_id']);
    $otherWarehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->postJson('/api/v1/wms/cross-dock-orders', [...$payload, 'warehouse_id' => $otherWarehouse->id])->assertUnprocessable()->assertJsonValidationErrors(['inbound_door_id']);

    $order = $this->postJson('/api/v1/wms/cross-dock-orders', $payload)->assertCreated()
        ->assertJsonPath('data.number', 'CD-2027-0001')->assertJsonPath('data.status', 'planned')->assertJsonPath('data.match_status', 'partial')->assertJsonCount(2, 'data.lines')->json('data');
    $this->postJson('/api/v1/wms/cross-dock-orders', $payload)->assertCreated()->assertJsonPath('data.number', 'CD-2027-0002');
    $id = $order['id'];
    $lineIds = collect($order['lines'])->pluck('id', 'stock_item_id');

    $this->postJson("/api/v1/wms/cross-dock-orders/$id/ship")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/receive", ['lines' => [['line_id' => $lineIds[$item->id], 'quantity' => '11']]])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    // The second line has 4 matched already: receiving less than that is refused.
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/receive", ['lines' => [['line_id' => $lineIds[$item->id], 'quantity' => '8']]])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/receive", ['lines' => [['line_id' => $lineIds[$item->id], 'quantity' => '8'], ['line_id' => $lineIds[$second->id], 'quantity' => '4']]])->assertOk()
        ->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.0.received_quantity', '8.0000');

    $this->postJson("/api/v1/wms/cross-dock-orders/$id/allocate", ['lines' => [['line_id' => $lineIds[$item->id], 'outbound_quantity' => '9']]])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/allocate", ['lines' => [['line_id' => (string) Str::uuid(), 'outbound_quantity' => '1']]])->assertUnprocessable();
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/allocate", ['lines' => [['line_id' => $lineIds[$item->id], 'outbound_quantity' => '8']]])->assertOk()->assertJsonPath('data.match_status', 'matched');
    $this->putJson("/api/v1/wms/cross-dock-orders/$id", ['notes' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->deleteJson("/api/v1/wms/cross-dock-orders/$id")->assertUnprocessable();

    $this->postJson("/api/v1/wms/cross-dock-orders/$id/ship")->assertOk()->assertJsonPath('data.status', 'shipped');
    $this->postJson("/api/v1/wms/cross-dock-orders/$id/cancel")->assertUnprocessable();

    $this->getJson('/api/v1/wms/cross-dock-orders?status=shipped&search=po-77')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/wms/cross-dock-orders/$id")->assertOk()->assertJsonPath('data.lines.0.stock_item.sku', $item->sku);
});

test('a planned cross-dock order can be edited, replaced its lines and deleted or cancelled', function (): void {
    $item = wmsItem($this->tenant);
    $order = $this->postJson('/api/v1/wms/cross-dock-orders', ['warehouse_id' => $this->warehouse->id, 'inbound_reference' => 'A', 'outbound_reference' => 'B', 'lines' => [['stock_item_id' => $item->id, 'inbound_quantity' => '5']]])->assertCreated()->json('data');

    $this->putJson('/api/v1/wms/cross-dock-orders/'.$order['id'], ['outbound_reference' => 'B2', 'lines' => [['stock_item_id' => $item->id, 'inbound_quantity' => '7', 'outbound_quantity' => '7']]])->assertOk()
        ->assertJsonPath('data.outbound_reference', 'B2')->assertJsonPath('data.match_status', 'matched')->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.inbound_quantity', '7.0000');
    $this->postJson('/api/v1/wms/cross-dock-orders/'.$order['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson('/api/v1/wms/cross-dock-orders/'.$order['id'])->assertNoContent();
    $this->getJson('/api/v1/wms/cross-dock-orders/'.$order['id'])->assertNotFound();
});

test('dock appointments never overlap on a door and respect its direction and status', function (): void {
    $door = wmsDoor($this->warehouse, ['code' => 'OUT-1', 'direction' => 'outbound']);
    $both = wmsDoor($this->warehouse, ['code' => 'ANY-1', 'direction' => 'both']);

    wmsAppointment($door['id'], '2027-06-16 08:00:00', '2027-06-16 09:00:00')->assertUnprocessable()->assertJsonValidationErrors(['direction']);
    wmsAppointment($door['id'], '2027-06-16 09:00:00', '2027-06-16 08:00:00', ['direction' => 'outbound'])->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
    $first = wmsAppointment($door['id'], '2027-06-16 08:00:00', '2027-06-16 09:30:00', ['direction' => 'outbound'])->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.warehouse_id', $this->warehouse->id)->assertJsonPath('data.door.code', 'OUT-1')->json('data');

    wmsAppointment($door['id'], '2027-06-16 09:00:00', '2027-06-16 10:00:00', ['direction' => 'outbound'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    wmsAppointment($door['id'], '2027-06-16 07:00:00', '2027-06-16 08:01:00', ['direction' => 'outbound'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    wmsAppointment($door['id'], '2027-06-16 08:30:00', '2027-06-16 09:00:00', ['direction' => 'outbound'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    // Back to back is fine, so is another door at the same time.
    $next = wmsAppointment($door['id'], '2027-06-16 09:30:00', '2027-06-16 10:30:00', ['direction' => 'outbound'])->assertCreated()->json('data');
    wmsAppointment($both['id'], '2027-06-16 08:00:00', '2027-06-16 09:30:00')->assertCreated();

    // Moving an appointment is checked against the others, but not against itself.
    $this->putJson('/api/v1/wms/dock-appointments/'.$first['id'], ['ends_at' => '2027-06-16 09:15:00'])->assertOk();
    $this->putJson('/api/v1/wms/dock-appointments/'.$first['id'], ['ends_at' => '2027-06-16 10:00:00'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);

    // A cancelled appointment frees its slot.
    $this->postJson('/api/v1/wms/dock-appointments/'.$next['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    wmsAppointment($door['id'], '2027-06-16 09:30:00', '2027-06-16 10:00:00', ['direction' => 'outbound'])->assertCreated();
    $this->putJson('/api/v1/wms/dock-appointments/'.$next['id'], ['carrier_name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->putJson('/api/v1/wms/dock-doors/'.$both['id'], ['is_active' => false])->assertOk();
    wmsAppointment($both['id'], '2027-06-17 08:00:00', '2027-06-17 09:00:00')->assertUnprocessable()->assertJsonValidationErrors(['dock_door_id']);

    $this->getJson('/api/v1/wms/dock-appointments?dock_door_id='.$door['id'].'&status=scheduled&from=2027-06-16&to=2027-06-16')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/wms/dock-appointments?search=trans')->assertOk()->assertJsonCount(4, 'data');
});

test('a dock appointment goes from check-in to completion, or ends as a no-show', function (): void {
    $door = wmsDoor($this->warehouse);
    $future = wmsAppointment($door['id'], '2027-06-16 08:00:00', '2027-06-16 09:00:00')->assertCreated()->json('data');
    $past = wmsAppointment($door['id'], '2027-06-15 06:00:00', '2027-06-15 07:00:00')->assertCreated()->json('data');

    $this->postJson('/api/v1/wms/dock-appointments/'.$future['id'].'/complete')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson('/api/v1/wms/dock-appointments/'.$future['id'].'/no-show')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson('/api/v1/wms/dock-appointments/'.$future['id'].'/check-in')->assertOk()->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.checked_in_at', now()->toIso8601String());
    $this->deleteJson('/api/v1/wms/dock-appointments/'.$future['id'])->assertUnprocessable();
    $this->postJson('/api/v1/wms/dock-appointments/'.$future['id'].'/cancel')->assertUnprocessable();
    $this->postJson('/api/v1/wms/dock-appointments/'.$future['id'].'/complete')->assertOk()->assertJsonPath('data.status', 'completed');

    $this->postJson('/api/v1/wms/dock-appointments/'.$past['id'].'/no-show')->assertOk()->assertJsonPath('data.status', 'no_show');
    $this->deleteJson('/api/v1/wms/dock-appointments/'.$past['id'])->assertNoContent();
});

test('a wave groups the open pick tasks that match its rule, then is released, completed or cancelled', function (): void {
    $high1 = wmsTask($this->warehouse, ['priority' => 90, 'reference' => 'DHL-1'])->json('data');
    $high2 = wmsTask($this->warehouse, ['priority' => 80, 'reference' => 'DHL-2'])->json('data');
    $low = wmsTask($this->warehouse, ['priority' => 20, 'reference' => 'DHL-3'])->json('data');
    $other = wmsTask($this->warehouse, ['priority' => 95, 'reference' => 'UPS-1'])->json('data');
    $count = wmsTask($this->warehouse, ['task_type' => 'count', 'priority' => 99, 'reference' => 'DHL-9', 'stock_item_id' => null])->json('data');

    $this->postJson('/api/v1/wms/wave-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'Bad', 'min_tasks' => 5, 'max_tasks' => 2])->assertUnprocessable()->assertJsonValidationErrors(['min_tasks']);
    $rule = $this->postJson('/api/v1/wms/wave-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'DHL priority', 'min_priority' => 50, 'reference_prefix' => 'DHL', 'min_tasks' => 2, 'max_tasks' => 5])->assertCreated()->json('data');
    $strict = $this->postJson('/api/v1/wms/wave-rules', ['warehouse_id' => $this->warehouse->id, 'name' => 'Needs ten', 'min_tasks' => 10])->assertCreated()->json('data');

    $this->postJson('/api/v1/wms/waves/plan', ['wave_rule_id' => $strict['id']])->assertUnprocessable()->assertJsonValidationErrors(['wave_rule_id']);
    $this->postJson('/api/v1/wms/waves/plan', ['wave_rule_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['wave_rule_id']);

    $wave = $this->postJson('/api/v1/wms/waves/plan', ['wave_rule_id' => $rule['id']])->assertCreated()
        ->assertJsonPath('data.number', 'WV-2027-0001')->assertJsonPath('data.status', 'planned')->assertJsonPath('data.task_count', 2)->json('data');
    $this->getJson('/api/v1/wms/tasks?wave_id='.$wave['id'])->assertOk()->assertJsonCount(2, 'data');
    expect(WmsTask::query()->where('wave_id', $wave['id'])->pluck('id')->sort()->values()->all())->toBe(collect([$high1['id'], $high2['id']])->sort()->values()->all());

    // The planned wave holds its tasks back; the other tasks are still offered.
    $this->postJson('/api/v1/wms/tasks/'.$high1['id'].'/start')->assertUnprocessable()->assertJsonValidationErrors(['wave_id']);
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $count['id']);
    $this->postJson('/api/v1/wms/tasks/'.$count['id'].'/confirm')->assertOk();
    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $other['id']);
    $this->postJson('/api/v1/wms/tasks/'.$other['id'].'/confirm')->assertOk();

    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/complete')->assertUnprocessable();
    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/release')->assertOk()->assertJsonPath('data.status', 'released');
    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/release')->assertUnprocessable();
    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/complete')->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson('/api/v1/wms/tasks/claim-next')->assertOk()->assertJsonPath('data.id', $high1['id']);
    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/cancel')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson('/api/v1/wms/tasks/'.$high1['id'].'/confirm')->assertOk();
    $this->postJson('/api/v1/wms/tasks/'.$high2['id'].'/cancel')->assertOk();
    $this->postJson('/api/v1/wms/waves/'.$wave['id'].'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
    $this->getJson('/api/v1/wms/waves?status=completed')->assertOk()->assertJsonCount(1, 'data');

    // A planned wave can be cancelled and gives its tasks back.
    $again = $this->postJson('/api/v1/wms/waves/plan', ['wave_rule_id' => $strict['id']]);
    $again->assertUnprocessable();
    $fresh = collect(range(1, 2))->map(fn (): array => wmsTask($this->warehouse, ['priority' => 70, 'reference' => 'DHL-X'])->json('data'));
    $planned = $this->postJson('/api/v1/wms/waves/plan', ['wave_rule_id' => $rule['id']])->assertCreated()->assertJsonPath('data.number', 'WV-2027-0002')->json('data');
    $this->postJson('/api/v1/wms/waves/'.$planned['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(WmsTask::query()->whereIn('id', $fresh->pluck('id'))->whereNull('wave_id')->count())->toBe(2)->and($low['id'])->not->toBeNull()->and($count['id'])->not->toBeNull();
});

test('the dashboard counts the throughput, the open work, the short picks and the dock utilization', function (): void {
    $member = wmsMember($this->tenant, 'warehouse');

    // Closed tasks: two picks (one short) and a putaway today; a pick yesterday; one last month (outside the period).
    $closed = static function (string $type, string $status, string $completedAt) use ($member): void {
        WmsTask::factory()->create(['tenant_id' => test()->tenant->id, 'warehouse_id' => test()->warehouse->id, 'task_type' => $type, 'status' => $status, 'completed_at' => $completedAt, 'assigned_to' => $member->id, 'planned_quantity' => '5']);
    };
    $closed('pick', 'completed', '2027-06-15 08:00:00');
    $closed('pick', 'short_completed', '2027-06-15 08:30:00');
    $closed('putaway', 'completed', '2027-06-15 08:45:00');
    $closed('pick', 'completed', '2027-06-14 10:00:00');
    $closed('pick', 'completed', '2027-05-01 10:00:00');
    WmsTask::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'task_type' => 'move', 'status' => 'open', 'planned_quantity' => '1']);
    WmsTask::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'task_type' => 'pick', 'status' => 'in_progress', 'planned_quantity' => '1']);

    // 3 booked hours in 2 days of 10 operating hours on 2 active doors: 180 / 2400 minutes = 7.50 percent.
    $one = wmsDoor($this->warehouse, ['code' => 'D-ONE']);
    $two = wmsDoor($this->warehouse, ['code' => 'D-TWO']);
    wmsDoor($this->warehouse, ['code' => 'D-OFF', 'is_active' => false]);
    wmsAppointment($one['id'], '2027-06-14 08:00:00', '2027-06-14 10:00:00')->assertCreated();
    wmsAppointment($two['id'], '2027-06-15 06:00:00', '2027-06-15 07:00:00')->assertCreated();
    // Cancelled and future appointments do not count.
    $cancelled = wmsAppointment($two['id'], '2027-06-15 07:00:00', '2027-06-15 08:00:00')->assertCreated()->json('data');
    $this->postJson('/api/v1/wms/dock-appointments/'.$cancelled['id'].'/cancel')->assertOk();

    $response = $this->getJson('/api/v1/wms/dashboard?days=2&operating_hours=10')->assertOk()
        ->assertJsonPath('data.period.from', '2027-06-14')->assertJsonPath('data.period.to', '2027-06-15')
        ->assertJsonPath('data.throughput.total', 4)->assertJsonPath('data.throughput.by_type.pick', 3)->assertJsonPath('data.throughput.by_type.putaway', 1)
        ->assertJsonPath('data.open_tasks.total', 2)->assertJsonPath('data.open_tasks.by_status.in_progress', 1)->assertJsonPath('data.open_tasks.by_type.move', 1)
        ->assertJsonPath('data.pick_short_rate_percent', '33.33')
        ->assertJsonPath('data.dock_utilization.overall_percent', '7.50');

    expect($response->json('data.throughput.per_day.1'))->toMatchArray(['date' => '2027-06-15', 'total' => 3, 'pick' => 2, 'putaway' => 1])
        ->and($response->json('data.throughput.per_day.0'))->toMatchArray(['date' => '2027-06-14', 'total' => 1])
        ->and(collect($response->json('data.dock_utilization.doors'))->pluck('utilization_percent', 'code')->all())->toBe(['D-ONE' => '10.00', 'D-TWO' => '5.00']);

    $this->getJson('/api/v1/wms/dashboard?days=0')->assertUnprocessable();
    $other = Warehouse::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson('/api/v1/wms/dashboard?days=2&warehouse_id='.$other->id)->assertOk()->assertJsonPath('data.throughput.total', 0)->assertJsonPath('data.pick_short_rate_percent', null)->assertJsonPath('data.dock_utilization.overall_percent', '0.00');
});
