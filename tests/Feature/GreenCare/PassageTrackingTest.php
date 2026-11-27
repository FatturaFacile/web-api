<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
    $this->site = gcSite($this);
    $this->crew = gcCrew($this);
});

test('a passage inside the included passages stays in the contract', function (): void {
    $contract = gcContract($this, $this->site, ['included_passages_count' => 2]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop, ['crew_hours' => [['member_id' => gcOperator($this->crew)->id, 'hours' => 2.5]]])->assertOk()
        ->assertJsonPath('data.passage_number', 1)
        ->assertJsonPath('data.passage_included', 2)
        ->assertJsonPath('data.is_extra_passage', false)
        ->assertJsonPath('data.billing_status', 'included_in_contract')
        ->assertJsonPath('data.crew_hours_logged.total', 2.5);

    expect($contract->refresh()->completed_passages_count)->toBe(1);
});

test('passage 17 of 16 is an extra passage ready to invoice at the extra price', function (): void {
    $contract = gcContract($this, $this->site, ['included_passages_count' => 16, 'completed_passages_count' => 16, 'extra_passage_unit_price' => 85]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop)->assertOk()
        ->assertJsonPath('data.passage_number', 17)
        ->assertJsonPath('data.is_extra_passage', true)
        ->assertJsonPath('data.billing_status', 'ready_to_invoice');

    expect($stop->refresh()->extra_passage_amount)->toBe('85.00')
        ->and($contract->refresh()->completed_passages_count)->toBe(17)
        ->and($contract->extraPassagesCount())->toBe(1);

    $this->getJson(GC_API.'/contracts/'.$contract->id)->assertOk()
        ->assertJsonPath('data.passage_counter.completed', 17)
        ->assertJsonPath('data.passage_counter.included', 16)
        ->assertJsonPath('data.passage_counter.extra', 1)
        ->assertJsonPath('data.passage_counter.is_over_included', true);
});

test('an extra passage of a contract that does not auto-bill is flagged but not billed', function (): void {
    $contract = gcContract($this, $this->site, ['included_passages_count' => 1, 'completed_passages_count' => 1, 'auto_bill_extra_passages' => false]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop)->assertOk()
        ->assertJsonPath('data.is_extra_passage', true)
        ->assertJsonPath('data.billing_status', 'not_billed');
});

test('a time and material contract bills the hours, never the passage', function (): void {
    $contract = gcContract($this, $this->site, ['billing_mode' => 'time_and_material', 'included_passages_count' => 0, 'time_and_material_hourly_rate' => 45]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop)->assertOk()
        ->assertJsonPath('data.is_extra_passage', false)
        ->assertJsonPath('data.billing_status', 'ready_to_invoice');
});

test('a stop that does not count toward the contract leaves the counter alone', function (): void {
    $contract = gcContract($this, $this->site);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop, ['counts_toward_contract_passage' => false])->assertOk()->assertJsonPath('data.passage_number', null);

    expect($contract->refresh()->completed_passages_count)->toBe(0);
});

test('a stop is closed only once', function (): void {
    $contract = gcContract($this, $this->site);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);

    gcCloseStop($this, $stop)->assertOk();
    gcCloseStop($this, $stop)->assertStatus(422)->assertJsonPath('code', 'stop_already_completed');

    expect($contract->refresh()->completed_passages_count)->toBe(1);
});

test('closing the last stop completes the route and the first close-out starts it', function (): void {
    $contract = gcContract($this, $this->site);
    $second = gcSite($this);
    [$schedule, $first] = gcStop($this, $this->crew, $this->site, $contract);
    [, $other] = gcStop($this, $this->crew, $second, gcContract($this, $second));

    gcCloseStop($this, $first)->assertOk();
    expect(CrewRouteSchedule::query()->find($schedule->id)->status->value)->toBe('in_progress');

    gcCloseStop($this, $other)->assertOk();
    expect(CrewRouteSchedule::query()->find($schedule->id)->status->value)->toBe('completed');
});

test('green waste weight on the close-out creates a slip with the default code of the jurisdiction', function (): void {
    [, $stop] = gcStop($this, $this->crew, $this->site, gcContract($this, $this->site));

    gcCloseStop($this, $stop, ['green_waste_weight_kg' => 180.5, 'compost_facility_id' => $this->composter->id])->assertOk();

    $slip = GreenWasteSlip::query()->where('route_stop_id', $stop->id)->first();
    expect($slip)->not->toBeNull()
        ->and($slip->waste_code)->toBe('CER 20 02 01')
        ->and((float) $slip->green_waste_weight_kg)->toBe(180.5)
        ->and($slip->transporter_vehicle_plate)->toBe('FG123AB')
        ->and($slip->slip_number)->toStartWith('GW-2026-');
});

test('plants and materials leave the warehouse and are written on the stop', function (): void {
    $warehouse = tfrWarehouse($this->tenant->id, 'GC1', ['is_primary' => true]);
    $plant = tfrProduct($this->tenant->id, 'LAUR-01', 4.0, 18.0);
    [, $stop] = gcStop($this, $this->crew, $this->site, gcContract($this, $this->site));

    gcCloseStop($this, $stop, ['materials' => [['product_id' => $plant->id, 'quantity' => 3, 'warehouse_id' => $warehouse->id]]])->assertOk()
        ->assertJsonPath('data.materials_and_plants_used.0.product_id', $plant->id)
        ->assertJsonPath('data.materials_and_plants_used.0.quantity', 3);

    expect(CrewRouteStop::query()->find($stop->id)->materials_and_plants_used[0]['stock_movement_id'])->not->toBeNull();
});

test('stops and contracts of another company are out of reach', function (): void {
    $contract = gcContract($this, $this->site);
    [, $stop] = gcStop($this, $this->crew, $this->site, $contract);
    $stranger = billingActor($this, 'accountant', $this->otherTenant);

    gcCloseStop($this, $stop)->assertNotFound();
    expect(SeasonalMaintenanceContract::withoutGlobalScopes()->find($contract->id)->completed_passages_count)->toBe(0)
        ->and($stranger)->not->toBeNull();
});
