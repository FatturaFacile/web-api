<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

test('the pomace sent to the pomace mill needs the buyer and the transport document', function (): void {
    omSetUp($this);
    $payload = ['byproduct_type' => 'SANSA_VERGINE', 'movement_date' => '2027-11-10', 'quantity' => 2400];

    $this->postJson(OM_API.'/mill-byproducts', $payload)->assertUnprocessable()->assertJsonPath('code', 'counterparty_required');
    $this->postJson(OM_API.'/mill-byproducts', $payload + ['counterparty_name' => 'Sansificio Sud'])->assertUnprocessable()->assertJsonPath('code', 'transport_document_required');

    $this->postJson(OM_API.'/mill-byproducts', $payload + ['counterparty_name' => 'Sansificio Sud', 'transport_document' => 'DDT 15/2027'])
        ->assertCreated()
        ->assertJsonPath('data.unit', 'kg')
        ->assertJsonPath('data.destination_type', 'SANSIFICIO')
        ->assertJsonPath('data.transport_document', 'DDT 15/2027');
});

test('the buyer can be a contact of the company', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/mill-byproducts', [
        'byproduct_type' => 'SANSA_VERGINE', 'movement_date' => '2027-11-10', 'quantity' => 100, 'counterparty_contact_id' => $this->farmer->id, 'transport_document' => 'DDT 1',
    ])
        ->assertCreated()
        ->assertJsonPath('data.counterparty_name', 'Azienda Agricola Rossi');
});

test('olive pits and bran need no documents', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/mill-byproducts', ['byproduct_type' => 'NOCCIOLINO', 'movement_date' => '2027-11-10', 'quantity' => 300])
        ->assertCreated()
        ->assertJsonPath('data.destination_type', 'BIOMASS');
    $this->postJson(OM_API.'/mill-byproducts', ['byproduct_type' => 'CRUSCA', 'movement_date' => '2027-11-10', 'quantity' => 150])
        ->assertCreated()
        ->assertJsonPath('data.destination_type', 'FEED');
});

test('the vegetation water spread on land needs the parcel and respects the yearly limit per hectare', function (): void {
    omSetUp($this);
    $spreading = ['byproduct_type' => 'ACQUE_VEGETAZIONE', 'movement_date' => '2027-11-12', 'quantity' => 60];

    $this->postJson(OM_API.'/mill-byproducts', $spreading)->assertUnprocessable()->assertJsonPath('code', 'spreading_parcel_required');

    $parcel = $spreading + ['cadastral_parcel' => 'Foglio 12 part. 45', 'parcel_area_ha' => 2];

    $this->postJson(OM_API.'/mill-byproducts', $parcel)
        ->assertCreated()
        ->assertJsonPath('data.unit', 'm3')
        ->assertJsonPath('data.m3_per_ha', '30.000')
        ->assertJsonPath('data.destination_type', 'LAND_SPREADING');

    // 60 + 50 m3 over 2 ha is 55 m3/ha in the year: above the limit of 50.
    $this->postJson(OM_API.'/mill-byproducts', ['quantity' => 50] + $parcel)->assertUnprocessable()->assertJsonPath('code', 'spreading_limit_exceeded');
    $this->postJson(OM_API.'/mill-byproducts', ['quantity' => 40] + $parcel)->assertCreated();

    // Another parcel, or another year, starts from zero.
    $this->postJson(OM_API.'/mill-byproducts', ['cadastral_parcel' => 'Foglio 13 part. 2'] + $parcel)->assertCreated();
    $this->postJson(OM_API.'/mill-byproducts', ['movement_date' => '2028-11-12', 'quantity' => 90] + $parcel)->assertCreated();

    expect(ByproductMovement::query()->count())->toBe(4);
});

test('the by-product register filters by type and parcel', function (): void {
    omSetUp($this);
    $this->postJson(OM_API.'/mill-byproducts', ['byproduct_type' => 'NOCCIOLINO', 'movement_date' => '2027-11-10', 'quantity' => 300])->assertCreated();
    $this->postJson(OM_API.'/mill-byproducts', ['byproduct_type' => 'ACQUE_VEGETAZIONE', 'movement_date' => '2027-11-12', 'quantity' => 20, 'cadastral_parcel' => 'P1', 'parcel_area_ha' => 1])->assertCreated();

    $this->getJson(OM_API.'/mill-byproducts')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(OM_API.'/mill-byproducts?byproduct_type=ACQUE_VEGETAZIONE')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(OM_API.'/mill-byproducts?cadastral_parcel=P1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '20.000');
    $this->getJson(OM_API.'/mill-byproducts?date_from=2027-11-11')->assertOk()->assertJsonCount(1, 'data');
});

test('the dashboard summarises the campaign', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);

    // 4000 kg of olives for 600 kg of oil (15 %), 100 kg kept in the tank and 2400 kg of pomace.
    $cycle = omStartCycle($this, omDelivery($this));
    omComplete($this, $cycle, ['pickup_kg' => 400, 'molenda_kg' => 100, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id, 'sansa_kg' => 2400])->assertOk();
    // A lot in the hopper shows on the board of the lines.
    omStartCycle($this, omDelivery($this), ['line_code' => 'L2']);

    $this->getJson(OM_API.'/mill-dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.campaign_year', 2027)
        ->assertJsonPath('data.olives_milled_kg', 4000.0)
        ->assertJsonPath('data.olives_milled_quintals', 40.0)
        ->assertJsonPath('data.oil_produced_kg', 600.0)
        ->assertJsonPath('data.average_yield_percent', 15.0)
        ->assertJsonPath('data.cold_extraction_cycles', 1)
        ->assertJsonPath('data.deliveries_by_status.MILLED', 1)
        ->assertJsonPath('data.deliveries_by_status.IN_HOPPER', 1)
        ->assertJsonPath('data.quality_breakdown.EVO', 600.0)
        ->assertJsonPath('data.byproducts_produced.sansa_kg', 2400.0)
        ->assertJsonPath('data.hoppers.0.line', 'L2')
        ->assertJsonPath('data.sian.overdue', 0)
        ->assertJsonPath('data.yield_trend.0.yield_percent', 15.0);
});

test('the dashboard counts the oil kept as molenda and the oil in the tanks by category', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    omStock($this, $tank, 300);
    $cycle = omCompletedCycle($this);
    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', [
        'tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'HYBRID_MOLENDA', 'molenda_percent' => 10, 'oil_price_per_kg' => 8, 'tank_id' => $tank->id,
    ])->assertCreated();

    $stats = $this->getJson(OM_API.'/mill-dashboard/stats')->assertOk()->assertJsonPath('data.molenda_retained_kg', 60.0)->json('data');

    $evo = collect($stats['oil_stock_by_category'])->firstWhere('category', 'EVO');

    expect($evo['stock_kg'])->toBe(360.0)->and($evo['tanks'])->toBe(1);
});

test('another campaign year has its own figures', function (): void {
    omSetUp($this);
    omCompletedCycle($this);

    $this->getJson(OM_API.'/mill-dashboard/stats?campaign_year=2026')->assertOk()->assertJsonPath('data.olives_milled_kg', 0.0)->assertJsonPath('data.average_yield_percent', 0.0);
});

test('the reference lists the codes and the constants of the module', function (): void {
    omSetUp($this);

    $this->getJson(OM_API.'/reference')
        ->assertOk()
        ->assertJsonPath('data.processing_modes.0', 'CONTO_TERZI')
        ->assertJsonPath('data.constants.density_kg_per_l', 0.916)
        ->assertJsonPath('data.constants.sian_deadline_days', 6)
        ->assertJsonPath('data.constants.cold_extraction_max_c', 27.0)
        ->assertJsonPath('data.constants.sms_lead_minutes', 45)
        ->assertJsonCount(8, 'data.sian_causals');
});

test('a member of a role outside the module cannot reach it', function (): void {
    omSetUp($this, 'member');

    $this->getJson(OM_API.'/mill-dashboard/stats')->assertForbidden();
});
