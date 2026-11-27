<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\WarehouseProfile;

require_once __DIR__.'/DistributionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    [$this->hub, $this->hubBin] = drpWarehouse('HUB');
    [$this->store, $this->storeBin] = drpWarehouse('STORE', 'retail_pos');
});

it('lists the warehouses with their role and profile, deriving the role of legacy warehouse types', function (): void {
    logisticsWarehouse(['code' => 'LEGACY-CONS', 'name' => 'Legacy consignment', 'warehouse_type' => 'customer_consignment']);
    logisticsWarehouse(['code' => 'LEGACY-TRN', 'name' => 'Legacy transit', 'warehouse_type' => 'transit']);

    $rows = collect($this->getJson('/api/v1/distribution/network/warehouses')->assertOk()->json('data'))->keyBy('code');

    expect($rows['HUB']['role'])->toBe('internal')
        ->and($rows['STORE']['role'])->toBe('retail_pos')
        ->and($rows['STORE']['profile']['role'])->toBe('retail_pos')
        ->and($rows['LEGACY-CONS']['role'])->toBe('consignment_out')
        ->and($rows['LEGACY-CONS']['profile'])->toBeNull()
        ->and($rows['LEGACY-TRN']['role'])->toBe('transit');

    $this->getJson('/api/v1/distribution/network/warehouses?q=legacy')->assertJsonCount(2, 'data');
});

it('saves the consignment terms and the hub of a warehouse and segregates the stock of third parties by default', function (): void {
    [$consignment] = drpWarehouse('CON', 'internal', 'customer_consignment');

    $profile = $this->putJson("/api/v1/distribution/network/warehouses/{$consignment->id}/profile", [
        'role' => 'consignment_out', 'parent_warehouse_id' => $this->hub->id, 'settlement_trigger' => 'monthly_report', 'billing_frequency' => 'quarterly',
        'max_consignment_days' => 90, 'is_insured' => true, 'external_reference' => 'PARTNER-9', 'notes' => 'Hotel Panorama',
    ])->assertOk()->json('data');

    expect($profile['role'])->toBe('consignment_out')
        ->and($profile['parent_warehouse_id'])->toBe($this->hub->id)
        ->and($profile['settlement_trigger'])->toBe('monthly_report')
        ->and($profile['billing_frequency'])->toBe('quarterly')
        ->and($profile['max_consignment_days'])->toBe(90)
        ->and($profile['is_insured'])->toBeTrue()
        ->and($profile['include_in_free_stock'])->toBeTrue();

    // The profile exists already (drpWarehouse): a first profile of a segregated role does not join the free stock.
    [$threePl] = [logisticsWarehouse(['code' => '3PL-NEW'])];
    $fresh = $this->putJson("/api/v1/distribution/network/warehouses/{$threePl->id}/profile", ['role' => 'third_party_3pl'])->assertOk()->json('data');
    expect($fresh['include_in_free_stock'])->toBeFalse()->and($fresh['counts_in_valuation'])->toBeTrue();

    $inbound = logisticsWarehouse(['code' => 'CON-IN-NEW']);
    expect($this->putJson("/api/v1/distribution/network/warehouses/{$inbound->id}/profile", ['role' => 'consignment_in'])->json('data.counts_in_valuation'))->toBeFalse()
        ->and(WarehouseProfile::query()->count())->toBe(5);
});

it('validates the profile', function (): void {
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->hub->id}/profile", ['role' => 'moon_base'])->assertStatus(422)->assertJsonValidationErrors('role');
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->hub->id}/profile", ['role' => 'internal', 'settlement_trigger' => 'whenever'])->assertStatus(422)->assertJsonValidationErrors('settlement_trigger');
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->hub->id}/profile", ['role' => 'internal', 'parent_warehouse_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('parent_warehouse_id');
    $this->putJson('/api/v1/distribution/network/warehouses/'.Str::uuid().'/profile', ['role' => 'internal'])->assertNotFound();
});

it('creates, updates, filters and deletes lanes with a unique route per transport mode', function (): void {
    $lane = $this->postJson('/api/v1/distribution/lanes', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 2, 'lead_time_hours' => 6, 'transport_mode' => 'road', 'cost_per_unit' => 0.35,
    ])->assertCreated()->json('data');

    expect($lane['lead_time_days'])->toBe(2)
        ->and($lane['transport_mode'])->toBe('road')
        ->and($lane['cost_per_unit'])->toBe('0.3500')
        ->and($lane['source_warehouse']['code'])->toBe('HUB')
        ->and($lane['destination_warehouse']['code'])->toBe('STORE')
        ->and(DistributionLane::query()->findOrFail($lane['id'])->totalLeadDays())->toBe(3);

    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1, 'transport_mode' => 'road'])
        ->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1, 'transport_mode' => 'air'])->assertCreated();
    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->hub->id, 'lead_time_days' => 1])->assertStatus(422);
    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1, 'transport_mode' => 'teleport'])->assertStatus(422)->assertJsonValidationErrors('transport_mode');

    $this->putJson("/api/v1/distribution/lanes/{$lane['id']}", ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 4, 'transport_mode' => 'road', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.lead_time_days', 4)->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/distribution/lanes')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/distribution/lanes?destination_warehouse_id={$this->hub->id}")->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/distribution/lanes/{$lane['id']}")->assertOk();
    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1, 'transport_mode' => 'road'])->assertCreated();
});

it('prefers the fastest active lane when the transfer is planned', function (): void {
    drpLane($this->hub, $this->store, 5, 'sea');
    drpLane($this->hub, $this->store, 2, 'road');
    $slowInactive = drpLane($this->hub, $this->store, 1, 'air');
    $slowInactive->update(['is_active' => false]);
    $item = drpItem();
    wmsReceive($this->hubBin, $item, '5');

    $transfer = $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id,
        'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]],
    ])->assertCreated()->json('data');

    expect($transfer['expected_arrival_date'])->toBe(now()->addDays(2)->toDateString());
});

it('serves the lookups with the enumerations, the warehouses and the items', function (): void {
    $item = drpItem();

    $lookups = $this->getJson('/api/v1/distribution/lookups')->assertOk()->json('data');

    expect($lookups['warehouse_roles'])->toBe(['internal', 'retail_pos', 'transit', 'third_party_3pl', 'consignment_out', 'consignment_in'])
        ->and($lookups['replenishment_policies'])->toBe(['min_max', 'reorder_point_fixed_qty', 'periodic_review', 'demand_driven_drp'])
        ->and($lookups['allocation_modes'])->toBe(['shared_pool', 'percentage', 'fixed_reserve', 'priority_rank'])
        ->and($lookups['bucket_types'])->toBe(['daily', 'weekly'])
        ->and(collect($lookups['warehouses'])->pluck('role', 'code')->all())->toMatchArray(['HUB' => 'internal', 'STORE' => 'retail_pos'])
        ->and(collect($lookups['items'])->pluck('sku')->all())->toContain($item->sku);
});

it('summarises the distribution in the dashboard', function (): void {
    $item = drpItem('4');
    wmsReceive($this->hubBin, $item, '50');

    $id = $this->postJson('/api/v1/distribution/transfers', [
        'source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'auto_approve' => true,
        'lines' => [['stock_item_id' => $item->id, 'quantity' => 10]],
    ])->json('data.id');
    $this->postJson("/api/v1/distribution/transfers/{$id}/dispatch")->assertOk();
    $this->postJson('/api/v1/distribution/transfers', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'auto_approve' => true, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();

    $dashboard = $this->getJson('/api/v1/distribution/dashboard')->assertOk()->json('data');

    expect($dashboard['active_transfers'])->toBe(2)
        ->and($dashboard['units_in_transit'])->toBe('10.0000')
        ->and($dashboard['value_in_transit'])->toBe('40.00')
        ->and($dashboard['overdue_shipments'])->toBe(0)
        ->and($dashboard['pending_proposals'])->toBe(0)
        ->and($dashboard['stockout_alerts'])->toBe(0)
        ->and($dashboard['consignment_stock_value'])->toBe('0.00')
        ->and($dashboard['latest_run'])->toBeNull();
});

it('hides the lanes of other companies and refuses read-only roles', function (): void {
    $foreign = wmsInOtherTenant(function (): DistributionLane {
        $source = logisticsWarehouse(['code' => 'F-SRC']);
        $destination = logisticsWarehouse(['code' => 'F-DST']);

        return drpLane($source, $destination);
    });

    $this->putJson("/api/v1/distribution/lanes/{$foreign->id}", ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/distribution/lanes/{$foreign->id}")->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/distribution/lanes', ['source_warehouse_id' => $this->hub->id, 'destination_warehouse_id' => $this->store->id, 'lead_time_days' => 1])->assertForbidden();
    $this->putJson("/api/v1/distribution/network/warehouses/{$this->hub->id}/profile", ['role' => 'internal'])->assertForbidden();
    $this->getJson('/api/v1/distribution/lanes')->assertOk();
    $this->getJson('/api/v1/distribution/lookups')->assertOk();
});
