<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\LotTraceabilityService;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
});

test('a handheld resolves a GS1-128 code into the item and lot with their stock', function (): void {
    $item = logisticsItem(['barcode_ean13' => '4006381333931']);
    $lot = logisticsLot($item, ['lot_number' => 'L-2026-01']);
    wmsReceive($this->bin, $item, '7', $lot->id);

    $result = $this->getJson('/api/v1/inventory/barcode/lookup?'.http_build_query(['code' => '(01)04006381333931(10)L-2026-01(17)271200']))->assertOk()->json();

    expect($result['gs1'])->toMatchArray(['gtin' => '04006381333931', 'lot' => 'L-2026-01', 'expiry' => '271200', 'expiry_date' => '2027-12-31'])
        ->and(array_column($result['matches'], 'type'))->toBe(['item', 'lot'])
        ->and($result['matches'][0]['stock'][0]['available'])->toBe('7.0000')
        ->and($result['matches'][1]['lot_id'])->toBe($lot->id);

    $fnc1 = $this->getJson('/api/v1/inventory/barcode/lookup?'.http_build_query(['code' => "]C1010400638133393110L-2026-01\x1D21SN-1"]))->assertOk()->json();
    expect($fnc1['gs1'])->toMatchArray(['gtin' => '04006381333931', 'lot' => 'L-2026-01', 'serial' => 'SN-1']);
});

test('a handheld resolves plain EAN, bin barcodes and SKUs, and nothing for an unknown code', function (): void {
    $item = logisticsItem(['barcode_ean13' => '4006381333931', 'sku' => 'VITE-8']);
    wmsReceive($this->bin, $item, '3', logisticsLot($item)->id);

    expect($this->getJson('/api/v1/inventory/barcode/lookup?code=4006381333931')->json('matches.0.stock_item_id'))->toBe($item->id)
        ->and($this->getJson('/api/v1/inventory/barcode/lookup?code=VITE-8')->json('matches.0.type'))->toBe('item')
        ->and($this->getJson("/api/v1/inventory/barcode/lookup?code={$this->bin->barcode}")->json('matches.0.stock.0.on_hand'))->toBe('3.0000');

    $this->getJson('/api/v1/inventory/barcode/lookup?code=NOPE')->assertOk()->assertJsonPath('matches', []);
    $this->getJson('/api/v1/inventory/barcode/lookup')->assertStatus(422)->assertJsonValidationErrors('code');

    // Another company's codes resolve to nothing.
    wmsInOtherTenant(static fn () => logisticsItem(['sku' => 'FOREIGN-SKU']));
    $this->getJson('/api/v1/inventory/barcode/lookup?code=FOREIGN-SKU')->assertOk()->assertJsonPath('matches', []);
});

test('a recall traces a raw lot forward to the finished lot, its stock and the customers that received it', function (): void {
    $raw = logisticsItem();
    $finished = logisticsItem();
    $rawLot = logisticsLot($raw, ['lot_number' => 'RAW-1']);
    $finishedLot = logisticsLot($finished, ['lot_number' => 'FIN-1']);
    app(LotTraceabilityService::class)->link($finishedLot, $rawLot, ['quantity_consumed' => 2]);
    wmsReceive($this->bin, $finished, '5', $finishedLot->id);

    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $note = DeliveryNote::create(['contact_id' => $customer->id, 'ddt_number' => 'DDT-T-1', 'date' => now()->toDateString(), 'status' => 'issued']);
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(stockItemId: (string) $finished->id, sourceBinId: (string) $this->bin->id, destinationBinId: null, quantity: 2.0, movementType: 'customer_delivery', lotId: (string) $finishedLot->id));
    StockMovement::query()->where('movement_type', 'customer_delivery')->update(['reference_type' => $note->getMorphClass(), 'reference_id' => $note->id]);

    $report = $this->getJson("/api/v1/inventory/lots/{$rawLot->id}/recall")->assertOk()->json();

    expect(array_column($report['affected_lots'], 'lot_number'))->toBe(['FIN-1', 'RAW-1'])
        ->and($report['customers'][0]['contact_id'])->toBe($customer->id)
        ->and($report['totals']['on_hand'])->toBe('3.0000')
        ->and($report['totals']['delivered'])->toBe('2.0000')
        ->and($report['quarantined'])->toBeFalse();

    $this->postJson("/api/v1/inventory/lots/{$rawLot->id}/recall", ['quarantine' => true])->assertOk()->assertJsonPath('quarantined', true);

    expect(StockLot::query()->whereIn('id', [$rawLot->id, $finishedLot->id])->where('is_quarantined', true)->count())->toBe(2)
        ->and($this->getJson('/api/v1/inventory/availability?'.http_build_query(['stock_item_id' => $finished->id, 'warehouse_id' => $this->warehouse->id]))->json('quants'))->toBe([]);
});

test('recalls are hidden across companies, validated and closed to read-only roles', function (): void {
    $lot = logisticsLot(logisticsItem());
    $foreignLot = wmsInOtherTenant(static fn (): StockLot => logisticsLot(logisticsItem()));

    $this->getJson("/api/v1/inventory/lots/{$foreignLot->id}/recall")->assertNotFound();
    $this->postJson("/api/v1/inventory/lots/{$lot->id}/recall", ['quarantine' => 'perhaps'])->assertStatus(422);

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/inventory/lots/{$lot->id}/recall")->assertForbidden();
    $this->getJson("/api/v1/inventory/lots/{$lot->id}/recall")->assertOk();
});

test('the relocation endpoint only moves stock between two bins of one warehouse, at the moving average', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '2']);
    $to = logisticsBin($this->warehouse, bin: 'B2');
    wmsReceive($this->bin, $item, '5');
    $elsewhere = logisticsBin(logisticsWarehouse());

    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'source_bin_id' => $this->bin->id, 'destination_bin_id' => $to->id, 'unit_cost' => 999])
        ->assertCreated()
        ->assertJsonPath('total_valuation', 4);

    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'source_bin_id' => $this->bin->id])
        ->assertStatus(422)->assertJsonValidationErrors('destination_bin_id');
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'movement_type' => 'PURCHASE_RECEIPT', 'source_bin_id' => $this->bin->id, 'destination_bin_id' => $to->id])
        ->assertStatus(422)->assertJsonValidationErrors('movement_type');
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'source_bin_id' => $this->bin->id, 'destination_bin_id' => $elsewhere->id])
        ->assertStatus(422);

    expect(wmsOnHand($this->bin, $item))->toBe('3.0000')->and(wmsOnHand($to, $item))->toBe('2.0000');
});
