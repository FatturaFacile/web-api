<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\PutawayRule;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseZone;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse(['code' => 'WH01']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function topologyBin(object $test, string $bin, array $attributes = [], string $zoneType = 'storage', string $zone = 'Z1'): WarehouseBin
{
    $created = logisticsBin($test->warehouse, $zoneType, bin: $bin, zone: $zone);
    $created->update($attributes);

    return $created->fresh();
}

test('bulk generation lays out the hierarchy with scannable paths, in one batch', function (): void {
    $this->postJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/locations/bulk", [
        'zone_code' => 'PICK', 'aisles' => 2, 'racks' => 2, 'shelves' => 2, 'bins' => 3, 'max_weight_kg' => 100,
    ])->assertCreated()->assertJsonPath('created', 24);

    expect(WarehouseBin::query()->count())->toBe(24)
        ->and(WarehouseBin::query()->where('full_path_code', 'WH01-PICK-A02-R01-S02-B03')->exists())->toBeTrue();

    $tree = $this->getJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/tree")->assertOk()->json('data');
    expect($tree)->toHaveCount(1)
        ->and($tree[0]['children'])->toHaveCount(2)
        ->and($tree[0]['children'][0]['children'][0]['children'])->toHaveCount(6);

    // The same layout again collides with the existing barcodes and creates nothing.
    $this->postJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/locations/bulk", ['zone_code' => 'PICK', 'aisles' => 1, 'racks' => 1, 'shelves' => 1, 'bins' => 1])->assertStatus(422);
    $this->postJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/locations/bulk", ['zone_code' => 'BIG', 'aisles' => 100, 'racks' => 100, 'shelves' => 1, 'bins' => 1])->assertStatus(422);
    expect(WarehouseBin::query()->count())->toBe(24);
});

test('bulk generation is a manager decision', function (): void {
    logisticsUser($this->tenant, 'warehouse');

    $this->postJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/locations/bulk", ['zone_code' => 'Z', 'aisles' => 1, 'racks' => 1, 'shelves' => 1, 'bins' => 1])->assertForbidden();
});

test('putaway skips bins that forbid mixing SKUs or lots and reports utilisation', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'weight_kg' => '2', 'volume_m3' => '0.01']);
    $other = logisticsItem(['tracking_type' => 'none']);
    $exclusive = topologyBin($this, 'B1', ['allow_mixed_skus' => false, 'pick_sequence' => 1]);
    $open = topologyBin($this, 'B2', ['max_weight_kg' => 100, 'pick_sequence' => 2]);
    wmsReceive($exclusive, $other, '1');

    $suggestions = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'quantity' => 10])
        ->assertOk()->json('data');

    expect(array_column($suggestions, 'bin_id'))->toBe([(string) $open->id])
        ->and($suggestions[0]['weight_utilization_pct'])->toEqual(20.0)
        ->and($suggestions[0]['reasons'])->not->toBeEmpty();
});

test('a bin that forbids mixed lots only accepts the lot it already holds', function (): void {
    $item = logisticsItem();
    $lotA = logisticsLot($item);
    $lotB = logisticsLot($item);
    $single = topologyBin($this, 'B1', ['allow_mixed_lots' => false, 'pick_sequence' => 1]);
    wmsReceive($single, $item, '5', $lotA->id);

    $forB = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'quantity' => 1, 'lot_id' => $lotB->id])->assertOk()->json('data');
    $forA = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'quantity' => 1, 'lot_id' => $lotA->id])->assertOk()->json('data');

    expect($forB)->toBe([])
        ->and(array_column($forA, 'bin_id'))->toBe([(string) $single->id])
        ->and($forA[0]['reasons'])->toContain('Consolidates with the same item and lot');
});

test('class A stock goes close to the shipping dock and class C close to the receiving dock', function (): void {
    $dockOut = topologyBin($this, 'OUT', ['coord_x' => 0, 'coord_y' => 0], 'shipping', 'SHIP');
    $dockIn = topologyBin($this, 'IN', ['coord_x' => 100, 'coord_y' => 0], 'receiving', 'RECV');
    $near = topologyBin($this, 'NEAR', ['coord_x' => 5, 'coord_y' => 0, 'pick_sequence' => 9]);
    $far = topologyBin($this, 'FAR', ['coord_x' => 95, 'coord_y' => 0, 'pick_sequence' => 1]);

    $a = logisticsItem(['tracking_type' => 'none', 'abc_class' => 'A']);
    $c = logisticsItem(['tracking_type' => 'none', 'abc_class' => 'C']);

    $forA = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $a->id, 'quantity' => 1])->assertOk()->json('data');
    $forC = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $c->id, 'quantity' => 1])->assertOk()->json('data');

    expect($forA[0]['bin_id'])->toBe((string) $near->id)
        ->and($forA[0]['distance_to_dock'])->toEqual(5.0)
        ->and($forC[0]['bin_id'])->toBe((string) $far->id);
    expect([$dockOut, $dockIn])->toHaveCount(2);
});

test('rules can be limited by ABC class and temperature, and zones must match the requested temperature', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'abc_class' => 'A']);
    $ambient = topologyBin($this, 'AMB');
    $cold = topologyBin($this, 'COLD', zone: 'Z-COLD');
    WarehouseZone::query()->where('code', 'Z-COLDstorage')->update(['temperature_control' => 'chilled']);

    PutawayRule::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'target_bin_id' => $ambient->id, 'strategy' => 'fixed_location', 'abc_class' => 'C', 'priority' => 1]);

    // The class C rule does not apply to a class A item: it falls back to any storable bin that fits.
    $suggested = $this->postJson('/api/v1/inventory/putaway/suggest', ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'quantity' => 1, 'temperature_control' => 'chilled'])->assertOk()->json('data');

    expect(array_column($suggested, 'bin_id'))->toBe([(string) $cold->id]);
    $this->postJson('/api/v1/inventory/putaway-rules', ['warehouse_id' => $this->warehouse->id, 'bin_type' => 'storage', 'strategy' => 'consolidate', 'abc_class' => 'X'])->assertStatus(422)->assertJsonValidationErrors('abc_class');
});

test('location labels come as ZPL and PDF', function (): void {
    $bin = topologyBin($this, 'B1');
    $bin->update(['full_path_code' => 'WH01-Z1-A1-R1-B1']);

    $zpl = $this->postJson('/api/v1/inventory/labels/locations', ['bin_ids' => [$bin->id], 'format' => 'zpl', 'size' => '50x25'])->assertOk();
    expect($zpl->getContent())->toContain('^XA')->toContain('WH01 > Z1 > A1 > R1 > B1')->toContain($bin->barcode)->toContain('^BC')->toContain('^BQ');

    $pdf = $this->postJson('/api/v1/inventory/labels/locations', ['bin_ids' => [$bin->id], 'format' => 'pdf'])->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf');

    $this->postJson('/api/v1/inventory/labels/locations', ['bin_ids' => [], 'format' => 'zpl'])->assertStatus(422);
});

test('the capacity summary and the in-transit monitor read the warehouse state', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'weight_kg' => '2']);
    $bin = topologyBin($this, 'B1', ['max_weight_kg' => 100]);
    wmsReceive($bin, $item, '10');

    $zone = $this->getJson("/api/v1/inventory/warehouses/{$this->warehouse->id}/capacity-summary")->assertOk()->json('data.0');
    expect($zone['occupied_bins'])->toBe(1)->and($zone['weight_utilization_pct'])->toEqual(20.0);

    $destination = logisticsWarehouse(['code' => 'LU']);
    $destinationBin = logisticsBin($destination, bin: 'D1');
    $order = $this->postJson('/api/v1/inventory/transfer-orders', ['source_warehouse_id' => $this->warehouse->id, 'destination_warehouse_id' => $destination->id, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 6]]])->assertCreated()->json();
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/approve")->assertOk();
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/ship")->assertOk();

    $rows = $this->getJson("/api/v1/inventory/in-transit?destination_warehouse_id={$destination->id}")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['outstanding_qty'])->toBe('6.0000');

    $lineId = $rows[0]['line_id'];
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 6, 'destination_bin_id' => $destinationBin->id]]])->assertOk();

    expect($this->getJson('/api/v1/inventory/in-transit')->assertOk()->json('data'))->toBe([]);
});
