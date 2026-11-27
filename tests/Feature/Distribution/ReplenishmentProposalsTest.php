<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Distribution\Events\ReplenishmentShortfallDetected;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Inventory\Models\TransferOrder;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->storeA, $this->storeABin] = drpWarehouse('STORE-A', 'retail_pos');
    [$this->storeB, $this->storeBBin] = drpWarehouse('STORE-B', 'retail_pos');
    $this->item = drpItem();
    drpLane($this->hub, $this->storeA, 2);
    drpLane($this->hub, $this->storeB, 2);
    drpLane($this->storeB, $this->storeA, 1);
});

/**
 * @return list<array<string, mixed>>
 */
function drpGenerate(object $test): array
{
    $test->postJson('/api/v1/distribution/proposals/generate')->assertOk();

    return $test->getJson('/api/v1/distribution/proposals')->assertOk()->json('data');
}

it('proposes a min-max replenishment up to the maximum from the hub', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['policy' => 'min_max', 'reorder_point' => 20, 'max_stock' => 50, 'safety_stock' => 10, 'lead_time_days' => 2]);

    $result = $this->postJson('/api/v1/distribution/proposals/generate')->assertOk()->json('data');
    $proposal = $this->getJson('/api/v1/distribution/proposals')->json('data.0');

    expect($result)->toMatchArray(['created' => 1, 'balancing' => 0, 'shortfalls' => 0])
        ->and($proposal['proposal_type'])->toBe('replenishment')
        ->and($proposal['status'])->toBe('pending')
        ->and($proposal['suggested_quantity'])->toBe('45.0000')
        ->and($proposal['source_warehouse']['code'])->toBe('HUB')
        ->and($proposal['calculation']['trigger_reason'])->toBe('below_reorder_point')
        ->and($proposal['calculation']['net_position'])->toBe('5.0000')
        ->and(array_column($proposal['calculation']['steps'], 'label'))->toContain('on_hand', 'projected_demand', 'net_position', 'needed_quantity');
});

it('does not propose anything while the position is above the reorder point', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '40');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);

    expect(drpGenerate($this))->toBe([]);
});

it('rounds a proposal up to the transfer multiple and the minimum transfer quantity', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50, 'transfer_multiple' => 12]);

    expect(drpGenerate($this)[0]['suggested_quantity'])->toBe('48.0000');
});

it('orders whole fixed quantities when the reorder point is reached', function (): void {
    wmsReceive($this->hubBin, $this->item, '500');
    drpRule($this->storeA, $this->item, $this->hub, ['policy' => 'reorder_point_fixed_qty', 'reorder_point' => 70, 'fixed_quantity' => 30]);

    $proposal = drpGenerate($this)[0];

    // Position 0, reorder point 70: three batches of 30.
    expect($proposal['suggested_quantity'])->toBe('90.0000');
});

it('tops up to the review target on the periodic review policy', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['policy' => 'periodic_review', 'safety_stock' => 10, 'review_cycle_days' => 7, 'lead_time_days' => 2]);

    $proposal = drpGenerate($this)[0];

    expect($proposal['suggested_quantity'])->toBe('5.0000')
        ->and($proposal['calculation']['trigger_reason'])->toBe('below_target_level');
});

it('covers the safety stock on the demand-driven policy within the minimum transfer quantity', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '4');
    drpRule($this->storeA, $this->item, $this->hub, ['policy' => 'demand_driven_drp', 'safety_stock' => 10, 'min_transfer_qty' => 8]);

    $proposal = drpGenerate($this)[0];

    expect($proposal['suggested_quantity'])->toBe('8.0000')
        ->and($proposal['calculation']['trigger_reason'])->toBe('below_safety_stock');
});

it('projects the consumption of the last weeks over the lead time', function (): void {
    wmsReceive($this->hubBin, $this->item, '500');
    wmsReceive($this->storeABin, $this->item, '120');
    drpDispatch($this->storeABin, $this->item, '60');
    drpRule($this->storeA, $this->item, $this->hub, ['policy' => 'demand_driven_drp', 'safety_stock' => 20, 'lead_time_days' => 10]);

    expect(drpGenerate($this))->toBe([]);

    drpDispatch($this->storeABin, $this->item, '40');
    $proposal = drpGenerate($this)[0];

    // 20 units left, 100 consumed in 60 days (about 1.67 a day) over a 10 day lead time -> 16.67 projected.
    expect($proposal['calculation']['projected_demand'])->toBe('16.6670')
        ->and($proposal['suggested_quantity'])->toBe('16.6670');
});

it('balances laterally first: a peer with excess stock lends before the hub is asked', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    wmsReceive($this->storeBBin, $this->item, '60');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50, 'safety_stock' => 10]);
    drpRule($this->storeB, $this->item, $this->hub, ['reorder_point' => 10, 'max_stock' => 30]);

    $result = $this->postJson('/api/v1/distribution/proposals/generate')->assertOk()->json('data');
    $proposals = collect($this->getJson('/api/v1/distribution/proposals')->json('data'))->keyBy('proposal_type');

    expect($result)->toMatchArray(['created' => 1, 'balancing' => 1])
        ->and($proposals['balancing']['source_warehouse']['code'])->toBe('STORE-B')
        ->and($proposals['balancing']['suggested_quantity'])->toBe('30.0000')
        ->and($proposals['replenishment']['source_warehouse']['code'])->toBe('HUB')
        ->and($proposals['replenishment']['suggested_quantity'])->toBe('15.0000');

    $this->getJson('/api/v1/distribution/proposals?proposal_type=balancing')->assertJsonCount(1, 'data');
});

it('does not borrow from a peer that no lane connects', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    [$island, $islandBin] = drpWarehouse('ISLAND', 'retail_pos');
    wmsReceive($islandBin, $this->item, '60');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);
    drpRule($island, $this->item, $this->hub, ['reorder_point' => 10, 'max_stock' => 30]);

    $proposals = drpGenerate($this);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['proposal_type'])->toBe('replenishment')
        ->and($proposals[0]['suggested_quantity'])->toBe('45.0000');
});

it('flags the shortfall when the hub cannot cover the need and tells procurement', function (): void {
    Event::fake([ReplenishmentShortfallDetected::class]);
    wmsReceive($this->hubBin, $this->item, '10');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);

    $proposal = drpGenerate($this)[0];

    expect($proposal['status'])->toBe('pending')
        ->and($proposal['suggested_quantity'])->toBe('10.0000')
        ->and($proposal['shortfall_quantity'])->toBe('35.0000')
        ->and($proposal['source_available_quantity'])->toBe('10.0000');
    Event::assertDispatched(ReplenishmentShortfallDetected::class, fn (ReplenishmentShortfallDetected $event): bool => $event->missingQuantity === '35.0000');
});

it('marks the proposal as a shortfall when the hub has nothing at all or the rule has no source', function (): void {
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);

    $none = drpGenerate($this)[0];
    expect($none['status'])->toBe('shortfall')->and($none['suggested_quantity'])->toBe('0.0000')->and($none['shortfall_quantity'])->toBe('45.0000');

    ReplenishmentProposal::query()->delete();
    ReplenishmentRule::query()->forceDelete();
    drpRule($this->storeA, $this->item, null, ['reorder_point' => 20, 'max_stock' => 50]);

    $orphan = drpGenerate($this)[0];
    expect($orphan['status'])->toBe('shortfall')->and($orphan['calculation']['shortfall_reason'])->toBe('no_source');
});

it('replaces the pending proposals of a rule when the engine runs again', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);

    drpGenerate($this);

    expect(drpGenerate($this))->toHaveCount(1);
});

it('turns approved proposals into transfers grouped by source and destination', function (): void {
    $other = drpItem();
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->hubBin, $other, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    wmsReceive($this->storeABin, $other, '2');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);
    drpRule($this->storeA, $other, $this->hub, ['reorder_point' => 10, 'max_stock' => 20]);

    $ids = array_column(drpGenerate($this), 'id');
    $result = $this->postJson('/api/v1/distribution/proposals/approve', ['ids' => $ids, 'transfer_status' => 'approved'])->assertOk()->json('data');

    expect($result['transfers'])->toHaveCount(1)
        ->and($result['transfers'][0]['status'])->toBe('approved')
        ->and($result['transfers'][0]['lines'])->toHaveCount(2)
        ->and($result['transfers'][0]['source_warehouse']['code'])->toBe('HUB')
        ->and($result['shortfalls'])->toBe([])
        ->and(ReplenishmentProposal::query()->where('status', 'converted')->whereNotNull('transfer_order_id')->count())->toBe(2)
        ->and(TransferOrder::query()->count())->toBe(1);

    $this->postJson('/api/v1/distribution/proposals/approve', ['ids' => $ids, 'transfer_status' => 'draft'])->assertStatus(422);
});

it('creates draft transfers on request and returns the shortfalls instead of transferring them', function (): void {
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);

    $ids = array_column(drpGenerate($this), 'id');
    $result = $this->postJson('/api/v1/distribution/proposals/approve', ['ids' => $ids, 'transfer_status' => 'draft'])->assertOk()->json('data');

    expect($result['transfers'])->toBe([])
        ->and($result['shortfalls'])->toHaveCount(1)
        ->and($result['shortfalls'][0]['status'])->toBe('shortfall');
});

it('adjusts the quantity of a pending proposal and rejects a proposal', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeABin, $this->item, '5');
    drpRule($this->storeA, $this->item, $this->hub, ['reorder_point' => 20, 'max_stock' => 50]);
    $id = drpGenerate($this)[0]['id'];

    $this->putJson("/api/v1/distribution/proposals/{$id}", ['suggested_quantity' => 20])->assertOk()
        ->assertJsonPath('data.suggested_quantity', '20.0000')->assertJsonPath('data.original_quantity', '45.0000');
    $this->putJson("/api/v1/distribution/proposals/{$id}", ['suggested_quantity' => 0])->assertStatus(422);

    $this->postJson("/api/v1/distribution/proposals/{$id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->putJson("/api/v1/distribution/proposals/{$id}", ['suggested_quantity' => 10])->assertStatus(422);
    $this->postJson("/api/v1/distribution/proposals/{$id}/reject")->assertStatus(422);
});

it('manages the rules with validation, uniqueness and tenant isolation', function (): void {
    $payload = ['warehouse_id' => $this->storeA->id, 'stock_item_id' => $this->item->id, 'policy' => 'min_max', 'source_warehouse_id' => $this->hub->id, 'reorder_point' => 10, 'max_stock' => 40, 'safety_stock' => 5];

    $rule = $this->postJson('/api/v1/distribution/replenishment-rules', $payload)->assertCreated()->json('data');
    expect($rule['policy'])->toBe('min_max')->and($rule['lead_time_days'])->toBe(1)->and($rule['warehouse']['code'])->toBe('STORE-A');

    $this->postJson('/api/v1/distribution/replenishment-rules', $payload)->assertStatus(422)->assertJsonValidationErrors('stock_item_id');
    $this->postJson('/api/v1/distribution/replenishment-rules', ['warehouse_id' => $this->storeB->id, 'stock_item_id' => $this->item->id, 'policy' => 'reorder_point_fixed_qty'])->assertStatus(422)->assertJsonValidationErrors('fixed_quantity');
    $this->postJson('/api/v1/distribution/replenishment-rules', ['warehouse_id' => $this->storeB->id, 'stock_item_id' => $this->item->id, 'policy' => 'periodic_review'])->assertStatus(422)->assertJsonValidationErrors('review_cycle_days');
    $this->postJson('/api/v1/distribution/replenishment-rules', ['warehouse_id' => $this->storeB->id, 'stock_item_id' => $this->item->id, 'policy' => 'min_max', 'reorder_point' => 30, 'max_stock' => 10])->assertStatus(422)->assertJsonValidationErrors('max_stock');
    $this->postJson('/api/v1/distribution/replenishment-rules', ['source_warehouse_id' => $this->storeA->id] + $payload)->assertStatus(422);

    $this->putJson("/api/v1/distribution/replenishment-rules/{$rule['id']}", ['max_stock' => 60] + $payload)->assertOk()->assertJsonPath('data.max_stock', '60.0000');
    $this->getJson('/api/v1/distribution/replenishment-rules')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/distribution/replenishment-rules/{$rule['id']}")->assertOk();
    $this->getJson("/api/v1/distribution/replenishment-rules/{$rule['id']}")->assertNotFound();

    $foreign = wmsInOtherTenant(fn () => drpRule(logisticsWarehouse(['code' => 'FOREIGN']), drpItem(), null));
    $this->getJson("/api/v1/distribution/replenishment-rules/{$foreign->id}")->assertNotFound();
});
