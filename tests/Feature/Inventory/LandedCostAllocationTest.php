<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Procurement\Models\PurchaseOrder;
use Tests\Support\InventoryValuationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = Fx::actingTenantUser();
    $this->tenantId = (string) $this->tenant->id;
    ['warehouse' => $this->warehouse, 'bin' => $this->bin] = Fx::warehouseWithBin($this->tenantId);
});

/**
 * @param  list<string>  $receiptIds
 * @return array<string, mixed>
 */
function landedCostPayload(array $receiptIds, string $key = 'value', float $total = 300.0): array
{
    return [
        'document_date' => now()->toDateString(),
        'cost_type' => 'freight',
        'allocation_key' => $key,
        'currency' => 'EUR',
        'total_amount' => $total,
        'receipt_ids' => $receiptIds,
    ];
}

/**
 * @param  list<array<string, mixed>>  $allocations
 * @return array<string, string>
 */
function allocatedByReceipt(array $allocations): array
{
    $result = [];
    foreach ($allocations as $allocation) {
        $result[$allocation['receipt_id']] = bcadd($result[$allocation['receipt_id']] ?? '0', (string) $allocation['allocated_amount'], 2);
    }

    return $result;
}

test('allocation by value splits freight proportionally to receipt value (matrix: 1000/500 -> 200/100)', function (): void {
    $receiptA = Fx::purchaseReceipt($this->tenantId);
    $receiptB = Fx::purchaseReceipt($this->tenantId);
    $itemA = Fx::item($this->tenantId, 'fifo');
    $itemB = Fx::item($this->tenantId, 'fifo');
    Fx::receive($itemA, $this->bin, 100, 10.00, (string) $receiptA->id); // value 1000
    Fx::receive($itemB, $this->bin, 50, 10.00, (string) $receiptB->id);  // value 500

    $response = $this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receiptA->id, (string) $receiptB->id]));

    $response->assertOk()
        ->assertJsonPath('totals.allocated', '300.00')
        ->assertJsonPath('totals.difference', '0.00');

    $byReceipt = allocatedByReceipt($response->json('allocations'));
    expect($byReceipt[(string) $receiptA->id])->toBe('200.00')
        ->and($byReceipt[(string) $receiptB->id])->toBe('100.00');

    // Preview never persists.
    expect(LandedCostDocument::count())->toBe(0)
        ->and(LandedCostAllocation::count())->toBe(0)
        ->and(InventoryRevaluation::count())->toBe(0);
});

test('allocation by quantity uses largest remainder so parts sum exactly to the total', function (): void {
    $receipts = [];
    foreach (range(1, 3) as $i) {
        $receipt = Fx::purchaseReceipt($this->tenantId);
        Fx::receive(Fx::item($this->tenantId, 'fifo'), $this->bin, 1, 3.00, (string) $receipt->id);
        $receipts[] = (string) $receipt->id;
    }

    $response = $this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload($receipts, 'quantity', 100.00));

    $response->assertOk()->assertJsonPath('totals.difference', '0.00');
    $amounts = array_map(static fn (array $a): string => $a['allocated_amount'], $response->json('allocations'));
    sort($amounts);

    expect($amounts)->toBe(['33.33', '33.33', '33.34'])
        ->and(array_reduce($amounts, static fn (string $c, string $a): string => bcadd($c, $a, 2), '0'))->toBe('100.00');
});

test('allocation by weight and by volume use item master weight_kg and volume_m3', function (): void {
    $receiptA = Fx::purchaseReceipt($this->tenantId);
    $receiptB = Fx::purchaseReceipt($this->tenantId);
    Fx::receive(Fx::item($this->tenantId, 'fifo', ['weight_kg' => 2.0, 'volume_m3' => 0.5]), $this->bin, 10, 1.00, (string) $receiptA->id);
    Fx::receive(Fx::item($this->tenantId, 'fifo', ['weight_kg' => 1.0, 'volume_m3' => 1.5]), $this->bin, 10, 100.00, (string) $receiptB->id);

    $byWeight = allocatedByReceipt($this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receiptA->id, (string) $receiptB->id], 'weight', 90.00))->assertOk()->json('allocations'));
    expect($byWeight[(string) $receiptA->id])->toBe('60.00')
        ->and($byWeight[(string) $receiptB->id])->toBe('30.00');

    $byVolume = allocatedByReceipt($this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receiptA->id, (string) $receiptB->id], 'volume', 90.00))->assertOk()->json('allocations'));
    expect($byVolume[(string) $receiptA->id])->toBe('22.50')
        ->and($byVolume[(string) $receiptB->id])->toBe('67.50');
});

test('allocation by weight is rejected when an item lacks weight_kg, listing the offending items', function (): void {
    $receipt = Fx::purchaseReceipt($this->tenantId);
    $withWeight = Fx::item($this->tenantId, 'fifo', ['weight_kg' => 2.0]);
    $withoutWeight = Fx::item($this->tenantId, 'fifo', ['weight_kg' => null]);
    Fx::receive($withWeight, $this->bin, 5, 1.00, (string) $receipt->id);
    Fx::receive($withoutWeight, $this->bin, 5, 1.00, (string) $receipt->id);

    $response = $this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receipt->id], 'weight'));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_key', 'items']);
    expect($response->json('errors.items'))->toBe([$withoutWeight->sku])
        ->and($response->json('errors.allocation_key.0'))->toContain($withoutWeight->sku);
});

test('allocation is rejected when no target has a basis greater than zero', function (): void {
    $receipt = Fx::purchaseReceipt($this->tenantId);
    Fx::receive(Fx::item($this->tenantId, 'fifo'), $this->bin, 5, 0.00, (string) $receipt->id);

    $this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receipt->id], 'value'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_key']);
});

test('allocation is rejected for a receipt without inventory receipt layers', function (): void {
    $receipt = Fx::purchaseReceipt($this->tenantId);

    $this->postJson('/api/v1/inventory/landed-costs/preview', landedCostPayload([(string) $receipt->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt_ids']);
});

test('creating a landed cost document returns a draft with the allocation preview and persists no allocations', function (): void {
    $receipt = Fx::purchaseReceipt($this->tenantId);
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 5.00, (string) $receipt->id);

    $response = $this->postJson('/api/v1/inventory/landed-costs', landedCostPayload([(string) $receipt->id], 'value', 50.00) + ['notes' => 'Sea freight']);

    $response->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('total_amount', '50.00')
        ->assertJsonPath('receipts.0.receipt_id', (string) $receipt->id)
        ->assertJsonPath('receipts.0.receipt_number', $receipt->receipt_number)
        ->assertJsonPath('allocations.0.item_id', (string) $item->id)
        ->assertJsonPath('allocations.0.allocated_amount', '50.00')
        ->assertJsonPath('journal_entry_id', null);

    expect($response->json('number'))->toStartWith('LC-'.now()->format('Y').'-')
        ->and(LandedCostAllocation::count())->toBe(0);

    $this->getJson('/api/v1/inventory/landed-costs/'.$response->json('id'))
        ->assertOk()
        ->assertJsonPath('allocations.0.allocated_amount', '50.00');

    $this->getJson('/api/v1/inventory/landed-costs?status=draft&receipt_id='.$receipt->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $response->json('id'));
});

test('creating a landed cost document validates the payload', function (): void {
    $this->postJson('/api/v1/inventory/landed-costs', [
        'document_date' => 'not-a-date',
        'cost_type' => 'lifo',
        'allocation_key' => 'price',
        'currency' => 'EURO',
        'total_amount' => -5,
        'receipt_ids' => [],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'document_date', 'cost_type', 'allocation_key', 'currency', 'total_amount', 'receipt_ids',
    ]);
});

test('landed cost can be allocated to purchase orders: every receipt of the order takes its share (largest remainder, sums to the total)', function (): void {
    $orderA = PurchaseOrder::factory()->create(['tenant_id' => $this->tenantId]);
    $orderB = PurchaseOrder::factory()->create(['tenant_id' => $this->tenantId]);

    $receiptA1 = Fx::purchaseReceipt($this->tenantId);
    $receiptA2 = Fx::purchaseReceipt($this->tenantId);
    $receiptB = Fx::purchaseReceipt($this->tenantId);
    $receiptA1->forceFill(['purchase_order_id' => $orderA->id])->save();
    $receiptA2->forceFill(['purchase_order_id' => $orderA->id])->save();
    $receiptB->forceFill(['purchase_order_id' => $orderB->id])->save();

    Fx::receive(Fx::item($this->tenantId, 'fifo'), $this->bin, 10, 10.00, (string) $receiptA1->id); // 100
    Fx::receive(Fx::item($this->tenantId, 'fifo'), $this->bin, 10, 20.00, (string) $receiptA2->id); // 200
    Fx::receive(Fx::item($this->tenantId, 'fifo'), $this->bin, 10, 30.00, (string) $receiptB->id);  // 300, not part of order A

    $payload = landedCostPayload([], 'value', 100.0);
    unset($payload['receipt_ids']);
    $payload['purchase_order_ids'] = [(string) $orderA->id];

    $response = $this->postJson('/api/v1/inventory/landed-costs/preview', $payload)->assertOk()
        ->assertJsonPath('totals.allocated', '100.00')
        ->assertJsonPath('totals.difference', '0.00');

    $byReceipt = allocatedByReceipt($response->json('allocations'));
    // 100 split 100:200 -> 33.33 / 66.67, receipt B untouched.
    expect($byReceipt)->toHaveCount(2)
        ->and($byReceipt[(string) $receiptA1->id])->toBe('33.33')
        ->and($byReceipt[(string) $receiptA2->id])->toBe('66.67');

    $created = $this->postJson('/api/v1/inventory/landed-costs', $payload)->assertCreated();
    expect(LandedCostDocument::query()->findOrFail($created->json('id'))->receiptIds())->toHaveCount(2);
});

test('a purchase order with nothing received, or of another tenant, is refused', function (): void {
    $empty = PurchaseOrder::factory()->create(['tenant_id' => $this->tenantId]);
    $payload = landedCostPayload([], 'value', 100.0);
    unset($payload['receipt_ids']);

    $this->postJson('/api/v1/inventory/landed-costs/preview', $payload + ['purchase_order_ids' => [(string) $empty->id]])
        ->assertStatus(422)->assertJsonValidationErrors('purchase_order_ids');

    $this->postJson('/api/v1/inventory/landed-costs/preview', $payload + ['purchase_order_ids' => [(string) Str::uuid()]])
        ->assertStatus(422)->assertJsonValidationErrors('purchase_order_ids.0');
});
