<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Distribution\Models\TransferMeta;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->store, $this->storeBin] = drpWarehouse('STORE', 'retail_pos');
    $this->item = drpItem();
});

/**
 * A store that consumed 60 units in the look-back window (1 a day, 7 a week) and holds `$onHand` now.
 */
function drpConsumingStore(object $test, string $onHand): void
{
    wmsReceive($test->storeBin, $test->item, (string) ((int) $onHand + 60));
    drpDispatch($test->storeBin, $test->item, '60');
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function drpRun(object $test, string $bucket = 'weekly', int $horizon = 6, array $extra = []): array
{
    return $test->postJson('/api/v1/distribution/drp/runs', ['bucket_type' => $bucket, 'horizon' => $horizon] + $extra)->assertCreated()->json('data');
}

/**
 * @return array<string, mixed>
 */
function drpRow(object $test, array $run, object $warehouse): array
{
    $grid = $test->getJson("/api/v1/distribution/drp/runs/{$run['id']}/grid?warehouse_id={$warehouse->id}&stock_item_id={$test->item->id}")->assertOk()->json('data');

    return $grid['rows'][0];
}

it('plans gross requirements, net requirements and planned receipts and releases per bucket', function (): void {
    drpConsumingStore($this, '20');
    drpRule($this->store, $this->item, null, ['policy' => 'demand_driven_drp', 'safety_stock' => 5, 'lead_time_days' => 7]);

    $run = drpRun($this);
    $row = drpRow($this, $run, $this->store);
    $cells = $row['cells'];

    expect($run['status'])->toBe('completed')
        ->and($run['bucket_type'])->toBe('weekly')
        ->and($run['summary']['rows'])->toBe(1)
        ->and($row['opening_balance'])->toBe('20.0000')
        ->and($row['safety_stock'])->toBe('5.0000')
        ->and($cells)->toHaveCount(6)
        // 20 - 7 = 13, then 6: both above the safety stock of 5.
        ->and($cells[0]['gross_requirements'])->toBe('7.0000')
        ->and($cells[0]['projected_available'])->toBe('13.0000')
        ->and($cells[1]['projected_available'])->toBe('6.0000')
        ->and($cells[1]['net_requirements'])->toBe('0.0000')
        // 6 - 7 = -1: 6 are needed to be back at 5, released one week earlier (lead time 7 days = one bucket).
        ->and($cells[2]['net_requirements'])->toBe('6.0000')
        ->and($cells[2]['planned_receipts'])->toBe('6.0000')
        ->and($cells[2]['projected_available'])->toBe('5.0000')
        ->and($cells[1]['planned_releases'])->toBe('6.0000');
});

it('raises a stockout risk alert at the first bucket that runs short', function (): void {
    drpConsumingStore($this, '10');
    drpRule($this->store, $this->item, null, ['policy' => 'demand_driven_drp', 'safety_stock' => 5, 'lead_time_days' => 7]);

    $run = drpRun($this);
    $alerts = $this->getJson("/api/v1/distribution/drp/runs/{$run['id']}")->assertOk()->json('data.alerts');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['type'])->toBe('stockout_risk')
        ->and($alerts[0]['bucket_index'])->toBe(1)
        ->and($alerts[0]['warehouse']['code'])->toBe('STORE')
        ->and($alerts[0]['item']['sku'])->toBe($this->item->sku)
        ->and($this->getJson('/api/v1/distribution/drp/alerts')->json('data'))->toHaveCount(1);
});

it('rolls the planned releases of a store up as gross requirements of the hub that supplies it', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    drpConsumingStore($this, '0');
    drpRule($this->store, $this->item, $this->hub, ['policy' => 'demand_driven_drp', 'lead_time_days' => 7]);

    $run = drpRun($this);
    $hub = drpRow($this, $run, $this->hub);
    $store = drpRow($this, $run, $this->store);

    // The store is empty and consumes 7 a week: it needs 7 in the first bucket (released at once, one bucket early is impossible)
    // and 7 more in the second bucket, released in the first. The hub sees both releases in its first bucket.
    expect($store['cells'][0]['planned_receipts'])->toBe('7.0000')
        ->and($store['cells'][0]['planned_releases'])->toBe('14.0000')
        ->and($hub['source_warehouse_id'])->toBeNull()
        ->and($hub['opening_balance'])->toBe('100.0000')
        ->and($hub['cells'][0]['gross_requirements'])->toBe('14.0000')
        ->and($hub['cells'][1]['gross_requirements'])->toBe('7.0000')
        ->and($hub['cells'][0]['projected_available'])->toBe('86.0000');

    // Without the rule of the store there would be no hub row at all: the hub exists only as the supplier of the store.
    expect($run['summary']['rows'])->toBe(2);
});

it('counts approved and in-transit transfers as scheduled receipts of the destination', function (): void {
    wmsReceive($this->hubBin, $this->item, '50');
    wmsReceive($this->storeBin, $this->item, '30');
    drpRule($this->store, $this->item, $this->hub, ['policy' => 'min_max', 'safety_stock' => 0, 'lead_time_days' => 7]);

    $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'auto_approve' => true,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 10]],
    ])->assertCreated();

    $row = drpRow($this, drpRun($this), $this->store);
    $hubRow = drpRow($this, drpRun($this), $this->hub);

    expect($row['cells'][0]['scheduled_receipts'])->toBe('10.0000')
        ->and($row['cells'][0]['projected_available'])->toBe('40.0000')
        // The same approved transfer is a committed issue of the hub.
        ->and($hubRow['cells'][0]['gross_requirements'])->toBe('10.0000');
});

it('alerts on excess stock above the maximum and on a late inbound transfer', function (): void {
    wmsReceive($this->hubBin, $this->item, '50');
    wmsReceive($this->storeBin, $this->item, '80');
    drpRule($this->store, $this->item, $this->hub, ['max_stock' => 40, 'reorder_point' => 5]);

    $id = $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'auto_approve' => true,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 5]],
    ])->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    TransferMeta::query()->where('transfer_order_id', $id)->update(['expected_arrival_date' => now()->subDays(3)->toDateString()]);

    $run = $this->postJson('/api/v1/distribution/drp/runs', ['bucket_type' => 'weekly', 'horizon' => 4])->assertCreated()->json('data');
    $types = collect($this->getJson("/api/v1/distribution/drp/runs/{$run['id']}")->json('data.alerts'))->pluck('type')->all();

    expect($types)->toContain('excess_stock', 'late_inbound')
        ->and($run['summary']['late_inbound_alerts'])->toBe(1);
});

it('plans daily buckets and keeps the rows of the requested warehouses only', function (): void {
    drpConsumingStore($this, '3');
    drpRule($this->store, $this->item, $this->hub, ['policy' => 'demand_driven_drp', 'lead_time_days' => 2]);

    $run = drpRun($this, 'daily', 5, ['warehouse_ids' => [$this->store->id]]);
    $grid = $this->getJson("/api/v1/distribution/drp/runs/{$run['id']}/grid")->assertOk()->json('data');

    expect($grid['buckets'])->toHaveCount(5)
        ->and($grid['buckets'][1]['start'])->toBe(now()->addDay()->toDateString())
        ->and($grid['rows'])->toHaveCount(1)
        ->and($grid['rows'][0]['warehouse']['code'])->toBe('STORE')
        // 3 on hand, 1 consumed a day: the planned receipt is released two days before it is needed.
        ->and($grid['rows'][0]['cells'][0]['gross_requirements'])->toBe('1.0000');
});

it('keeps every run as a versioned snapshot and lists them newest first', function (): void {
    drpConsumingStore($this, '20');
    drpRule($this->store, $this->item, null, ['policy' => 'demand_driven_drp', 'lead_time_days' => 7]);

    $first = drpRun($this);
    $second = drpRun($this, 'weekly', 4);

    expect($first['run_number'])->not->toBe($second['run_number'])
        ->and(DrpRun::query()->count())->toBe(2)
        ->and($this->getJson('/api/v1/distribution/drp/runs')->assertOk()->json('data'))->toHaveCount(2)
        ->and($this->getJson("/api/v1/distribution/drp/runs/{$first['id']}/grid")->json('data.buckets'))->toHaveCount(6)
        ->and($this->getJson("/api/v1/distribution/drp/runs/{$second['id']}/grid")->json('data.buckets'))->toHaveCount(4);
});

it('validates the DRP parameters', function (): void {
    $this->postJson('/api/v1/distribution/drp/runs', ['bucket_type' => 'monthly', 'horizon' => 4])->assertStatus(422)->assertJsonValidationErrors('bucket_type');
    $this->postJson('/api/v1/distribution/drp/runs', ['bucket_type' => 'weekly', 'horizon' => 0])->assertStatus(422)->assertJsonValidationErrors('horizon');
    $this->postJson('/api/v1/distribution/drp/runs', ['bucket_type' => 'weekly', 'horizon' => 4, 'warehouse_ids' => ['not-a-uuid']])->assertStatus(422);
});

it('runs from the console for one company, with proposals on request', function (): void {
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeBin, $this->item, '2');
    drpRule($this->store, $this->item, $this->hub, ['reorder_point' => 10, 'max_stock' => 30]);

    $this->artisan('drp:run', ['--tenant' => $this->tenant->id, '--bucket' => 'daily', '--horizon' => 3, '--proposals' => true])->assertSuccessful();

    expect(DrpRun::query()->count())->toBe(1)
        ->and(DrpRun::query()->first()->bucket_type->value)->toBe('daily')
        ->and(ReplenishmentProposal::query()->where('status', 'pending')->count())->toBe(1);

    $this->artisan('drp:run', ['--bucket' => 'yearly'])->assertFailed();
});

it('keeps the DRP of one company away from another', function (): void {
    $foreign = wmsInOtherTenant(fn (): DrpRun => DrpRun::create(['run_number' => 'DRP-FOREIGN', 'bucket_type' => 'weekly', 'horizon' => 4, 'status' => 'completed']));

    $this->getJson("/api/v1/distribution/drp/runs/{$foreign->id}")->assertNotFound();
    $this->getJson("/api/v1/distribution/drp/runs/{$foreign->id}/grid")->assertNotFound();
});
