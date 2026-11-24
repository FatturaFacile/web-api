<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\StockLot;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
    $this->item = logisticsItem(['name' => 'Barbera']);
});

test('a batch keeps its certification reference and stock is read by batch', function (): void {
    $lot = $this->postJson('/api/v1/inventory/lots', [
        'stock_item_id' => $this->item->id,
        'lot_number' => 'V2025-A',
        'certification_scheme' => 'Disciplinare interno',
        'certification_reference' => 'CERT-001',
        'certification_date' => '2026-02-01',
    ])->assertCreated()->assertJsonPath('certification_reference', 'CERT-001');

    $other = logisticsLot($this->item, ['lot_number' => 'V2025-B', 'certification_reference' => 'CERT-002']);
    $lotModel = StockLot::findOrFail($lot->json('id'));
    logisticsQuant(logisticsBin($this->warehouse), $this->item, '100', $lotModel);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B2'), $this->item, '50', $lotModel);
    logisticsQuant(logisticsBin($this->warehouse, bin: 'B3'), $this->item, '7', $other);

    $this->getJson('/api/v1/inventory/lots/stock?certification_reference=CERT-001')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lot_number', 'V2025-A')
        ->assertJsonPath('data.0.quantity_on_hand', '150.0000')
        ->assertJsonPath('data.0.certification_scheme', 'Disciplinare interno');

    $this->getJson("/api/v1/inventory/lots/stock?stock_item_id={$this->item->id}&warehouse_id={$this->warehouse->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a certification reference is plain data: no rule looks at it', function (): void {
    $lot = logisticsLot($this->item, ['certification_reference' => 'ANY VALUE 123']);

    $this->patchJson("/api/v1/inventory/lots/{$lot->id}", ['certification_reference' => 'CORRETTO'])
        ->assertOk()
        ->assertJsonPath('certification_reference', 'CORRETTO');

    expect(Schema::hasColumns('stock_lots', ['certification_scheme', 'certification_reference', 'certification_date']))->toBeTrue();
});
