<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

test('a cycle starts only for a weighed lot and puts it in the hopper', function (): void {
    omSetUp($this);
    $booked = omDelivery($this, ['weighing' => null]);

    $this->postJson(OM_API.'/milling-cycles', ['delivery_id' => $booked->id])->assertUnprocessable()->assertJsonPath('code', 'delivery_not_weighed');

    $weighed = omDelivery($this);

    $this->postJson(OM_API.'/milling-cycles', ['delivery_id' => $weighed->id, 'line_code' => 'L2', 'malaxation_temperature_c' => 25.5, 'water_added_l' => 10])
        ->assertCreated()
        ->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.line_code', 'L2')
        ->assertJsonPath('data.input_net_kg', '4000.000');

    expect($weighed->refresh()->status->value)->toBe('IN_HOPPER');

    $this->postJson(OM_API.'/milling-cycles', ['delivery_id' => $weighed->id])->assertUnprocessable();
});

test('the completion computes the yield, the cold extraction and the quality class', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 600])
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED')
        ->assertJsonPath('data.yield_percent', '15.00')
        ->assertJsonPath('data.cold_extraction', true)
        ->assertJsonPath('data.quality_class', 'EVO')
        ->assertJsonPath('data.output_liters', '655.022')
        ->assertJsonPath('data.pickup_kg', '600.000');

    expect($cycle->refresh()->delivery->status->value)->toBe('MILLED');
});

test('a malaxation above 27 degrees is not a cold extraction', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this), ['malaxation_temperature_c' => 29]);

    omComplete($this, $cycle)->assertOk()->assertJsonPath('data.cold_extraction', false);
});

test('the oil can be entered in litres and is converted with the density', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['output_kg' => null, 'output_liters' => 1000])
        ->assertOk()
        ->assertJsonPath('data.output_kg', '916.000')
        ->assertJsonPath('data.yield_percent', '22.90');
});

test('the completion needs the oil in kilograms or in litres', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/complete', ['acidity_pct' => 0.4])->assertUnprocessable()->assertJsonValidationErrors(['output_kg', 'output_liters']);
});

test('the oil is routed to the farmer, the molenda and the tank with the SIAN operations', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 400, 'molenda_kg' => 100, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])
        ->assertOk()
        ->assertJsonPath('data.molenda_kg', '100.000')
        ->assertJsonPath('data.mill_stock_kg', '100.000');

    $tank->refresh();
    expect($tank->stock_kg)->toBe('100.000')->and($tank->category?->value)->toBe('EVO')->and($tank->origin_designation?->value)->toBe('ITALIA')
        ->and($tank->lot_composition)->toEqual(['2027-OL-00001' => 100]);

    $movements = SianMovement::query()->orderBy('progressive')->get();

    expect($movements->pluck('causal')->map(fn ($c) => $c->value)->all())->toBe(['C02', 'S02'])
        ->and($movements[0]->quantity_kg)->toBe('100.000')
        ->and($movements[0]->to_tank_id)->toBe($tank->id)
        ->and($movements[0]->counterparty_cuaa)->toBe('RSSMRA85T10A562S')
        ->and($movements[0]->transmission_deadline->toDateString())->toBe('2027-11-16')
        ->and($movements[0]->status->value)->toBe('DRAFT')
        ->and($movements[1]->quantity_kg)->toBe('400.000')
        ->and($movements[1]->to_tank_id)->toBeNull();
});

test('the product defaults to the farmer pickup and to the tank for the own account', function (): void {
    omSetUp($this);
    $thirdParty = omStartCycle($this, omDelivery($this));
    omComplete($this, $thirdParty)->assertOk()->assertJsonPath('data.pickup_kg', '600.000')->assertJsonPath('data.mill_stock_kg', '0.000');

    $tank = omTank($this, ['code' => 'S2']);
    $own = omStock($this, $tank, 600);

    expect($own->mill_stock_kg)->toBe('600.000')->and($own->status->value)->toBe('SETTLED')->and($own->delivery->status->value)->toBe('SETTLED')
        ->and($tank->refresh()->stock_kg)->toBe('600.000');

    $load = SianMovement::query()->where('causal', 'C01')->firstOrFail();

    expect($load->counterparty_contact_id)->toBeNull()->and($load->quantity_kg)->toBe('600.000');
});

test('the own account keeps all the oil in the mill', function (): void {
    omSetUp($this);
    $tank = omTank($this);
    $cycle = omStartCycle($this, omDelivery($this, ['processing_mode' => 'CONTO_PROPRIO', 'farmer_contact_id' => null]));

    omComplete($this, $cycle, ['pickup_kg' => 100, 'mill_stock_kg' => 500, 'target_tank_id' => $tank->id])->assertUnprocessable()->assertJsonPath('code', 'own_account_no_farmer_share');
});

test('pickup, molenda and stock must add up to the oil obtained', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 300, 'molenda_kg' => 100, 'mill_stock_kg' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'destination_mismatch');

    expect($cycle->refresh()->status->value)->toBe('IN_PROGRESS');
});

test('oil kept in the mill needs a destination tank', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 500, 'mill_stock_kg' => 100])->assertUnprocessable()->assertJsonPath('code', 'target_tank_required');
});

test('the tank cannot overflow and nothing is written when it would', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S3', 'capacity_kg' => 50]);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 500, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])->assertUnprocessable()->assertJsonPath('code', 'tank_capacity_exceeded');

    expect(SianMovement::query()->count())->toBe(0)->and($tank->refresh()->stock_kg)->toBe('0.000')->and($cycle->refresh()->status->value)->toBe('IN_PROGRESS');
});

test('a lot declared DOP whose acidity is too high is stored as a lower category and warned', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S4']);
    $cycle = omStartCycle($this, omDelivery($this, ['certification' => 'DOP']));

    omComplete($this, $cycle, ['acidity_pct' => 1.2, 'peroxides' => 10, 'pickup_kg' => 500, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])
        ->assertOk()
        ->assertJsonPath('data.quality_class', 'VERGINE')
        ->assertJsonPath('data.quality_warnings.0', 'quality_below_declared');

    expect($tank->refresh()->category?->value)->toBe('VERGINE');
});

test('DOP oil cannot be poured into a tank of extra virgin oil', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S5']);
    omStock($this, $tank, 300, 'EVO');

    $cycle = omStartCycle($this, omDelivery($this, ['certification' => 'DOP']));

    omComplete($this, $cycle, ['pickup_kg' => 500, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])->assertUnprocessable()->assertJsonPath('code', 'dop_igp_mixing');
});

test('the SIAN movement of a farmer without a valid CUAA is refused', function (): void {
    omSetUp($this);
    $anonymous = omFarmer($this, ['fiscal_code' => null, 'vat_number' => null, 'company_name' => 'Senza codice']);
    $cycle = omStartCycle($this, omDelivery($this, ['farmer_contact_id' => $anonymous->id]));

    omComplete($this, $cycle)->assertUnprocessable()->assertJsonPath('code', 'counterparty_required');

    expect(SianMovement::query()->count())->toBe(0);
});

test('a grain cycle has a flour yield and no oil register', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this, ['commodity' => 'CEREALI', 'certification' => 'CONVENZIONALE', 'weighing' => omWeighing(1400)]));

    omComplete($this, $cycle, ['output_kg' => 800, 'bran_kg' => 150, 'acidity_pct' => null, 'peroxides' => null])
        ->assertOk()
        ->assertJsonPath('data.yield_percent', '80.00')
        ->assertJsonPath('data.output_liters', '0.000')
        ->assertJsonPath('data.bran_kg', '150.000')
        ->assertJsonPath('data.quality_class', null);

    expect(SianMovement::query()->count())->toBe(0);
});

test('the by-products of the cycle are kept', function (): void {
    omSetUp($this);
    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['sansa_kg' => 2400, 'nocciolino_kg' => 300, 'vegetation_water_l' => 1800])
        ->assertOk()
        ->assertJsonPath('data.sansa_kg', '2400.000')
        ->assertJsonPath('data.nocciolino_kg', '300.000')
        ->assertJsonPath('data.vegetation_water_l', '1800.000');
});

test('a closed cycle cannot be closed again', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    omComplete($this, $cycle)->assertUnprocessable()->assertJsonPath('code', 'cycle_not_open');
});

test('the cycles are listed and shown with the lot and the farmer', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);
    omStartCycle($this, omDelivery($this));

    $this->getJson(OM_API.'/milling-cycles?status=COMPLETED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lot_code', '2027-OL-00001')->assertJsonPath('data.0.farmer_name', 'Azienda Agricola Rossi');
    $this->getJson(OM_API.'/milling-cycles')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(OM_API.'/milling-cycles/'.$cycle->id)->assertOk()->assertJsonPath('data.id', $cycle->id)->assertJsonPath('data.yield_percent', '15.00');
});

test('a cycle of another company is not found', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(OM_API.'/milling-cycles/'.$cycle->id)->assertNotFound();
    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/complete', ['output_kg' => 10])->assertNotFound();

    expect(MillingCycle::query()->count())->toBe(0);
});
