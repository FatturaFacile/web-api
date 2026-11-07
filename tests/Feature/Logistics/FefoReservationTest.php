<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Services\LotTraceabilityService;
use Modules\Inventory\Services\StockReservationService;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
    $this->reservations = app(StockReservationService::class);
});

function reservationFor(string $itemId, string $warehouseId, float $quantity, ?string $lotId = null): StockReservationDTO
{
    return new StockReservationDTO($itemId, $warehouseId, 'sales_orders', (string) Str::uuid7(), $quantity, lotId: $lotId);
}

test('fefo reserves the lot that expires first, whatever its receipt date', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $late = logisticsLot($item, ['expiration_date' => now()->addDays(90), 'received_at' => now()->subDays(30)]);
    $early = logisticsLot($item, ['expiration_date' => now()->addDays(10), 'received_at' => now()->subDays(1)]);
    $noExpiry = logisticsLot($item, ['received_at' => now()->subDays(60)]);
    $lateQuant = logisticsQuant($this->bin, $item, '10', $late);
    $earlyQuant = logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '6', $early);
    $openQuant = logisticsQuant(logisticsBin($this->warehouse, bin: 'B3'), $item, '10', $noExpiry);

    $reservation = $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 8.0));

    expect((string) $earlyQuant->fresh()->quantity_reserved)->toBe('6.0000')
        ->and((string) $lateQuant->fresh()->quantity_reserved)->toBe('2.0000')
        ->and((string) $openQuant->fresh()->quantity_reserved)->toBe('0.0000')
        ->and($reservation->allocations()->pluck('lot_id')->all())->toEqualCanonicalizing([$early->id, $late->id]);
});

test('fifo reserves the oldest receipt, and the warehouse sets the strategy when the item has none', function (): void {
    $item = logisticsItem();
    $older = logisticsLot($item, ['received_at' => now()->subDays(20), 'expiration_date' => now()->addDays(300)]);
    $newer = logisticsLot($item, ['received_at' => now()->subDays(2), 'expiration_date' => now()->addDays(5)]);
    $olderQuant = logisticsQuant($this->bin, $item, '5', $older);
    $newerQuant = logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '5', $newer);

    $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 3.0));
    expect((string) $olderQuant->fresh()->quantity_reserved)->toBe('3.0000')
        ->and((string) $newerQuant->fresh()->quantity_reserved)->toBe('0.0000');

    $this->warehouse->update(['picking_strategy' => 'fefo']);
    $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 1.0));

    expect((string) $newerQuant->fresh()->quantity_reserved)->toBe('1.0000');
});

test('the strategy of the item wins over the warehouse', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fifo']);
    $this->warehouse->update(['picking_strategy' => 'fefo']);
    $older = logisticsLot($item, ['received_at' => now()->subDays(20), 'expiration_date' => now()->addDays(300)]);
    $newer = logisticsLot($item, ['received_at' => now()->subDays(2), 'expiration_date' => now()->addDays(5)]);
    $olderQuant = logisticsQuant($this->bin, $item, '5', $older);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '5', $newer);

    $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 1.0));

    expect((string) $olderQuant->fresh()->quantity_reserved)->toBe('1.0000');
});

test('expired, quarantined and short-dated lots are never reserved', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo', 'min_remaining_shelf_life_days' => 30]);
    $expired = logisticsLot($item, ['expiration_date' => now()->subDay()]);
    $shortDated = logisticsLot($item, ['expiration_date' => now()->addDays(10)]);
    $quarantined = logisticsLot($item, ['expiration_date' => now()->addDays(100), 'is_quarantined' => true]);
    $good = logisticsLot($item, ['expiration_date' => now()->addDays(200)]);
    logisticsQuant($this->bin, $item, '5', $expired);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '5', $shortDated);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B3'), $item, '5', $quarantined);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B4'), $item, '5', $good);

    expect(fn () => $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 6.0)))
        ->toThrow(StockRuleViolation::class);

    $reservation = $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 5.0));

    expect($reservation->allocations()->pluck('lot_id')->all())->toBe([$good->id]);
});

test('stock in quarantine or transit areas, locked bins and transit warehouses stays out of reach', function (): void {
    $item = logisticsItem();
    logisticsQuant(logisticsBin($this->warehouse, 'quarantine'), $item, '5');
    logisticsQuant(logisticsBin($this->warehouse, 'transit'), $item, '5');
    $locked = logisticsBin($this->warehouse, bin: 'B9');
    $locked->update(['is_locked' => true]);
    logisticsQuant($locked, $item, '5');

    $transit = logisticsWarehouse(['warehouse_type' => 'transit']);
    logisticsQuant(logisticsBin($transit), $item, '5');

    expect(fn () => $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 1.0)))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->reservations->allocate(reservationFor($item->id, $transit->id, 1.0)))->toThrow(StockRuleViolation::class);
});

test('a release gives back the exact quants of the reservation', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $first = logisticsQuant($this->bin, $item, '5', logisticsLot($item, ['expiration_date' => now()->addDays(5)]));
    $second = logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '5', logisticsLot($item, ['expiration_date' => now()->addDays(50)]));
    $held = $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 2.0));
    $released = $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 6.0));

    $this->reservations->release($released);

    expect((string) $first->fresh()->quantity_reserved)->toBe('2.0000')
        ->and((string) $second->fresh()->quantity_reserved)->toBe('0.0000')
        ->and($released->fresh()->status)->toBe('released')
        ->and($held->fresh()->status)->toBe('active');
});

test('a reservation from before allocations existed still releases', function (): void {
    $item = logisticsItem();
    $quant = logisticsQuant($this->bin, $item, '5');
    $reservation = $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 3.0));
    $reservation->allocations()->forceDelete();

    $this->reservations->release($reservation);

    expect((string) $quant->fresh()->quantity_reserved)->toBe('0.0000');
});

test('fractions stay exact across lots', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $first = logisticsQuant($this->bin, $item, '0.1000', logisticsLot($item, ['expiration_date' => now()->addDays(5)]));
    $second = logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $item, '0.2000', logisticsLot($item, ['expiration_date' => now()->addDays(6)]));

    $this->reservations->allocate(reservationFor($item->id, $this->warehouse->id, 0.3));

    expect((string) $first->fresh()->quantity_reserved)->toBe('0.1000')
        ->and((string) $second->fresh()->quantity_reserved)->toBe('0.2000');
});

test('the api reserves in fefo order, reports the allocation and refuses without stock', function (): void {
    logisticsUser($this->tenant);
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $lot = logisticsLot($item, ['expiration_date' => now()->addDays(5)]);
    logisticsQuant($this->bin, $item, '5', $lot);

    $this->getJson("/api/v1/inventory/availability?stock_item_id={$item->id}&warehouse_id={$this->warehouse->id}")
        ->assertOk()
        ->assertJsonPath('strategy', 'fefo')
        ->assertJsonPath('quants.0.lot_id', $lot->id);

    $payload = ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'source_type' => 'sales_orders', 'source_id' => (string) Str::uuid7()];

    $this->postJson('/api/v1/inventory/reservations', $payload)
        ->assertCreated()
        ->assertJsonPath('allocations.0.lot_id', $lot->id);

    $this->getJson('/api/v1/inventory/reservations')->assertOk();

    $this->postJson('/api/v1/inventory/reservations', ['quantity' => 50] + $payload)->assertStatus(422);
});

test('a lot is traced from the packaged product back to the raw material and forward again', function (): void {
    $raw = logisticsItem(['name' => 'Farina']);
    $bulk = logisticsItem(['name' => 'Impasto']);
    $packaged = logisticsItem(['name' => 'Biscotti']);
    $rawLot = logisticsLot($raw, ['supplier_lot_number' => 'SUP-77', 'lot_number' => 'RAW-1']);
    $bulkLot = logisticsLot($bulk, ['lot_number' => 'BULK-1']);
    $packagedLot = logisticsLot($packaged, ['lot_number' => 'PACK-1', 'lot_level' => 'packaged']);

    logisticsUser($this->tenant);
    $this->postJson("/api/v1/inventory/lots/{$bulkLot->id}/components", ['component_lot_id' => $rawLot->id, 'quantity_consumed' => 12.5])->assertCreated();
    $this->postJson("/api/v1/inventory/lots/{$packagedLot->id}/components", ['component_lot_id' => $bulkLot->id])->assertCreated();

    $this->getJson("/api/v1/inventory/lots/{$packagedLot->id}/traceability")
        ->assertOk()
        ->assertJsonPath('lot_number', 'PACK-1')
        ->assertJsonPath('lots.0.lot_number', 'BULK-1')
        ->assertJsonPath('lots.0.lots.0.lot_number', 'RAW-1')
        ->assertJsonPath('lots.0.lots.0.supplier_lot_number', 'SUP-77')
        ->assertJsonPath('lots.0.lots.0.quantity_consumed', '12.5000');

    $this->getJson("/api/v1/inventory/lots/{$rawLot->id}/traceability?direction=forward")
        ->assertOk()
        ->assertJsonPath('lots.0.lots.0.lot_number', 'PACK-1');
});

test('a genealogy cannot loop', function (): void {
    $item = logisticsItem();
    $a = logisticsLot($item);
    $b = logisticsLot($item);
    $service = app(LotTraceabilityService::class);
    $service->link($a, $b);

    expect(fn () => $service->link($b, $a))->toThrow(StockRuleViolation::class)
        ->and(fn () => $service->link($a, $a))->toThrow(StockRuleViolation::class);
});

test('a new lot takes its expiry from the shelf life of the item, and quarantine can be lifted', function (): void {
    logisticsUser($this->tenant);
    $item = logisticsItem(['shelf_life_days' => 100]);

    $response = $this->postJson('/api/v1/inventory/lots', [
        'stock_item_id' => $item->id,
        'lot_number' => 'L-1',
        'manufacturing_date' => '2026-01-01',
    ])->assertCreated()->assertJsonPath('expiration_date', '2026-04-11');

    $id = $response->json('id');
    $this->postJson("/api/v1/inventory/lots/{$id}/quarantine")->assertOk()->assertJsonPath('is_quarantined', true);
    $this->postJson("/api/v1/inventory/lots/{$id}/release")->assertOk()->assertJsonPath('is_quarantined', false);
    $this->getJson('/api/v1/inventory/lots?expiring_within_days=1')->assertOk()->assertJsonPath('data.0.id', $id);
});

test('virtual warehouses and their locations come through the api', function (): void {
    logisticsUser($this->tenant);

    $warehouse = $this->postJson('/api/v1/inventory/warehouses', ['code' => 'V1', 'name' => 'Conto deposito', 'type' => 'virtual', 'is_virtual' => true])
        ->assertCreated()
        ->assertJsonPath('type', 'virtual')
        ->assertJsonPath('is_virtual', true);

    $this->postJson("/api/v1/inventory/warehouses/{$warehouse->json('id')}/locations", [
        'zone_code' => 'Z', 'zone_type' => 'storage', 'aisle_code' => 'A', 'rack_code' => 'R', 'bin_code' => 'B', 'barcode' => 'V1-B',
    ])->assertCreated();
});

test('another tenant reaches neither the lots nor the stock', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $lot = logisticsLot($item);
    logisticsQuant($this->bin, $item, '5', $lot);
    $other = logisticsTenant('Beta Logistica');
    logisticsUser($other);

    $this->getJson("/api/v1/inventory/lots/{$lot->id}")->assertNotFound();
    $this->getJson('/api/v1/inventory/lots')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/inventory/lots/stock')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/inventory/reservations', [
        'stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'source_type' => 'sales_orders', 'source_id' => (string) Str::uuid7(),
    ])->assertNotFound();
});

test('a role without write access reads lots but cannot change them', function (): void {
    $item = logisticsItem();
    $lot = logisticsLot($item);
    logisticsUser($this->tenant, 'sales');

    $this->getJson("/api/v1/inventory/lots/{$lot->id}")->assertOk();
    $this->postJson("/api/v1/inventory/lots/{$lot->id}/quarantine")->assertForbidden();
    expect(StockLot::find($lot->id)->is_quarantined)->toBeFalse()
        ->and(app(TenantContext::class)->getTenantId())->toBe($this->tenant->id);
});
