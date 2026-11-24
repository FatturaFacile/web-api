<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\PutawayRule;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\WarehouseBin;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
});

test('the bin master shows type, capacity and the occupancy computed from the quants', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'weight_kg' => '2.5', 'volume_m3' => '0.01']);
    $bin = logisticsBin($this->warehouse, bin: 'B1');
    wmsReceive($bin, $item, '4');

    $this->patchJson("/api/v1/inventory/bins/{$bin->id}", ['bin_type' => 'picking', 'max_quantity' => 10, 'max_weight_kg' => 30])
        ->assertOk()
        ->assertJsonPath('bin_type', 'picking')
        ->assertJsonPath('max_quantity', '10.0000');

    $this->getJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/bins")
        ->assertOk()
        ->assertJsonPath('data.0.occupancy.quantity', '4.0000')
        ->assertJsonPath('data.0.occupancy.weight_kg', '10.000')
        ->assertJsonPath('data.0.occupancy.items', 1);

    $zones = $this->getJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/zones")->assertOk()->json('data');
    expect($zones)->toHaveCount(1)->and($zones[0]['bins_count'])->toBe(1);

    $this->patchJson("/api/v1/inventory/zones/{$zones[0]['id']}", ['zone_type' => 'quarantine'])->assertOk()->assertJsonPath('zone_type', 'quarantine');
});

test('a new location takes the bin type of its zone and accepts a capacity', function (): void {
    $this->postJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/locations", [
        'zone_code' => 'SHIP', 'zone_type' => 'shipping', 'aisle_code' => 'A', 'rack_code' => 'R', 'bin_code' => 'S1', 'barcode' => 'SHIP-S1', 'max_quantity' => 50,
    ])->assertCreated()->assertJsonPath('bin_type', 'shipping');
});

test('bin master changes are validated, refused to read-only roles and hidden across companies', function (): void {
    $bin = logisticsBin($this->warehouse);

    $this->patchJson("/api/v1/inventory/bins/{$bin->id}", ['bin_type' => 'rocket'])->assertStatus(422)->assertJsonValidationErrors('bin_type');
    $this->patchJson("/api/v1/inventory/bins/{$bin->id}", ['max_quantity' => -1])->assertStatus(422);

    $foreign = wmsInOtherTenant(static fn (): WarehouseBin => logisticsBin(logisticsWarehouse()));
    $this->patchJson("/api/v1/inventory/bins/{$foreign->id}", ['is_locked' => true])->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->patchJson("/api/v1/inventory/bins/{$bin->id}", ['is_locked' => true])->assertForbidden();
    $this->getJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/bins")->assertOk();
});

test('putaway follows the item rule, consolidates and respects the bin capacity', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    $receiving = logisticsBin($this->warehouse, 'receiving', bin: 'IN');
    $full = logisticsBin($this->warehouse, aisle: 'A2', bin: 'B1');
    $holding = logisticsBin($this->warehouse, aisle: 'A2', bin: 'B2');
    $empty = logisticsBin($this->warehouse, aisle: 'A2', bin: 'B3');
    $full->update(['max_quantity' => '5']);
    wmsReceive($full, $item, '5');
    wmsReceive($holding, $item, '1');
    wmsReceive($receiving, $item, '8');

    $this->postJson('/api/v1/inventory/putaway-rules', [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'target_zone_id' => $full->rack->aisle->warehouse_zone_id, 'strategy' => 'consolidate', 'priority' => 1,
    ])->assertCreated();

    $suggestions = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'quantity' => 8])
        ->assertOk()->json('data');

    expect(array_column($suggestions, 'bin_id'))->toBe([$holding->id, $empty->id])
        ->and($suggestions[0]['source'])->toBe('rule');

    $this->postJson('/api/v1/inventory/putaway', ['source_bin_id' => $receiving->id, 'stock_item_id' => $item->id, 'quantity' => 8])
        ->assertCreated()
        ->assertJsonPath('movement_type', 'putaway');

    expect(wmsOnHand($holding, $item))->toBe('9.0000')
        ->and(wmsOnHand($receiving, $item))->toBe('0.0000')
        ->and(StockMovement::query()->where('movement_type', 'putaway')->sole()->destination_location_id)->toBe($holding->id);

    $this->postJson('/api/v1/inventory/putaway', ['source_bin_id' => $holding->id, 'stock_item_id' => $item->id, 'quantity' => 1, 'destination_bin_id' => $full->id])
        ->assertStatus(422);
});

test('putaway never takes stock out of quarantine and never stores into a quarantine bin', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    $quarantine = logisticsBin($this->warehouse, 'quarantine', bin: 'Q1');
    $quarantineTarget = logisticsBin($this->warehouse, 'quarantine', bin: 'Q2');
    $receiving = logisticsBin($this->warehouse, 'receiving', bin: 'IN');
    wmsReceive($quarantine, $item, '3');
    wmsReceive($receiving, $item, '3');

    $this->postJson('/api/v1/inventory/putaway', ['source_bin_id' => $quarantine->id, 'stock_item_id' => $item->id, 'quantity' => 3])->assertStatus(422);
    $this->postJson('/api/v1/inventory/putaway', ['source_bin_id' => $receiving->id, 'stock_item_id' => $item->id, 'quantity' => 3, 'destination_bin_id' => $quarantineTarget->id])->assertStatus(422);
});

test('putaway rules are a manager decision, validated and scoped to the company', function (): void {
    $this->postJson('/api/v1/inventory/putaway-rules', ['warehouse_id' => $this->warehouse->id, 'strategy' => 'consolidate'])
        ->assertStatus(422)->assertJsonValidationErrors('target_bin_id');

    $foreignWarehouse = wmsInOtherTenant(static fn () => logisticsWarehouse());
    $this->postJson('/api/v1/inventory/putaway-rules', ['warehouse_id' => $foreignWarehouse->id, 'bin_type' => 'storage', 'strategy' => 'consolidate'])
        ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    $foreignRule = wmsInOtherTenant(static fn (): PutawayRule => PutawayRule::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId()]));
    $this->deleteJson("/api/v1/inventory/putaway-rules/{$foreignRule->id}")->assertNotFound();

    logisticsUser($this->tenant, 'warehouse');
    $this->postJson('/api/v1/inventory/putaway-rules', ['warehouse_id' => $this->warehouse->id, 'bin_type' => 'storage', 'strategy' => 'consolidate'])->assertForbidden();
    $this->getJson('/api/v1/inventory/putaway-rules')->assertOk();
});
