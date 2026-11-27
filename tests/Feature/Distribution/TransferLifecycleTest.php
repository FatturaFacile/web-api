<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Distribution\Models\TransferMeta;
use Modules\Distribution\Services\StockPositionService;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\WarehouseBin;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->store, $this->storeBin] = drpWarehouse('STORE', 'retail_pos');
    $this->item = drpItem();
    wmsReceive($this->hubBin, $this->item, '20');
});

/**
 * @return array<string, mixed>
 */
function drpTransferPayload(object $test, string $quantity = '10', bool $autoApprove = true): array
{
    return [
        'source_warehouse_id' => $test->hub->id,
        'destination_warehouse_id' => $test->store->id,
        'auto_approve' => $autoApprove,
        'lines' => [['stock_item_id' => $test->item->id, 'quantity' => $quantity]],
    ];
}

function drpTransitBin(string $transferId): WarehouseBin
{
    return WarehouseBin::query()->findOrFail(TransferOrder::query()->findOrFail($transferId)->transit_bin_id);
}

it('creates a draft whose expected arrival follows the lane lead time', function (): void {
    drpLane($this->hub, $this->store, 3);

    $data = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '5', false))->assertCreated()->json('data');

    expect($data['status'])->toBe('draft')
        ->and($data['transfer_number'])->toStartWith('TO-')
        ->and($data['expected_arrival_date'])->toBe(now()->addDays(3)->toDateString())
        ->and($data['lines'][0]['sku'])->toBe($this->item->sku)
        ->and($data['lines'][0]['quantity'])->toBe('5.0000');
});

it('moves stock source to transit to destination with the transport data', function (): void {
    $created = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->assertCreated()->assertJsonPath('data.status', 'approved')->json('data');

    $shipped = $this->postJson("/api/v1/distribution/transfers/{$created['id']}/dispatch", [
        'carrier' => 'Swiss Post', 'tracking_number' => 'TRK-1', 'ddt_reference' => 'DDT-1', 'expected_arrival_date' => now()->addDays(2)->toDateString(),
    ])->assertOk()->assertJsonPath('data.status', 'in_transit')->json('data');

    expect($shipped['carrier'])->toBe('Swiss Post')
        ->and($shipped['tracking_number'])->toBe('TRK-1')
        ->and($shipped['ddt_reference'])->toBe('DDT-1')
        ->and($shipped['expected_arrival_date'])->toBe(now()->addDays(2)->toDateString())
        ->and(drpOnHand($this->hubBin, $this->item))->toBe('10.0000')
        ->and(drpOnHand(drpTransitBin($created['id']), $this->item))->toBe('10.0000')
        ->and(drpOnHand($this->storeBin, $this->item))->toBe('0.0000');

    $transit = $this->getJson('/api/v1/distribution/in-transit')->assertOk();
    expect($transit->json('meta.total_units'))->toBe('10.0000')
        ->and($transit->json('meta.total_value'))->toBe('100.00')
        ->and($transit->json('data.0.destination_warehouse.code'))->toBe('STORE')
        ->and($transit->json('data.0.tracking_number'))->toBe('TRK-1');

    $this->postJson("/api/v1/distribution/transfers/{$created['id']}/receive", ['lines' => []])->assertOk()->assertJsonPath('data.status', 'received');

    expect(drpOnHand(drpTransitBin($created['id']), $this->item))->toBe('0.0000')
        ->and(drpWarehouseOnHand($this->store, $this->item))->toBe('10.0000')
        ->and($this->getJson('/api/v1/distribution/in-transit')->json('meta.total_units'))->toBe('0.0000');
});

it('keeps the rest in transit on a partial receipt and never orphans stock on a write-off', function (): void {
    $id = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    $lineId = $this->getJson("/api/v1/distribution/transfers/{$id}")->json('data.lines.0.id');

    $this->postJson("/api/v1/distribution/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 4]]])
        ->assertOk()->assertJsonPath('data.status', 'partially_received');
    expect($this->getJson('/api/v1/distribution/in-transit')->json('meta.total_units'))->toBe('6.0000');

    $final = $this->postJson("/api/v1/distribution/transfers/{$id}/receive", [
        'lines' => [['line_id' => $lineId, 'quantity' => 3, 'damaged_quantity' => 1]], 'final' => true, 'resolution' => 'write_off',
    ])->assertOk()->json('data');

    expect($final['status'])->toBe('closed')
        ->and($final['lines'][0]['quantity_received'])->toBe('7.0000')
        ->and($final['lines'][0]['quantity_damaged'])->toBe('1.0000')
        ->and($final['lines'][0]['discrepancy_qty'])->toBe('3.0000')
        ->and(drpOnHand(drpTransitBin($id), $this->item))->toBe('0.0000')
        ->and(drpWarehouseOnHand($this->store, $this->item))->toBe('7.0000')
        ->and($this->getJson('/api/v1/distribution/in-transit')->json('meta.total_units'))->toBe('0.0000');
});

it('returns the missing units to the source or claims them from the carrier', function (): void {
    $id = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    $lineId = $this->getJson("/api/v1/distribution/transfers/{$id}")->json('data.lines.0.id');

    $this->postJson("/api/v1/distribution/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 6]], 'final' => true, 'resolution' => 'return_to_source'])
        ->assertOk()->assertJsonPath('data.status', 'closed');

    expect(drpOnHand(drpTransitBin($id), $this->item))->toBe('0.0000')
        ->and(drpWarehouseOnHand($this->hub, $this->item))->toBe('14.0000')
        ->and(drpWarehouseOnHand($this->store, $this->item))->toBe('6.0000');

    $claim = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '4'))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$claim}/dispatch")->assertOk();
    $claimLine = $this->getJson("/api/v1/distribution/transfers/{$claim}")->json('data.lines.0.id');
    $this->postJson("/api/v1/distribution/transfers/{$claim}/receive", ['lines' => [['line_id' => $claimLine, 'quantity' => 3]], 'final' => true, 'resolution' => 'carrier_claim'])
        ->assertOk()->assertJsonPath('data.status', 'closed');

    expect(drpOnHand(drpTransitBin($claim), $this->item))->toBe('0.0000');
});

it('leaves the remainder on the way for a second parcel with keep_in_transit', function (): void {
    $id = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    $lineId = $this->getJson("/api/v1/distribution/transfers/{$id}")->json('data.lines.0.id');

    $this->postJson("/api/v1/distribution/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 6]], 'final' => true, 'resolution' => 'keep_in_transit'])
        ->assertOk()->assertJsonPath('data.status', 'partially_received');

    expect($this->getJson('/api/v1/distribution/in-transit')->json('meta.total_units'))->toBe('4.0000');

    $this->postJson("/api/v1/distribution/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'quantity' => 4]]])
        ->assertOk()->assertJsonPath('data.status', 'received');
});

it('cancels a draft but refuses to cancel a shipped transfer', function (): void {
    $draft = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '3', false))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$draft}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $shipped = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$shipped}/dispatch")->assertOk();
    $this->postJson("/api/v1/distribution/transfers/{$shipped}/cancel")->assertStatus(422)->assertJsonPath('code', 'cannot_cancel_in_transit');
});

it('ships nothing when the source cannot cover the quantity', function (): void {
    $id = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '50'))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

    expect(drpOnHand($this->hubBin, $this->item))->toBe('20.0000')
        ->and(TransferOrder::query()->findOrFail($id)->status)->toBe('approved');
});

it('validates the warehouses and the lines and respects blocked and counted warehouses', function (): void {
    $this->postJson('/api/v1/distribution/transfers', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->hub->id, 'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    $this->postJson('/api/v1/distribution/transfers', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lines' => []])
        ->assertStatus(422)->assertJsonValidationErrors('lines');

    $this->putJson("/api/v1/distribution/network/warehouses/{$this->store->id}/profile", ['role' => 'retail_pos', 'is_blocked' => true])->assertOk();
    $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->assertStatus(422);

    $this->putJson("/api/v1/distribution/network/warehouses/{$this->store->id}/profile", ['role' => 'retail_pos', 'is_blocked' => false])->assertOk();
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->hub->id}/profile", ['role' => 'internal', 'is_counting' => true])->assertOk();
    $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->assertStatus(422);
});

it('lists the transfers with the count of each status and previews the source availability', function (): void {
    $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '2', false))->assertCreated();
    $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this, '3'))->assertCreated();

    $list = $this->getJson('/api/v1/distribution/transfers')->assertOk();
    expect($list->json('meta.status_counts'))->toMatchArray(['draft' => 1, 'approved' => 1]);

    $this->getJson('/api/v1/distribution/transfers?status=draft')->assertOk()->assertJsonCount(1, 'data');

    $availability = $this->getJson("/api/v1/distribution/availability?warehouse_id={$this->hub->id}&stock_item_id={$this->item->id}")->assertOk()->json('data');
    expect($availability)->toMatchArray(['on_hand' => '20.0000', 'available' => '20.0000', 'in_transit_inbound' => '0.0000']);
});

it('flags an overdue shipment and filters the goods in transit', function (): void {
    $id = $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    TransferMeta::query()->where('transfer_order_id', $id)->update(['expected_arrival_date' => now()->subDays(2)->toDateString()]);

    expect($this->getJson('/api/v1/distribution/in-transit?overdue=1')->json('data.0.is_overdue'))->toBeTrue()
        ->and($this->getJson("/api/v1/distribution/in-transit?destination_warehouse_id={$this->hub->id}")->json('data'))->toBe([])
        ->and($this->getJson("/api/v1/distribution/in-transit?stock_item_id={$this->item->id}&source_warehouse_id={$this->hub->id}")->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/distribution/dashboard')->json('data.overdue_shipments'))->toBe(1);
});

it('hides the transfers of other companies and refuses read-only roles', function (): void {
    $foreign = wmsInOtherTenant(static fn (): TransferOrder => TransferOrder::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId()]));
    $this->getJson("/api/v1/distribution/transfers/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/distribution/transfers/{$foreign->id}/dispatch")->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/distribution/transfers', drpTransferPayload($this))->assertForbidden();
    $this->getJson('/api/v1/distribution/transfers')->assertOk();
});

/** Stock of an item across every non-transit bin of a warehouse. */
function drpWarehouseOnHand(object $warehouse, object $item): string
{
    return app(StockPositionService::class)->snapshot((string) $warehouse->id, (string) $item->id)['on_hand'];
}
