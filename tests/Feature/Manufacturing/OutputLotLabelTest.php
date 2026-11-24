<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Services\OutputLabelService;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
});

/**
 * Releases an order of a lot-tracked finished good made from steel and returns its id.
 */
function mfgLotOrder(object $test): string
{
    $lotItem = mfgItem($test, 'FG-GS1', 'finished_good', tracking: 'lot');
    StockItem::query()->whereKey($lotItem->stock_item_id)->update(['barcode_ean13' => '4006381333931', 'shelf_life_days' => 30]);

    $bomId = $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $lotItem->id, 'name' => 'GS1', 'components' => [['component_item_id' => $test->product['steel']->id, 'quantity_per' => '1']]])->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();
    $orderId = (string) $test->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $lotItem->id, 'target_quantity' => '4', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $test->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk();

    return $orderId;
}

test('generate_lot numbers the output lot after the order, sets the expiry and prints a GS1-128 label', function (): void {
    mfgStock($this->product['steel'], $this->warehouse, '10', '12.00');
    $orderId = mfgLotOrder($this);

    $this->getJson("/api/v1/manufacturing/production-orders/{$orderId}/output-label")->assertUnprocessable();

    $receipt = $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/receipts", ['quantity' => '2', 'generate_lot' => true])->assertCreated();
    $lot = StockLot::query()->findOrFail($receipt->json('data.lot_id'));
    expect($lot->expiration_date?->toDateString())->toBe(now()->addDays(30)->toDateString());

    $label = $this->getJson("/api/v1/manufacturing/production-orders/{$orderId}/output-label")->assertOk();
    $label->assertJsonPath('data.lot_number', $lot->lot_number)->assertJsonPath('data.gtin', '04006381333931')->assertJsonPath('data.symbology', 'gs1-128');
    expect($label->json('data.human_readable'))->toBe('(01)04006381333931(17)'.now()->addDays(30)->format('ymd').'(10)'.$lot->lot_number)
        ->and($label->json('data.content'))->toContain('<svg');

    $this->getJson("/api/v1/manufacturing/production-orders/{$orderId}/output-label?format=bmp")->assertUnprocessable()->assertJsonValidationErrors(['format']);
});

test('GTIN-14 carries the correct check digit and refuses a malformed EAN', function (): void {
    $service = app(OutputLabelService::class);

    expect($service->gtin14('4006381333931'))->toBe('04006381333931');
    expect(fn () => $service->gtin14('123'))->toThrow(ManufacturingOperationException::class);
});
