<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\StockAllocationPlanner;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->source = logisticsWarehouse(['code' => 'MI', 'name' => 'Milano']);
    $this->destination = logisticsWarehouse(['code' => 'LU', 'name' => 'Lugano']);
    $this->sourceBin = logisticsBin($this->source);
    $this->destinationBin = logisticsBin($this->destination, bin: 'D1');
});

test('a transfer order ships into transit, keeps lots and is received in two receipts', function (): void {
    $item = logisticsItem(['current_moving_average_cost' => '3']);
    $lotA = logisticsLot($item, ['received_at' => now()->subDays(5)]);
    $lotB = logisticsLot($item, ['received_at' => now()->subDay()]);
    wmsReceive($this->sourceBin, $item, '4', $lotA->id);
    wmsReceive($this->sourceBin, $item, '4', $lotB->id);

    $order = $this->postJson('/api/v1/inventory/transfer-orders', [
        'source_warehouse_id' => $this->source->id, 'destination_warehouse_id' => $this->destination->id,
        'lines' => [['stock_item_id' => $item->id, 'quantity' => 6]],
    ])->assertCreated()->assertJsonPath('status', 'draft')->json();

    expect($order['transfer_number'])->toStartWith('TO-');

    $shipped = $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/ship")->assertOk()->assertJsonPath('status', 'in_transit')->json();
    $transit = WarehouseBin::query()->findOrFail($shipped['transit_bin_id']);

    expect(wmsOnHand($this->sourceBin, $item))->toBe('2.0000')
        ->and(wmsOnHand($transit, $item))->toBe('6.0000')
        ->and($shipped['lines'][0]['quantity_in_transit'])->toBe('6.0000')
        ->and(array_column($shipped['lines'][0]['shipped_lots'], 'quantity_shipped'))->toBe(['4.0000', '2.0000'])
        // In-transit stock is not available to orders of the destination.
        ->and(app(StockAllocationPlanner::class)->availability($item, $this->destination))->toBe([]);

    $lineId = $shipped['lines'][0]['id'];
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 5, 'destination_bin_id' => $this->destinationBin->id]]])
        ->assertOk()->assertJsonPath('status', 'partially_received');
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 2]]])->assertStatus(422);
    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/receive")->assertOk()->assertJsonPath('status', 'received');

    expect(wmsOnHand($transit, $item))->toBe('0.0000')
        ->and(wmsOnHand($this->destinationBin, $item))->toBe('6.0000')
        ->and($this->destinationBin->quants()->where('lot_id', $lotA->id)->sole()->quantity_on_hand)->toBe('4.0000')
        // Both legs at the moving average: the transfer does not change the stock value.
        ->and(StockMovement::query()->where('origin_document_id', $order['id'])->where('movement_type', 'transfer_out')->sum('total_valuation'))
        ->toEqual(StockMovement::query()->where('origin_document_id', $order['id'])->where('movement_type', 'transfer_in')->sum('total_valuation'));

    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/cancel")->assertStatus(422);
});

test('a transfer that the source cannot cover ships nothing', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->sourceBin, $item, '2');

    $order = $this->postJson('/api/v1/inventory/transfer-orders', [
        'source_warehouse_id' => $this->source->id, 'destination_warehouse_id' => $this->destination->id,
        'lines' => [['stock_item_id' => $item->id, 'quantity' => 3]],
    ])->assertCreated()->json();

    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/ship")->assertStatus(422);

    expect(wmsOnHand($this->sourceBin, $item))->toBe('2.0000')
        ->and(TransferOrder::query()->findOrFail($order['id'])->status)->toBe('draft');

    $this->postJson("/api/v1/inventory/transfer-orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
});

test('transfer orders validate, hide other companies and refuse read-only roles', function (): void {
    $item = logisticsItem();

    $this->postJson('/api/v1/inventory/transfer-orders', ['source_warehouse_id' => $this->source->id, 'destination_warehouse_id' => $this->source->id, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    $this->postJson('/api/v1/inventory/transfer-orders', ['source_warehouse_id' => $this->source->id, 'destination_warehouse_id' => $this->destination->id, 'lines' => []])
        ->assertStatus(422)->assertJsonValidationErrors('lines');

    $foreign = wmsInOtherTenant(static fn (): TransferOrder => TransferOrder::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId()]));
    $this->getJson("/api/v1/inventory/transfer-orders/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/inventory/transfer-orders/{$foreign->id}/ship")->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/inventory/transfer-orders', ['source_warehouse_id' => $this->source->id, 'destination_warehouse_id' => $this->destination->id, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]]])
        ->assertForbidden();
    $this->getJson('/api/v1/inventory/transfer-orders')->assertOk();
});
