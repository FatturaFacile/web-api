<?php

declare(strict_types=1);

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockReservation;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse(['is_primary' => false]);
    $this->storage = logisticsBin($this->warehouse, bin: 'B1');
    $this->shipping = logisticsBin($this->warehouse, 'shipping', bin: 'S1');
});

test('a sales order reserves what is open and available, following LIFO when the item asks for it', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('LIFO-1');
    $item->update(['picking_strategy' => 'lifo']);
    $older = logisticsLot($item, ['received_at' => now()->subDays(10)]);
    $newer = logisticsLot($item, ['received_at' => now()->subDay()]);
    wmsReceive($this->storage, $item, '3', $older->id);
    wmsReceive($this->storage, $item, '3', $newer->id);
    $order = wmsSalesOrder([[$product, '2']]);

    $lines = $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk()->json('data');

    expect($lines[0]['reserved'])->toBe('2.0000')->and($lines[0]['short'])->toBe('0.0000')
        ->and(StockReservation::query()->findOrFail($lines[0]['reservation_id'])->allocations()->sole()->lot_id)->toBe($newer->id);

    // Nothing more to reserve: the open quantity is already held.
    $again = $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk()->json('data');
    expect($again[0]['reserved'])->toBe('0.0000')->and($again[0]['already_reserved'])->toBe('2.0000');

    // A bigger order gets what is left and reports the rest as short.
    $big = wmsSalesOrder([[$product, '10']]);
    $bigLines = $this->postJson("/api/v1/inventory/sales-orders/{$big->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk()->json('data');
    expect($bigLines[0]['reserved'])->toBe('4.0000')->and($bigLines[0]['short'])->toBe('6.0000');

    $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/release-reservations")->assertOk()->assertJsonPath('released', 1);
});

test('a wave reserves, picks into the staging bin and the shipment confirmation issues the delivery note from there', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('WAVE-1');
    $default = logisticsBin($this->warehouse, aisle: 'A0', bin: 'A0');
    wmsReceive($this->storage, $item, '10');
    $order = wmsSalesOrder([[$product, '4']]);

    $wave = $this->postJson('/api/v1/inventory/waves', ['warehouse_id' => $this->warehouse->id, 'staging_bin_id' => $this->shipping->id, 'sales_order_ids' => [$order->id]])
        ->assertCreated()
        ->assertJsonPath('status', 'released')
        ->json();

    $list = $wave['pick_lists'][0];
    expect($list['origin_document_id'])->toBe($order->id)->and($list['pick_wave_id'])->toBe($wave['id']);

    foreach ($list['lines'] as $line) {
        $this->postJson("/api/v1/inventory/pick-lists/{$list['id']}/lines/{$line['id']}/confirm", ['quantity' => $line['quantity_to_pick']])->assertOk();
    }

    expect(wmsOnHand($this->shipping, $item))->toBe('4.0000')
        ->and(StockPickLine::query()->where('stock_pick_list_id', $list['id'])->value('destination_bin_id'))->toBe($this->shipping->id);

    $shipment = $this->postJson("/api/v1/inventory/pick-lists/{$list['id']}/ship")->assertCreated()->json();

    $note = DeliveryNote::query()->findOrFail($shipment['delivery_note_id']);
    expect($note->sales_order_id)->toBe($order->id)
        ->and($shipment['lines'][0]['quantity'])->toBe('4.0000')
        ->and(wmsOnHand($this->shipping, $item))->toBe('0.0000')
        ->and(wmsOnHand($this->storage, $item))->toBe('6.0000')
        ->and(wmsOnHand($default, $item))->toBe('0.0000')
        ->and((float) $order->items()->first()->quantity_delivered)->toBe(4.0)
        ->and(SalesOrder::query()->findOrFail($order->id)->status)->toBe('shipped')
        ->and(StockReservation::query()->where('reserved_for_id', $order->items()->first()->id)->value('status'))->toBe('fulfilled')
        ->and(StockMovement::query()->where('reference_id', $note->id)->sum('quantity'))->toEqual(4);

    $this->getJson("/api/v1/inventory/waves/{$wave['id']}")->assertOk()->assertJsonPath('status', 'completed');
    $this->postJson("/api/v1/inventory/pick-lists/{$list['id']}/ship")->assertStatus(422);
});

test('a shipment is refused when the picks were not staged, so the stock is never issued twice', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('NOSTAGE-1');
    wmsReceive($this->storage, $item, '5');
    $order = wmsSalesOrder([[$product, '2']]);

    $list = $this->postJson('/api/v1/inventory/pick-lists', [
        'warehouse_id' => $this->warehouse->id, 'origin_document_type' => $order->getMorphClass(), 'origin_document_id' => $order->id,
        'items' => [['stock_item_id' => $item->id, 'quantity' => 2]],
    ])->assertCreated()->json();

    $this->postJson("/api/v1/inventory/pick-lists/{$list['id']}/lines/{$list['lines'][0]['id']}/confirm", ['quantity' => 2])->assertOk();

    $this->postJson("/api/v1/inventory/pick-lists/{$list['id']}/ship")->assertStatus(422);
    expect(DeliveryNote::query()->count())->toBe(0);
});

test('outbound endpoints validate, hide other companies and refuse read-only roles', function (): void {
    ['product' => $product] = wmsProduct('VAL-1');
    $order = wmsSalesOrder([[$product, '1']]);

    $this->postJson('/api/v1/inventory/waves', ['warehouse_id' => $this->warehouse->id, 'staging_bin_id' => $this->storage->id, 'sales_order_ids' => [$order->id]])->assertStatus(422);
    $this->postJson('/api/v1/inventory/waves', ['warehouse_id' => $this->warehouse->id])->assertStatus(422)->assertJsonValidationErrors(['staging_bin_id', 'sales_order_ids']);
    $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/reserve", [])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    $draft = wmsSalesOrder([[$product, '1']], 'draft');
    $this->postJson("/api/v1/inventory/sales-orders/{$draft->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertStatus(422);

    $foreignOrder = wmsInOtherTenant(static function (): SalesOrder {
        ['product' => $foreignProduct] = wmsProduct('FOREIGN-1');

        return wmsSalesOrder([[$foreignProduct, '1']]);
    });
    $this->postJson("/api/v1/inventory/sales-orders/{$foreignOrder->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertNotFound();
    $this->postJson('/api/v1/inventory/waves', ['warehouse_id' => $this->warehouse->id, 'staging_bin_id' => $this->shipping->id, 'sales_order_ids' => [$foreignOrder->id]])
        ->assertStatus(422)->assertJsonValidationErrors('sales_order_ids.0');

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertForbidden();
    $this->getJson('/api/v1/inventory/waves')->assertOk();
});
