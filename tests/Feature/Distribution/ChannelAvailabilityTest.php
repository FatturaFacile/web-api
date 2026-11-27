<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Distribution\Models\SalesChannel;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->store, $this->storeBin] = drpWarehouse('STORE', 'retail_pos');
    [$this->threePl, $this->threePlBin] = drpWarehouse('3PL', 'third_party_3pl');
    [$this->consignment, $this->consignmentBin] = drpWarehouse('CON-OUT', 'consignment_out', 'customer_consignment');
    $this->item = drpItem();
    wmsReceive($this->hubBin, $this->item, '100');
    wmsReceive($this->storeBin, $this->item, '40');
    wmsReceive($this->threePlBin, $this->item, '25');
    wmsReceive($this->consignmentBin, $this->item, '15');
    drpReserve($this->hubBin, $this->item, '10');
});

/**
 * A channel with its warehouse rules, created through the API.
 *
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function drpChannel(object $test, string $code, array $rules, int $priority = 100): array
{
    $channel = $test->postJson('/api/v1/distribution/channels', ['code' => $code, 'name' => $code, 'channel_type' => 'custom', 'priority' => $priority])->assertCreated()->json('data');

    foreach ($rules as $rule) {
        $test->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", $rule)->assertCreated();
    }

    return $channel;
}

/**
 * ATP of every channel for the item, keyed by channel code.
 *
 * @return array<string, array<string, mixed>>
 */
function drpAtp(object $test): array
{
    $data = $test->getJson("/api/v1/distribution/atp?stock_item_id={$test->item->id}")->assertOk()->json('data');

    return collect($data['channels'])->keyBy('code')->all();
}

it('shows a shared channel the whole free stock of its warehouse', function (): void {
    drpChannel($this, 'B2B', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']]);

    $channel = drpAtp($this)['B2B'];

    expect($channel['on_hand'])->toBe('100.0000')
        ->and($channel['reserved'])->toBe('10.0000')
        ->and($channel['atp'])->toBe('90.0000')
        ->and($channel['warehouses'][0]['code'])->toBe('HUB')
        ->and($channel['warehouses'][0]['atp'])->toBe('90.0000');
});

it('splits a warehouse by percentage between channels', function (): void {
    drpChannel($this, 'ECOM', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage', 'allocation_percentage' => 60]]);
    drpChannel($this, 'B2B', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage', 'allocation_percentage' => 40]]);

    $atp = drpAtp($this);

    expect($atp['ECOM']['atp'])->toBe('54.0000')->and($atp['B2B']['atp'])->toBe('36.0000');
});

it('ring-fences a fixed reserve and shows the rest to the shared channels', function (): void {
    drpChannel($this, 'MARKET', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'fixed_reserve', 'fixed_reserve_qty' => 25]]);
    drpChannel($this, 'B2B', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']]);

    $atp = drpAtp($this);

    expect($atp['MARKET']['atp'])->toBe('25.0000')
        ->and($atp['MARKET']['ring_fenced'])->toBe('25.0000')
        ->and($atp['B2B']['atp'])->toBe('65.0000');
});

it('serves the priority ranks in order and lets the shared pool see what is left', function (): void {
    drpChannel($this, 'FIRST', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'priority_rank', 'priority_rank' => 1, 'fixed_reserve_qty' => 30]], 10);
    drpChannel($this, 'SECOND', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'priority_rank', 'priority_rank' => 2]], 20);
    drpChannel($this, 'POOL', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']], 30);

    $atp = drpAtp($this);

    expect($atp['FIRST']['atp'])->toBe('30.0000')
        ->and($atp['SECOND']['atp'])->toBe('60.0000')
        ->and($atp['POOL']['atp'])->toBe('0.0000');
});

it('carves ring-fences and percentages out before the shared channels', function (): void {
    drpChannel($this, 'MARKET', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'fixed_reserve', 'fixed_reserve_qty' => 20]]);
    drpChannel($this, 'ECOM', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage', 'allocation_percentage' => 50]]);
    drpChannel($this, 'B2B', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']]);

    $atp = drpAtp($this);

    // 90 free: 20 ring-fenced, half of the remaining 70 to e-commerce, the other 35 to the shared pool.
    expect($atp['MARKET']['atp'])->toBe('20.0000')->and($atp['ECOM']['atp'])->toBe('35.0000')->and($atp['B2B']['atp'])->toBe('35.0000');
});

it('adds the stock on its way only when the channel includes in-transit stock', function (): void {
    drpChannel($this, 'WITH', [['warehouse_id' => $this->store->id, 'allocation_mode' => 'shared_pool', 'include_in_transit' => true]]);
    drpChannel($this, 'WITHOUT', [['warehouse_id' => $this->store->id, 'allocation_mode' => 'shared_pool', 'include_in_transit' => false]]);

    $id = $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'auto_approve' => true,
        'lines' => [['stock_item_id' => $this->item->id, 'quantity' => 12]],
    ])->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();

    $atp = drpAtp($this);

    expect($atp['WITH']['in_transit_inbound'])->toBe('12.0000')
        ->and($atp['WITH']['atp'])->toBe('52.0000')
        ->and($atp['WITHOUT']['atp'])->toBe('40.0000');
});

it('keeps consignment and 3PL warehouses out unless the channel asks for them', function (): void {
    drpChannel($this, 'DEFAULTS', [
        ['warehouse_id' => $this->consignment->id, 'allocation_mode' => 'shared_pool'],
        ['warehouse_id' => $this->threePl->id, 'allocation_mode' => 'shared_pool', 'exclude_3pl' => true],
    ]);
    drpChannel($this, 'OPEN', [
        ['warehouse_id' => $this->consignment->id, 'allocation_mode' => 'shared_pool', 'exclude_consignment' => false],
        ['warehouse_id' => $this->threePl->id, 'allocation_mode' => 'shared_pool', 'exclude_3pl' => false],
    ]);

    $atp = drpAtp($this);

    expect($atp['DEFAULTS']['atp'])->toBe('0.0000')
        ->and($atp['OPEN']['atp'])->toBe('40.0000')
        ->and(collect($atp['OPEN']['warehouses'])->pluck('atp', 'code')->all())->toEqual(['CON-OUT' => '15.0000', '3PL' => '25.0000']);
});

it('holds back the safety buffer and never goes below zero', function (): void {
    drpChannel($this, 'BUFFERED', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool', 'safety_buffer_qty' => 10]]);
    drpChannel($this, 'EMPTY', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool', 'safety_buffer_qty' => 500]]);

    $atp = drpAtp($this);

    expect($atp['BUFFERED']['atp'])->toBe('80.0000')
        ->and($atp['BUFFERED']['ring_fenced'])->toBe('90.0000')
        ->and($atp['BUFFERED']['safety_buffer'])->toBe('10.0000')
        ->and($atp['EMPTY']['atp'])->toBe('0.0000');
});

it('ignores blocked warehouses, inactive rules and inactive channels', function (): void {
    drpChannel($this, 'LIVE', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool'], ['warehouse_id' => $this->store->id, 'allocation_mode' => 'shared_pool']]);
    $off = drpChannel($this, 'OFF', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']]);

    $this->putJson("/api/v1/distribution/channels/{$off['id']}", ['code' => 'OFF', 'name' => 'OFF', 'channel_type' => 'custom', 'is_active' => false])->assertOk();
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->store->id}/profile", ['role' => 'retail_pos', 'is_blocked' => true])->assertOk();

    $atp = drpAtp($this);

    expect($atp)->toHaveKey('LIVE')->not->toHaveKey('OFF')
        ->and($atp['LIVE']['atp'])->toBe('90.0000')
        // The blocked store takes no part.
        ->and($atp['LIVE']['warehouses'])->toHaveCount(1);
});

it('answers for one channel and for every channel and item in the matrix', function (): void {
    $b2b = drpChannel($this, 'B2B', [['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool']], 10);
    $pos = drpChannel($this, 'POS', [['warehouse_id' => $this->store->id, 'allocation_mode' => 'shared_pool']], 20);

    $single = $this->getJson("/api/v1/distribution/atp?stock_item_id={$this->item->id}&sales_channel_id={$pos['id']}")->assertOk()->json('data');
    expect($single['channels'])->toHaveCount(1)->and($single['channels'][0]['code'])->toBe('POS')->and($single['stock_item']['sku'])->toBe($this->item->sku);

    $matrix = $this->getJson('/api/v1/distribution/atp/matrix')->assertOk();
    $row = collect($matrix->json('data.rows'))->firstWhere('stock_item_id', $this->item->id);

    expect(array_column($matrix->json('data.channels'), 'code'))->toBe(['B2B', 'POS'])
        ->and($row['cells'][$b2b['id']])->toBe('90.0000')
        ->and($row['cells'][$pos['id']])->toBe('40.0000')
        ->and($matrix->json('meta.total'))->toBe(1);

    $this->getJson('/api/v1/distribution/atp')->assertStatus(422);
});

it('validates the channels and their warehouse rules', function (): void {
    $channel = drpChannel($this, 'ECOM', []);

    $this->postJson('/api/v1/distribution/channels', ['code' => 'ECOM', 'name' => 'Again', 'channel_type' => 'custom'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/distribution/channels', ['code' => 'bad code!', 'name' => 'Bad', 'channel_type' => 'custom'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/distribution/channels', ['code' => 'NEW', 'name' => 'New', 'channel_type' => 'fax'])->assertStatus(422)->assertJsonValidationErrors('channel_type');

    $this->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage'])->assertStatus(422)->assertJsonValidationErrors('allocation_percentage');
    $this->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'fixed_reserve'])->assertStatus(422)->assertJsonValidationErrors('fixed_reserve_qty');
    $this->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage', 'allocation_percentage' => 150])->assertStatus(422);

    $rule = $this->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool'])->assertCreated()->json('data');
    $this->postJson("/api/v1/distribution/channels/{$channel['id']}/warehouses", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'shared_pool'])->assertStatus(422);

    $this->putJson("/api/v1/distribution/channel-warehouses/{$rule['id']}", ['warehouse_id' => $this->hub->id, 'allocation_mode' => 'percentage', 'allocation_percentage' => 25])
        ->assertOk()->assertJsonPath('data.allocation_percentage', '25.000');
    $this->getJson("/api/v1/distribution/channels/{$channel['id']}/warehouses")->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/distribution/channel-warehouses/{$rule['id']}")->assertOk();
    $this->deleteJson("/api/v1/distribution/channels/{$channel['id']}")->assertOk();

    expect(SalesChannel::query()->count())->toBe(0);
    // A deleted code is free again.
    drpChannel($this, 'ECOM', []);
});

it('hides the channels of other companies and refuses read-only roles', function (): void {
    $foreign = wmsInOtherTenant(fn (): SalesChannel => SalesChannel::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'channel_type' => 'custom']));

    $this->putJson("/api/v1/distribution/channels/{$foreign->id}", ['code' => 'FOREIGN', 'name' => 'Hack', 'channel_type' => 'custom'])->assertNotFound();
    $this->deleteJson("/api/v1/distribution/channels/{$foreign->id}")->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/distribution/channels', ['code' => 'RO', 'name' => 'RO', 'channel_type' => 'custom'])->assertForbidden();
    $this->getJson('/api/v1/distribution/channels')->assertOk();
});
