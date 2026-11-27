<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Distribution\Models\ConsignmentSettlement;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\TransferOrder;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->consignment, $this->consignmentBin] = drpWarehouse('CON-OUT', 'consignment_out', 'customer_consignment');
    [$this->supplierStock, $this->supplierBin] = drpWarehouse('CON-IN', 'consignment_in', 'vendor_consignment', 'vendor');
    [$this->threePl, $this->threePlBin] = drpWarehouse('3PL', 'third_party_3pl');
    $this->item = drpItem('10');
});

it('sends goods to a customer consignment warehouse with the consignment transfer purpose', function (): void {
    wmsReceive($this->hubBin, $this->item, '30');

    $id = $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->consignment->id, 'auto_approve' => true,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 12]],
    ])->assertCreated()->json('data.id');

    expect(TransferOrder::query()->findOrFail($id)->purpose)->toBe('customer_consignment');

    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    $this->postJson("/api/v1/distribution/transfers/{$id}/receive", ['lines' => []])->assertOk()->assertJsonPath('data.status', 'received');

    $stock = $this->getJson('/api/v1/distribution/consignment/stock?direction=out')->assertOk();
    expect($stock->json('data.0.quantity'))->toBe('12.0000')
        ->and($stock->json('data.0.value'))->toBe('120.00')
        ->and($stock->json('meta.total_value'))->toBe('120.00');
});

it('records the reported consumption, issues the stock and keeps the settlement ready for invoicing', function (): void {
    wmsReceive($this->consignmentBin, $this->item, '10');

    $data = $this->postJson('/api/v1/distribution/consignment/settlements', [
        'warehouse_id' => $this->consignment->id, 'reference' => 'REPORT-05',
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 4, 'unit_price' => 12]],
    ])->assertCreated()->json('data');

    expect($data['settlement_number'])->toStartWith('CS-')
        ->and($data['direction'])->toBe('out')
        ->and($data['status'])->toBe('open')
        ->and($data['total_quantity'])->toBe('4.0000')
        ->and($data['total_value'])->toBe('48.00')
        ->and($data['lines'][0]['line_total'])->toBe('48.00')
        ->and(drpOnHand($this->consignmentBin, $this->item))->toBe('6.0000')
        ->and(StockMovement::query()->where('movement_type', 'consignment_consumption')->where('origin_document_id', $data['id'])->sum('quantity'))->toEqual(4);

    $this->getJson('/api/v1/distribution/consignment/settlements')->assertOk()->assertJsonCount(1, 'data');
    expect(ConsignmentSettlement::query()->count())->toBe(1);
});

it('refuses a consumption above the stock held or on a warehouse that is not on consignment', function (): void {
    wmsReceive($this->consignmentBin, $this->item, '5');
    wmsReceive($this->hubBin, $this->item, '5');

    $this->postJson('/api/v1/distribution/consignment/settlements', ['warehouse_id' => $this->consignment->id, 'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 6]]])->assertStatus(422);
    $this->postJson('/api/v1/distribution/consignment/settlements', ['warehouse_id' => $this->hub->id, 'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 1]]])->assertStatus(422);
    $this->postJson('/api/v1/distribution/consignment/settlements', ['warehouse_id' => $this->consignment->id, 'lines' => []])->assertStatus(422)->assertJsonValidationErrors('lines');

    expect(drpOnHand($this->consignmentBin, $this->item))->toBe('5.0000')
        ->and(ConsignmentSettlement::query()->count())->toBe(0);
});

it('returns the unsold goods to a warehouse of ours through a transfer', function (): void {
    wmsReceive($this->consignmentBin, $this->item, '8');

    $data = $this->postJson('/api/v1/distribution/consignment/returns', [
        'warehouse_id' => $this->consignment->id, 'destination_warehouse_id' => $this->hub->id,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 8]],
    ])->assertCreated()->json('data');

    expect($data['status'])->toBe('draft')
        ->and(TransferOrder::query()->findOrFail($data['id'])->purpose)->toBe('consignment_return');

    $this->postJson('/api/v1/distribution/consignment/returns', [
        'warehouse_id' => $this->supplierStock->id, 'destination_warehouse_id' => $this->hub->id,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertStatus(422);
});

it('flags consignment stock held beyond the agreed days', function (): void {
    wmsReceive($this->consignmentBin, $this->item, '5');
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->consignment->id}/profile", [
        'role' => 'consignment_out', 'max_consignment_days' => 30, 'settlement_trigger' => 'monthly_report', 'billing_frequency' => 'monthly', 'is_insured' => true,
    ])->assertOk();
    StockMovement::query()->where('warehouse_id', $this->consignment->id)->update(['executed_at' => now()->subDays(45)]);

    $row = $this->getJson('/api/v1/distribution/consignment/stock?direction=out')->assertOk()->json('data.0');

    expect($row['is_overdue'])->toBeTrue()
        ->and($row['days_held'])->toBeGreaterThanOrEqual(45)
        ->and($row['max_consignment_days'])->toBe(30);
    expect($this->getJson('/api/v1/distribution/dashboard')->json('data.consignment_stock_value'))->toBe('50.00');
});

it('reports the stock held for a supplier separately from our own consignment stock', function (): void {
    wmsReceive($this->supplierBin, $this->item, '7');
    wmsReceive($this->consignmentBin, $this->item, '3');

    expect($this->getJson('/api/v1/distribution/consignment/stock?direction=in')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/distribution/consignment/stock?direction=in')->json('data.0.direction'))->toBe('in')
        ->and($this->getJson('/api/v1/distribution/consignment/stock')->json('data'))->toHaveCount(2);
});

it('reconciles the stock reported by a 3PL provider with the system stock', function (): void {
    wmsReceive($this->threePlBin, $this->item, '20');

    $short = $this->postJson('/api/v1/distribution/threepl/reconciliations', [
        'warehouse_id' => $this->threePl->id, 'external_reference' => 'ASN-77',
        'lines' => [['stock_item_id' => $this->item->id, 'external_quantity' => 18]],
    ])->assertCreated()->json('data');

    expect($short['status'])->toBe('discrepancy')
        ->and($short['lines'][0]['system_quantity'])->toBe('20.0000')
        ->and($short['lines'][0]['difference'])->toBe('-2.0000');

    $balanced = $this->postJson('/api/v1/distribution/threepl/reconciliations', [
        'warehouse_id' => $this->threePl->id, 'lines' => [['stock_item_id' => $this->item->id, 'external_quantity' => 20]],
    ])->assertCreated()->json('data');

    expect($balanced['status'])->toBe('balanced');
    $this->getJson('/api/v1/distribution/threepl/reconciliations')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/distribution/threepl/reconciliations', ['warehouse_id' => $this->hub->id, 'lines' => [['stock_item_id' => $this->item->id, 'external_quantity' => 1]]])->assertStatus(422);
});
