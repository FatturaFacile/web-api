<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
});

test('the stock item list and detail report the quantity on hand and available over every bin', function (): void {
    $item = logisticsItem();
    wmsReceive($this->bin, $item, '7');

    $list = $this->getJson('/api/v1/inventory/items')->assertOk();
    expect(array_keys($list->json()))->toBe(['data', 'links', 'meta'])
        ->and(array_keys($list->json('meta')))->toContain('current_page', 'last_page', 'per_page', 'total', 'links', 'path');

    $row = collect($list->json('data'))->firstWhere('id', $item->id);
    expect($row['total_on_hand'])->toEqual(7)->and($row['total_available'])->toEqual(7);

    $detail = $this->getJson("/api/v1/inventory/items/{$item->id}")->assertOk();
    expect($detail->json('total_on_hand'))->toEqual(7)->and($detail->json('quants.0.quantity'))->toEqual(7);
});

test('the ledger lists answer with the paginator document and the bare resource on create', function (): void {
    $item = logisticsItem();
    wmsReceive($this->bin, $item, '3');

    foreach (['quants', 'movements', 'reservations', 'pick-lists', 'counts', 'adjustments', 'warehouses', 'lots'] as $list) {
        $response = $this->getJson("/api/v1/inventory/{$list}")->assertOk();
        expect(array_keys($response->json()))->toBe(['data', 'links', 'meta'], $list);
    }

    $quant = $this->getJson('/api/v1/inventory/quants')->json('data.0');
    expect($quant)->toHaveKeys(['id', 'warehouse_bin_id', 'stock_item_id', 'lot_id', 'quantity', 'reserved_quantity', 'available_quantity']);
});

test('the lookup of a code takes code and the optional warehouse filter only', function (): void {
    $this->getJson('/api/v1/inventory/barcode/lookup?barcode=123')->assertStatus(422)->assertJsonValidationErrors('code');
});
