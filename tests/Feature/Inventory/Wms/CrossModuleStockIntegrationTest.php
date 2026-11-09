<?php

declare(strict_types=1);

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\Jobs\ExpireStaleReservationsJob;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseSuggestion;
use Modules\Procurement\Services\PurchaseSuggestionService;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

/**
 * Integration of the inventory module with Sales and Procurement: reservations of an order released by a delivery note
 * made straight from the sales API, lapsed reservations mirrored back on the order, and purchase suggestions that only
 * count the stock the inventory module considers available.
 */
beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse(['is_primary' => true]);
    $this->storage = logisticsBin($this->warehouse, bin: 'B1');
});

function reservedInWarehouse(string $stockItemId): string
{
    return bcadd((string) StockQuant::query()->where('stock_item_id', $stockItemId)->sum('quantity_reserved'), '0', 4);
}

test('a delivery note made from the sales API releases the reservations the warehouse flow made for the order', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('DDT-RES-1');
    wmsReceive($this->storage, $item, '10');
    $order = wmsSalesOrder([[$product, '4']]);
    $line = $order->items()->sole();

    $reserved = $this->postJson("/api/v1/inventory/sales-orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk()->json('data.0');
    expect($reserved['reserved'])->toBe('4.0000')
        ->and(reservedInWarehouse((string) $item->id))->toBe('4.0000')
        // The warehouse flow does not mirror its reservation on the order line
        ->and((string) $line->fresh()->quantity_reserved)->toBe('0.0000');

    $this->postJson("/api/v1/sales/orders/{$order->id}/delivery-notes", ['lines' => [$line->id => 3], 'warehouse_id' => $this->warehouse->id])->assertCreated();

    // The warehouse reservation was given back before the issue, and the open rest of the order is held again.
    expect(StockReservation::query()->findOrFail($reserved['reservation_id'])->status)->toBe('released')
        ->and(StockReservation::query()->where('reserved_for_id', $line->id)->where('status', 'active')->sum('quantity_reserved'))->toEqual(1)
        ->and(reservedInWarehouse((string) $item->id))->toBe('1.0000')
        ->and((string) $line->fresh()->quantity_reserved)->toBe('1.0000')
        ->and((string) $line->fresh()->quantity_delivered)->toBe('3.0000')
        ->and(wmsOnHand($this->storage, $item))->toBe('7.0000');
});

test('the reservation expiry job releases the stock and updates the reserved quantity and status of the order', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('EXP-RES-1');
    wmsReceive($this->storage, $item, '10');
    $order = wmsSalesOrder([[$product, '4']]);
    $line = $order->items()->sole();

    $this->postJson("/api/v1/sales/orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk()
        ->assertJsonPath('data.inventory_status', 'reserved');
    expect((string) $line->fresh()->quantity_reserved)->toBe('4.0000');

    StockReservation::query()->where('reserved_for_id', $line->id)->update(['expires_at' => now()->subHour()]);
    (new ExpireStaleReservationsJob)->handle(app(StockReservationServiceInterface::class));

    expect(StockReservation::query()->where('reserved_for_id', $line->id)->value('status'))->toBe('cancelled')
        ->and(reservedInWarehouse((string) $item->id))->toBe('0.0000')
        ->and((string) $line->fresh()->quantity_reserved)->toBe('0.0000')
        ->and(SalesOrder::query()->findOrFail($order->id)->inventory_status)->toBe('not_reserved');
});

test('the inventory service exposes the active reservations of a document', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('LOOKUP-1');
    wmsReceive($this->storage, $item, '10');
    $order = wmsSalesOrder([[$product, '2']]);
    $line = $order->items()->sole();
    $this->postJson("/api/v1/sales/orders/{$order->id}/reserve", ['warehouse_id' => $this->warehouse->id])->assertOk();

    $active = app(StockReservationServiceInterface::class)->activeFor('sales_order_item', [(string) $line->id]);

    expect($active)->toHaveCount(1)
        ->and((string) $active->first()->quantity_reserved)->toBe('2.0000')
        ->and(app(StockReservationServiceInterface::class)->activeFor('sales_order_item', []))->toHaveCount(0);
});

test('purchase suggestions ignore stock in quarantine and transit zones and in quarantined lots', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->storage, $item, '4');
    wmsReceive(logisticsBin($this->warehouse, 'quarantine', bin: 'Q1'), $item, '50');
    wmsReceive(logisticsBin($this->warehouse, 'transit', bin: 'T1'), $item, '50');
    $blockedLot = logisticsLot($item, ['is_quarantined' => true]);
    wmsReceive($this->storage, $item, '30', $blockedLot->id);

    $this->postJson('/api/v1/inventory/reorder-rules', [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id,
        'min_stock_level' => 5, 'reorder_point' => 10, 'max_stock_level' => 40, 'economic_order_quantity' => 20,
    ])->assertCreated();

    $suggestion = $this->getJson('/api/v1/inventory/replenishment/suggestions?only_needed=1')->assertOk()->json('data.0');
    $handOff = $this->postJson('/api/v1/inventory/replenishment/hand-off')->assertOk()->json();

    // Inventory and Procurement agree: 4 available, up to 40 => 36.
    expect($suggestion['available'])->toBe('4.0000')
        ->and($handOff['created'])->toBe(1)
        ->and($handOff['data'][0]['quantity'])->toBe('36.0000')
        ->and($suggestion['suggested_quantity'])->toBe('36.0000');
});

test('a purchase suggestion is made once for the open quantity of a sales order line of the tenant', function (): void {
    ['product' => $product, 'item' => $item] = wmsProduct('DROP-1');
    $order = wmsSalesOrder([[$product, '6']]);
    $line = $order->items()->sole();
    $line->forceFill(['quantity_delivered' => '2'])->save();
    $service = app(PurchaseSuggestionService::class);

    $suggestion = $service->suggestForSalesOrderLine((string) $this->tenant->id, (string) $line->id);

    expect($suggestion->source)->toBe(PurchaseSuggestionService::SalesOrderLineSource)
        ->and((string) $suggestion->source_id)->toBe((string) $line->id)
        ->and((string) $suggestion->stock_item_id)->toBe((string) $item->id)
        ->and((string) $suggestion->quantity)->toBe('4.0000')
        ->and($service->suggestForSalesOrderLine((string) $this->tenant->id, (string) $line->id)->id)->toBe($suggestion->id)
        ->and(PurchaseSuggestion::query()->count())->toBe(1);

    $foreignLine = wmsInOtherTenant(static function (): SalesOrderItem {
        ['product' => $foreignProduct] = wmsProduct('DROP-X');

        return wmsSalesOrder([[$foreignProduct, '1']])->items()->sole();
    });

    expect(fn () => $service->suggestForSalesOrderLine((string) $this->tenant->id, (string) $foreignLine->id))
        ->toThrow(ProcurementRuleViolation::class);
});
