<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockLotLink;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgProductionOrder;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
});

/**
 * A planned order of the pump through the API.
 *
 * @param  array<string, mixed>  $overrides
 */
function mfgPlannedOrder(object $test, string $quantity = '10', array $overrides = []): string
{
    return (string) $test->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $test->product['fg']->id,
        'target_quantity' => $quantity,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(5)->toDateString(),
        ...$overrides,
    ])->assertCreated()->json('data.id');
}

test('orders are listed, filtered, shown, rescheduled and released with scheduled operations', function (): void {
    $id = mfgPlannedOrder($this);

    $this->getJson('/api/v1/manufacturing/production-orders?status=planned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson('/api/v1/manufacturing/production-orders?status=closed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/manufacturing/production-orders/{$id}")->assertOk()->assertJsonPath('data.bom_id', $this->product['bom_id'])->assertJsonPath('data.routing_id', $this->product['routing_id']);

    $start = now()->addDays(2)->toDateString();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/reschedule", ['planned_start_date' => $start, 'planned_end_date' => now()->addDays(9)->toDateString()])
        ->assertOk()->assertJsonPath('data.planned_start_date', $start);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/reschedule", ['planned_start_date' => $start, 'planned_end_date' => now()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['planned_end_date']);

    $released = $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertOk()->assertJsonCount(2, 'data.lines')->assertJsonCount(2, 'data.operations');
    // OP10 needs 90 minutes, within the 8 hours of the start day; OP20 starts the day after.
    expect(collect($released->json('data.operations'))->pluck('scheduled_date', 'sequence')->all())->toBe([10 => $start, 20 => now()->addDays(3)->toDateString()]);

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
});

test('creation and release validate references, quantities and released BOMs', function (): void {
    $this->postJson('/api/v1/manufacturing/production-orders', [])->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id', 'target_quantity', 'planned_start_date', 'planned_end_date']);

    $foreign = mfgItem($this, 'FG-FOREIGN', 'finished_good', tenant: $this->otherTenant);
    $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $foreign->id, 'target_quantity' => '-1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id', 'target_quantity']);

    $draftOnly = mfgItem($this, 'FG-DRAFT', 'finished_good');
    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $draftOnly->id, 'name' => 'Draft', 'components' => [['component_item_id' => $this->product['steel']->id, 'quantity_per' => '1']]])->assertCreated();
    $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $draftOnly->id, 'target_quantity' => '1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['bom_id']);

    $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $this->product['steel']->id, 'target_quantity' => '1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id']);
});

test('reservations cover only the available stock and issues beyond the stock are refused', function (): void {
    $id = mfgPlannedOrder($this, '60');
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertOk();

    $steelLine = MfgProductionOrder::query()->findOrFail($id)->lines()->where('mfg_item_id', $this->product['steel']->id)->firstOrFail();
    expect($steelLine->required_quantity)->toBe('120.0000')
        ->and($steelLine->allocated_quantity)->toBe('100.0000')
        ->and(StockReservation::query()->whereKey($steelLine->reservation_id)->value('reserved_for_type'))->toBe('mfg_production_order_line');

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['lines' => [['line_id' => $steelLine->id, 'quantity' => '101']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    expect(mfgQuantities($this->product['steel'], $this->warehouse))->toBe(['on_hand' => '100.0000', 'reserved' => '100.0000']);

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['lines' => [['line_id' => $steelLine->id, 'quantity' => '40']]])->assertCreated();
    expect(mfgQuantities($this->product['steel'], $this->warehouse))->toBe(['on_hand' => '60.0000', 'reserved' => '60.0000'])
        ->and($steelLine->refresh()->consumed_quantity)->toBe('40.0000');

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['lines' => [['line_id' => (string) Str::uuid(), 'quantity' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
});

test('receipts are limited to the open quantity, track lots with genealogy and serial numbers', function (): void {
    $lotItem = mfgItem($this, 'FG-LOT', 'finished_good', tracking: 'lot');
    $serialItem = mfgItem($this, 'FG-SER', 'finished_good', tracking: 'serial');
    $steelLot = StockLot::create(['tenant_id' => $this->tenant->id, 'stock_item_id' => $this->product['steel']->stock_item_id, 'lot_number' => 'STEEL-L1', 'received_at' => now()->toDateString()]);
    mfgStock($this->product['steel'], $this->warehouse, '10', '12.00', (string) $steelLot->id);

    foreach ([$lotItem, $serialItem] as $item) {
        $bomId = $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $item->id, 'name' => $item->item_code, 'components' => [['component_item_id' => $this->product['steel']->id, 'quantity_per' => '1']]])->json('data.id');
        $this->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();
    }

    $lotOrder = (string) $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $lotItem->id, 'target_quantity' => '2', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $released = $this->postJson("/api/v1/manufacturing/production-orders/{$lotOrder}/release")->assertOk();
    $this->postJson("/api/v1/manufacturing/production-orders/{$lotOrder}/issues", ['lines' => [['line_id' => $released->json('data.lines.0.id'), 'quantity' => '2', 'lot_id' => $steelLot->id]]])->assertCreated()->assertJsonPath('data.0.lot_id', $steelLot->id);

    $this->postJson("/api/v1/manufacturing/production-orders/{$lotOrder}/receipts", ['quantity' => '2'])->assertUnprocessable()->assertJsonValidationErrors(['lot_number']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$lotOrder}/receipts", ['quantity' => '3', 'lot_number' => 'PUMP-L1'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $receipt = $this->postJson("/api/v1/manufacturing/production-orders/{$lotOrder}/receipts", ['quantity' => '2', 'lot_number' => 'PUMP-L1'])->assertCreated();
    $outputLot = StockLot::query()->findOrFail($receipt->json('data.lot_id'));
    expect($outputLot->lot_number)->toBe('PUMP-L1')
        ->and(StockLotLink::query()->where('lot_id', $outputLot->id)->where('component_lot_id', $steelLot->id)->where('production_order_id', $lotOrder)->exists())->toBeTrue();

    $serialOrder = (string) $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $serialItem->id, 'target_quantity' => '2', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $this->postJson("/api/v1/manufacturing/production-orders/{$serialOrder}/release")->assertOk();
    $this->postJson("/api/v1/manufacturing/production-orders/{$serialOrder}/receipts", ['quantity' => '2', 'serial_numbers' => ['SN-1']])->assertUnprocessable()->assertJsonValidationErrors(['serial_numbers']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$serialOrder}/receipts", ['quantity' => '2', 'serial_numbers' => ['SN-1', 'SN-2']])->assertCreated()->assertJsonCount(2, 'data.serial_number_ids');
    expect(StockSerialNumber::query()->where('stock_item_id', $serialItem->stock_item_id)->where('status', 'in_stock')->count())->toBe(2)
        ->and(mfgQuantities($serialItem, $this->warehouse)['on_hand'])->toBe('2.0000');
});

test('cancellation releases reservations and is refused once material moved', function (): void {
    $id = mfgPlannedOrder($this);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertOk();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/cancel", ['reason' => 'Customer withdrew'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(mfgQuantities($this->product['steel'], $this->warehouse)['reserved'])->toBe('0.0000');
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertUnprocessable();

    $moved = mfgPlannedOrder($this);
    $released = $this->postJson("/api/v1/manufacturing/production-orders/{$moved}/release")->assertOk();
    $line = collect($released->json('data.lines'))->firstWhere('mfg_item_id', $this->product['steel']->id);
    $this->postJson("/api/v1/manufacturing/production-orders/{$moved}/issues", ['lines' => [['line_id' => $line['id'], 'quantity' => '1']]])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$moved}/cancel", ['reason' => 'Too late'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
});

test('operations confirm times and quantities, refuse completed operations and list the order transactions', function (): void {
    $id = mfgPlannedOrder($this);
    $released = $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertOk();
    $op10 = collect($released->json('data.operations'))->firstWhere('sequence', 10)['id'];

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$op10}/confirmations", [])->assertUnprocessable()->assertJsonValidationErrors(['good_quantity']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$op10}/confirmations", ['run_minutes' => '-5'])->assertUnprocessable()->assertJsonValidationErrors(['run_minutes']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$op10}/confirmations", ['run_minutes' => '30', 'labor_minutes' => '60', 'good_quantity' => '4', 'scrap_quantity' => '1', 'scrap_reason' => 'Porosity'])
        ->assertCreated()->assertJsonPath('data.labor_cost', '60.0000')->assertJsonPath('data.overhead_cost', '15.0000');
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$op10}/confirmations", ['run_minutes' => '30', 'good_quantity' => '5', 'is_final' => true])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$op10}/confirmations", ['run_minutes' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['operation']);

    $this->getJson("/api/v1/manufacturing/production-orders/{$id}")->assertOk()->assertJsonPath('data.scrapped_quantity', '1.0000')->assertJsonPath('data.status', 'in_progress');
    // Seal backflushed for 4 + 1 and then 5 units processed at operation 10.
    $this->getJson("/api/v1/manufacturing/production-orders/{$id}/transactions")->assertOk()
        ->assertJsonCount(2, 'data.material_movements')
        ->assertJsonCount(2, 'data.confirmations')
        ->assertJsonPath('data.material_movements.0.movement_type', 'backflush');

    $foreignOperation = (string) Str::uuid();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$foreignOperation}/confirmations", ['run_minutes' => '1'])->assertNotFound();
});

test('the service refuses operations of a planned order and concurrent releases release once', function (): void {
    $id = mfgPlannedOrder($this);
    $order = MfgProductionOrder::query()->findOrFail($id);
    $service = app(ProductionExecutionServiceInterface::class);

    expect(fn () => $service->receiveOutput($order, '1'))->toThrow(ManufacturingOperationException::class);

    $service->releaseOrder($order);
    expect(fn () => $service->releaseOrder($order))->toThrow(ManufacturingOperationException::class)
        ->and(MfgProductionOrder::query()->findOrFail($id)->lines()->count())->toBe(2);
});
