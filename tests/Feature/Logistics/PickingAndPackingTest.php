<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Services\PackagingCalculator;
use Modules\Inventory\Services\StockReservationService;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
});

test('a pick list follows fefo and walks the aisles in an s shape', function (): void {
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $other = logisticsItem();
    $firstAisleFar = logisticsBin($this->warehouse, aisle: 'A1', rack: 'R9', bin: 'B1');
    $firstAisleNear = logisticsBin($this->warehouse, aisle: 'A1', rack: 'R1', bin: 'B1');
    $secondAisleNear = logisticsBin($this->warehouse, aisle: 'A2', rack: 'R1', bin: 'B1');
    $secondAisleFar = logisticsBin($this->warehouse, aisle: 'A2', rack: 'R9', bin: 'B1');
    logisticsQuant($firstAisleFar, $item, '2', logisticsLot($item, ['expiration_date' => now()->addDays(5)]));
    logisticsQuant($secondAisleNear, $item, '2', logisticsLot($item, ['expiration_date' => now()->addDays(6)]));
    logisticsQuant($secondAisleFar, $item, '2', logisticsLot($item, ['expiration_date' => now()->addDays(7)]));
    logisticsQuant($firstAisleNear, $other, '1');

    $response = $this->postJson('/api/v1/inventory/pick-lists', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [['stock_item_id' => $item->id, 'quantity' => 6], ['stock_item_id' => $other->id, 'quantity' => 1]],
    ])->assertCreated();

    expect(array_column($response->json('lines'), 'warehouse_bin_id'))->toBe([$firstAisleNear->id, $firstAisleFar->id, $secondAisleFar->id, $secondAisleNear->id])
        ->and(array_column($response->json('lines'), 'sequence_number'))->toBe([1, 2, 3, 4]);
});

test('an explicit pick sequence takes the route over', function (): void {
    $item = logisticsItem();
    $last = logisticsBin($this->warehouse, aisle: 'A1', pickSequence: 20);
    $first = logisticsBin($this->warehouse, aisle: 'A9', pickSequence: 10);
    logisticsQuant($last, $item, '2', logisticsLot($item));
    logisticsQuant($first, $item, '2', logisticsLot($item));

    $response = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 4]]]);

    expect(array_column($response->json('lines'), 'warehouse_bin_id'))->toBe([$first->id, $last->id]);
});

test('a pick list holds its stock, reports what is short and frees it when cancelled', function (): void {
    $item = logisticsItem();
    $quant = logisticsQuant(logisticsBin($this->warehouse), $item, '4');
    $missing = logisticsItem();

    $list = $this->postJson('/api/v1/inventory/pick-lists', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [['stock_item_id' => $item->id, 'quantity' => 6], ['stock_item_id' => $missing->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('shortages.0.short', '2.0000')
        ->assertJsonPath('shortages.1.stock_item_id', $missing->id);

    expect((string) $quant->fresh()->quantity_reserved)->toBe('4.0000');

    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

    expect((string) $quant->fresh()->quantity_reserved)->toBe('0.0000');
});

test('nothing to pick is refused', function (): void {
    $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => logisticsItem()->id, 'quantity' => 1]]])
        ->assertStatus(422);
});

test('guided picking moves the stock, keeps short picks free and closes the list', function (): void {
    $item = logisticsItem();
    $source = logisticsBin($this->warehouse);
    $shipping = logisticsBin($this->warehouse, 'shipping', bin: 'S1');
    $quant = logisticsQuant($source, $item, '10');

    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 6]]])->assertCreated();
    $listId = $list->json('id');

    $next = $this->getJson("/api/v1/inventory/pick-lists/{$listId}/next-stop")
        ->assertOk()
        ->assertJsonPath('remaining_stops', 1)
        ->assertJsonPath('line.bin_barcode', $source->barcode);

    $lineId = $next->json('line.id');

    $this->postJson("/api/v1/inventory/pick-lists/{$listId}/lines/{$lineId}/confirm", ['quantity' => 7])->assertStatus(422);
    $this->postJson("/api/v1/inventory/pick-lists/{$listId}/lines/{$lineId}/confirm", ['quantity' => 5, 'destination_bin_id' => $shipping->id])
        ->assertOk()
        ->assertJsonPath('status', 'shorted');
    $this->postJson("/api/v1/inventory/pick-lists/{$listId}/lines/{$lineId}/confirm", ['quantity' => 5])->assertStatus(422);

    expect((string) $quant->fresh()->quantity_on_hand)->toBe('5.0000')
        ->and((string) $quant->fresh()->quantity_reserved)->toBe('0.0000');

    $this->getJson("/api/v1/inventory/pick-lists/{$listId}")->assertOk()->assertJsonPath('status', 'completed');
    $this->getJson("/api/v1/inventory/pick-lists/{$listId}/next-stop")->assertOk()->assertJsonPath('line', null);
});

test('a pick list follows a reservation without holding the stock twice', function (): void {
    $item = logisticsItem();
    $quant = logisticsQuant(logisticsBin($this->warehouse), $item, '5');
    $reservation = app(StockReservationService::class)->allocate(new StockReservationDTO($item->id, $this->warehouse->id, 'sales_orders', (string) Str::uuid7(), 3.0));

    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'reservation_id' => $reservation->id]]])->assertCreated();

    expect((string) $quant->fresh()->quantity_reserved)->toBe('3.0000');

    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/lines/{$list->json('lines.0.id')}/confirm", ['quantity' => 3])->assertOk();

    expect((string) $quant->fresh()->quantity_reserved)->toBe('0.0000')
        ->and((string) $quant->fresh()->quantity_on_hand)->toBe('2.0000')
        ->and($reservation->fresh()->status)->toBe('picked');
});

test('cartons, pallets and weights are planned from the item master', function (): void {
    $item = logisticsItem(['units_per_carton' => 12, 'cartons_per_pallet' => 10, 'net_weight_kg' => '0.500', 'carton_tare_kg' => '0.250', 'pallet_tare_kg' => '25', 'volume_m3' => '0.00100']);

    $this->postJson('/api/v1/inventory/packing/calculate', ['items' => [['stock_item_id' => $item->id, 'quantity' => 250]]])
        ->assertOk()
        ->assertJsonPath('cartons', 21)
        ->assertJsonPath('pallets', 3)
        ->assertJsonPath('net_weight_kg', '125.000')
        ->assertJsonPath('gross_weight_kg', '205.250')
        ->assertJsonPath('volume_m3', '0.25000');
});

test('an item without packaging data stays loose and keeps its goods weight', function (): void {
    $plan = app(PackagingCalculator::class)->forItem(logisticsItem(['net_weight_kg' => '2.000', 'weight_kg' => '9.000']), '3');

    expect($plan['cartons'])->toBe(0)->and($plan['gross_weight_kg'])->toBe('6.000');
});

test('packing a pick list fills cartons on pallets and updates the delivery note', function (): void {
    $item = logisticsItem(['units_per_carton' => 10, 'cartons_per_pallet' => 2, 'net_weight_kg' => '1.000', 'carton_tare_kg' => '0.500', 'pallet_tare_kg' => '20']);
    logisticsQuant(logisticsBin($this->warehouse), $item, '25', logisticsLot($item));
    $note = DeliveryNote::create(['contact_id' => Contact::create(['company_name' => 'Cliente'])->id, 'ddt_number' => 'DDT-1', 'date' => '2026-11-07']);

    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 25]]]);
    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/lines/{$list->json('lines.0.id')}/confirm", ['quantity' => 25])->assertOk();

    $packed = $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list", ['delivery_note_id' => $note->id])
        ->assertCreated()
        ->assertJsonPath('cartons_count', 3)
        ->assertJsonPath('pallets_count', 2)
        ->assertJsonPath('total_packages', 2)
        ->assertJsonPath('net_weight_kg', '25.000')
        ->assertJsonPath('gross_weight_kg', '66.500');

    $this->getJson("/api/v1/inventory/packing-lists/{$packed->json('id')}")->assertOk()->assertJsonCount(5, 'packages');

    expect((int) $note->fresh()->packages_count)->toBe(2)
        ->and((string) $note->fresh()->gross_weight_kg)->toBe('66.50');

    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list")->assertStatus(422);
});

test('a mixed packing must account for everything that was picked', function (): void {
    $item = logisticsItem();
    logisticsQuant(logisticsBin($this->warehouse), $item, '5');
    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 5]]]);
    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/lines/{$list->json('lines.0.id')}/confirm", ['quantity' => 5])->assertOk();
    $package = fn (int $quantity): array => ['package_type' => 'box', 'tare_weight_kg' => 1, 'items' => [['stock_item_id' => $item->id, 'quantity' => $quantity]]];

    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list", ['packages' => [$package(4)]])->assertStatus(422);
    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list", ['packages' => [$package(6)]])->assertStatus(422);
    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list", ['packages' => [$package(3), $package(2)]])
        ->assertCreated()
        ->assertJsonPath('total_packages', 2);
});

test('a terminal receipt books the lot and expiry of a gs1 code and a resend changes nothing', function (): void {
    $item = logisticsItem(['barcode_ean13' => '8001234567890']);
    $bin = logisticsBin($this->warehouse);
    $scan = ['device_id' => 'T1', 'client_scan_id' => 'S1', 'item_code' => '(01)08001234567890(10)LOT7(17)270630', 'bin_code' => $bin->barcode, 'quantity' => 24];

    $first = $this->postJson('/api/v1/inventory/terminal/receipt', $scan)->assertOk()->assertJsonPath('replayed', false);
    $this->postJson('/api/v1/inventory/terminal/receipt', $scan)
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('movement_id', $first->json('movement_id'));

    $quant = $item->quants()->with('lot')->sole();

    expect((string) $quant->quantity_on_hand)->toBe('24.0000')
        ->and($quant->lot->lot_number)->toBe('LOT7')
        ->and($quant->lot->expiration_date->toDateString())->toBe('2027-06-30');
});

test('a terminal receipt of a lot item needs a lot, and a serial item one serial at a time', function (): void {
    $bin = logisticsBin($this->warehouse);
    $lotItem = logisticsItem();
    $serialItem = logisticsItem(['tracking_type' => 'serial']);

    $this->postJson('/api/v1/inventory/terminal/receipt', ['device_id' => 'T1', 'client_scan_id' => 'A', 'item_code' => $lotItem->sku, 'bin_code' => $bin->barcode, 'quantity' => 1])->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/receipt', ['device_id' => 'T1', 'client_scan_id' => 'B', 'item_code' => $serialItem->sku, 'bin_code' => $bin->barcode, 'quantity' => 2, 'serial_number' => 'SN1'])->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/receipt', ['device_id' => 'T1', 'client_scan_id' => 'C', 'item_code' => $serialItem->sku, 'bin_code' => $bin->barcode, 'quantity' => 1, 'serial_number' => 'SN1'])->assertOk();

    expect($serialItem->serialNumbers()->sole()->serial_number)->toBe('SN1');
});

test('a failed scan can be retried with the same id', function (): void {
    $item = logisticsItem();
    $bin = logisticsBin($this->warehouse);
    $scan = ['device_id' => 'T1', 'client_scan_id' => 'R1', 'item_code' => $item->sku, 'bin_code' => $bin->barcode, 'quantity' => 1];

    $this->postJson('/api/v1/inventory/terminal/receipt', $scan)->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/receipt', $scan + ['lot_number' => 'L1'])->assertOk()->assertJsonPath('replayed', false);
});

test('the terminal checks bin, item and lot of a pick', function (): void {
    $item = logisticsItem();
    $bin = logisticsBin($this->warehouse);
    $lot = logisticsLot($item);
    logisticsQuant($bin, $item, '4', $lot);
    $wrongBin = logisticsBin($this->warehouse, bin: 'B2');
    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 4]]]);
    $line = ['device_id' => 'T1', 'pick_line_id' => $list->json('lines.0.id'), 'item_code' => $item->sku, 'quantity' => 4];

    $this->postJson('/api/v1/inventory/terminal/pick', $line + ['client_scan_id' => 'P1', 'bin_code' => $wrongBin->barcode])->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/pick', ['item_code' => logisticsItem()->sku, 'client_scan_id' => 'P2', 'bin_code' => $bin->barcode] + $line)->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/pick', $line + ['client_scan_id' => 'P3', 'bin_code' => $bin->barcode, 'lot_number' => 'NOPE'])->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/pick', $line + ['client_scan_id' => 'P4', 'bin_code' => $bin->barcode, 'lot_number' => $lot->lot_number])
        ->assertOk()
        ->assertJsonPath('status', 'picked');

    expect(StockPickLine::findOrFail($list->json('lines.0.id'))->picked_by_user_id)->toBe($this->user->id);
});

test('the terminal moves stock between bins', function (): void {
    $item = logisticsItem();
    $from = logisticsBin($this->warehouse);
    $to = logisticsBin($this->warehouse, bin: 'B2');
    $lot = logisticsLot($item);
    logisticsQuant($from, $item, '4', $lot);

    $this->postJson('/api/v1/inventory/terminal/transfer', [
        'device_id' => 'T1', 'client_scan_id' => 'M1', 'item_code' => $lot->lot_number, 'from_bin_code' => $from->barcode, 'to_bin_code' => $to->barcode, 'quantity' => 3,
    ])->assertOk();

    expect((string) $item->quants()->where('warehouse_bin_id', $to->id)->sole()->quantity_on_hand)->toBe('3.0000')
        ->and((string) $item->quants()->where('warehouse_bin_id', $from->id)->sole()->quantity_on_hand)->toBe('1.0000');
});

test('terminal counts feed the session, including goods nobody expected', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => 1]);
    $found = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => 1]);
    $bin = logisticsBin($this->warehouse);
    $quant = logisticsQuant($bin, $item, '10');

    $session = $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => '2026-11-07'])->assertCreated();

    $this->postJson('/api/v1/inventory/terminal/count', ['device_id' => 'T1', 'client_scan_id' => 'C1', 'session_id' => $session->json('id'), 'item_code' => $item->sku, 'bin_code' => $bin->barcode, 'quantity' => 8])
        ->assertOk()
        ->assertJsonPath('discrepancy_quantity', '-2.0000');
    $this->postJson('/api/v1/inventory/terminal/count', ['device_id' => 'T1', 'client_scan_id' => 'C2', 'session_id' => $session->json('id'), 'item_code' => $found->sku, 'bin_code' => $bin->barcode, 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('discrepancy_quantity', '3.0000');

    $this->postJson("/api/v1/inventory/counts/{$session->json('id')}/reconcile")->assertOk();

    expect((string) $quant->fresh()->quantity_on_hand)->toBe('8.0000')
        ->and((string) $found->quants()->sole()->quantity_on_hand)->toBe('3.0000');
});

test('a retroactive stocktake compares with the stock of that day and dates the adjustment there', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    $bin = logisticsBin($this->warehouse);
    $quant = logisticsQuant($bin, $item, '0');

    $this->travelTo(now()->subDays(20));
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'movement_type' => 'PURCHASE_RECEIPT', 'destination_bin_id' => $bin->id, 'unit_cost' => 1])->assertCreated();
    $this->travelBack();
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 4, 'movement_type' => 'SALES_DELIVERY', 'source_bin_id' => $bin->id, 'unit_cost' => 1])->assertCreated();

    $moment = now()->subDays(10)->toIso8601String();

    $this->getJson('/api/v1/inventory/quants/as-of?'.http_build_query(['warehouse_id' => $this->warehouse->id, 'at' => $moment]))
        ->assertOk()
        ->assertJsonPath('data.0.quantity', '10.0000');

    $session = $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => '2026-11-07', 'effective_at' => $moment])->assertCreated();

    $this->postJson('/api/v1/inventory/terminal/count', ['device_id' => 'T1', 'client_scan_id' => 'C1', 'session_id' => $session->json('id'), 'item_code' => $item->sku, 'bin_code' => $bin->barcode, 'quantity' => 9])
        ->assertOk()
        ->assertJsonPath('discrepancy_quantity', '-1.0000');

    $this->postJson("/api/v1/inventory/counts/{$session->json('id')}/reconcile")->assertOk();

    expect((string) $quant->fresh()->quantity_on_hand)->toBe('5.0000')
        ->and(StockMovement::query()->where('movement_type', 'scrap_loss')->sole()->executed_at->toDateString())->toBe(now()->subDays(10)->toDateString());

    $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => '2026-11-07', 'effective_at' => now()->addDay()->toIso8601String()])->assertStatus(422);
});

test('labels print ean13 and code39', function (string $target, string $symbology, string $expected): void {
    $item = logisticsItem(['barcode_ean13' => '4006381333931', 'sku' => 'abc-1']);
    $id = $target === 'item' ? $item->id : logisticsLot($item, ['lot_number' => 'L-9'])->id;

    $this->postJson('/api/v1/inventory/labels/barcode', ['target' => $target, 'id' => $id, 'symbology' => $symbology])
        ->assertOk()
        ->assertJsonPath('value', $expected)
        ->assertJsonPath('symbology', $symbology);
})->with([
    ['item', 'ean13', '4006381333931'],
    ['item', 'code39', 'ABC-1'],
    ['lot', 'code39', 'L-9'],
]);

test('a label refuses values its symbology cannot encode', function (): void {
    $item = logisticsItem(['sku' => 'bad_underscore']);
    $lot = logisticsLot($item, ['lot_number' => 'NOT-EAN']);

    $this->postJson('/api/v1/inventory/labels/barcode', ['target' => 'item', 'id' => $item->id, 'symbology' => 'code39'])->assertStatus(422);
    $this->postJson('/api/v1/inventory/labels/barcode', ['target' => 'lot', 'id' => $lot->id, 'symbology' => 'ean13'])->assertStatus(422);
});

test('another tenant cannot touch pick lists or scan into its bins', function (): void {
    $item = logisticsItem();
    $bin = logisticsBin($this->warehouse);
    logisticsQuant($bin, $item, '4');
    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 1]]]);

    $other = logisticsTenant('Beta');
    logisticsUser($other);

    $this->getJson("/api/v1/inventory/pick-lists/{$list->json('id')}")->assertNotFound();
    $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $this->warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 1]]])->assertStatus(422);
    $this->postJson('/api/v1/inventory/terminal/receipt', ['device_id' => 'T1', 'client_scan_id' => 'X', 'item_code' => $item->sku, 'bin_code' => $bin->barcode, 'quantity' => 1, 'lot_number' => 'L'])->assertStatus(422);
});

test('a role without write access cannot use the terminal', function (): void {
    logisticsUser($this->tenant, 'sales');

    $this->postJson('/api/v1/inventory/terminal/receipt', ['device_id' => 'T1'])->assertForbidden();
    $this->postJson('/api/v1/inventory/packing/calculate', ['items' => []])->assertForbidden();
});
