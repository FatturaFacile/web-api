<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gcSitePayload(object $test, array $overrides = []): array
{
    return array_merge([
        'code' => 'VILLA-01',
        'name' => 'Villa Rosa',
        'customer_id' => $test->customer->id,
        'address' => 'Via Roma 1, Como',
        'latitude' => 45.81,
        'longitude' => 9.08,
        'geo_polygon_coordinates' => ['type' => 'Polygon', 'coordinates' => [[[9.08, 45.81], [9.081, 45.81], [9.081, 45.811], [9.08, 45.811], [9.08, 45.81]]]],
        'lawn_area_sqm' => 850.5,
        'hedge_length_meters' => 62,
        'tree_count' => 9,
        'irrigation_system_specs' => ['zones' => 6, 'controller_type' => 'Rain Bird ESP-ME', 'winterized' => true],
        'access_gate_code' => '1234#',
        'botanical_inventory' => [['species' => 'Buxus sempervirens', 'planting_date' => '2021-03-12', 'pruning_schedule' => 'Jun / Sep', 'phytosanitary_notes' => 'Cydalima watch']],
    ], $overrides);
}

test('a site is created with its geometry, inventory and an encrypted gate code', function (): void {
    $id = $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.lawn_area_sqm', 850.5)
        ->assertJsonPath('data.access_gate_code', '1234#')
        ->assertJsonPath('data.irrigation_system_specs.winterized', true)
        ->assertJsonPath('data.botanical_inventory.0.species', 'Buxus sempervirens')
        ->assertJsonPath('data.customer_name', 'Bianchi SpA')
        ->json('data.id');

    expect(DB::table('green_space_sites')->where('id', $id)->value('access_gate_code'))->not->toBe('1234#');
    expect(GreenSpaceSite::query()->find($id)->access_gate_code)->toBe('1234#');
});

test('the polygon, the coordinates and the code are validated', function (): void {
    $this->postJson(GC_API.'/sites', gcSitePayload($this, ['geo_polygon_coordinates' => ['type' => 'Point', 'coordinates' => [1, 2]]]))->assertStatus(422)->assertJsonValidationErrors(['geo_polygon_coordinates']);
    $this->postJson(GC_API.'/sites', gcSitePayload($this, ['latitude' => 120]))->assertStatus(422)->assertJsonValidationErrors(['latitude']);
    $this->postJson(GC_API.'/sites', gcSitePayload($this, ['latitude' => 45.1, 'longitude' => null]))->assertStatus(422)->assertJsonValidationErrors(['longitude']);

    $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertCreated();
    $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('a customer of another company cannot be used', function (): void {
    $this->postJson(GC_API.'/sites', gcSitePayload($this, ['customer_id' => $this->foreignCustomer->id]))->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});

test('sites are listed, searched, changed and deleted', function (): void {
    $id = $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertCreated()->json('data.id');
    $this->postJson(GC_API.'/sites', gcSitePayload($this, ['code' => 'PARK-02', 'name' => 'Parco Verdi', 'is_active' => false]))->assertCreated();

    $this->getJson(GC_API.'/sites?search=rosa')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'VILLA-01');
    $this->getJson(GC_API.'/sites?is_active=false')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'PARK-02');

    $this->putJson(GC_API.'/sites/'.$id, gcSitePayload($this, ['name' => 'Villa Rosa Nord', 'tree_count' => 12]))->assertOk()->assertJsonPath('data.name', 'Villa Rosa Nord')->assertJsonPath('data.tree_count', 12);

    $this->deleteJson(GC_API.'/sites/'.$id)->assertOk();
    $this->getJson(GC_API.'/sites/'.$id)->assertNotFound();
});

test('the sites of another company are invisible', function (): void {
    $id = $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertCreated()->json('data.id');

    billingActor($this, 'accountant', $this->otherTenant);

    $this->getJson(GC_API.'/sites')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(GC_API.'/sites/'.$id)->assertNotFound();
    $this->deleteJson(GC_API.'/sites/'.$id)->assertNotFound();
});

test('a contract is numbered, takes the jurisdiction of the site and starts as a draft', function (): void {
    $site = gcSite($this);

    $payload = [
        'site_id' => $site->id, 'customer_id' => $this->customer->id, 'season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30',
        'billing_mode' => 'fixed_installments', 'included_passages_count' => 16, 'fixed_fee_amount' => 1200, 'installment_frequency' => 'monthly',
        'extra_passage_unit_price' => 85, 'included_services' => ['mowing', 'hedging'],
    ];

    $this->postJson(GC_API.'/contracts', $payload)->assertCreated()
        ->assertJsonPath('data.contract_number', 'GC-2026-0001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.vat_rate', 22)
        ->assertJsonPath('data.apply_condominium_withholding', false)
        ->assertJsonPath('data.passage_counter.included', 16);

    $this->postJson(GC_API.'/contracts', $payload)->assertCreated()->assertJsonPath('data.contract_number', 'GC-2026-0002');
});

test('an Italian condominium switches the withholding on by itself and needs its fiscal code', function (): void {
    $site = gcSite($this);
    $payload = [
        'site_id' => $site->id, 'customer_id' => $this->customer->id, 'condominium_administrator_id' => $this->administrator->id,
        'season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30', 'billing_mode' => 'per_passage', 'extra_passage_unit_price' => 90,
        'is_condominium' => true,
    ];

    $this->postJson(GC_API.'/contracts', $payload)->assertStatus(422)->assertJsonValidationErrors(['condominium_fiscal_code']);

    $this->postJson(GC_API.'/contracts', $payload + ['condominium_fiscal_code' => '90012345678'])->assertCreated()
        ->assertJsonPath('data.is_condominium', true)
        ->assertJsonPath('data.apply_condominium_withholding', true)
        ->assertJsonPath('data.condominium_administrator_name', 'Studio Rossi Amministrazioni');

    $this->postJson(GC_API.'/contracts', $payload + ['condominium_fiscal_code' => '90012345678', 'apply_condominium_withholding' => false])->assertCreated()
        ->assertJsonPath('data.apply_condominium_withholding', false);
});

test('a Swiss condominium never withholds and takes the Swiss VAT', function (): void {
    $site = gcSite($this, ['jurisdiction' => 'CH']);

    $this->postJson(GC_API.'/contracts', [
        'site_id' => $site->id, 'customer_id' => $this->customer->id, 'season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30',
        'billing_mode' => 'time_and_material', 'is_condominium' => true, 'condominium_fiscal_code' => 'CHE-123.456.788', 'apply_condominium_withholding' => true,
    ])->assertCreated()->assertJsonPath('data.apply_condominium_withholding', false)->assertJsonPath('data.vat_rate', 8.1)->assertJsonPath('data.jurisdiction', 'CH');
});

test('the contract dates and the snow threshold are validated', function (): void {
    $site = gcSite($this);
    $base = ['site_id' => $site->id, 'customer_id' => $this->customer->id, 'billing_mode' => 'per_passage'];

    $this->postJson(GC_API.'/contracts', $base + ['season_start_date' => '2026-09-30', 'season_end_date' => '2026-04-01'])->assertStatus(422)->assertJsonValidationErrors(['season_end_date']);
    $this->postJson(GC_API.'/contracts', $base + ['season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30', 'snow_removal_standby_fee' => 300])->assertStatus(422)->assertJsonValidationErrors(['snow_trigger_threshold_cm']);
    $this->postJson(GC_API.'/contracts', $base + ['season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30', 'billing_mode' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors(['billing_mode']);
});

test('a contract is activated and suspended, other transitions are refused', function (): void {
    $contract = gcContract($this, gcSite($this), ['status' => 'draft']);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/suspend')->assertStatus(422)->assertJsonPath('code', 'contract_invalid_transition');
    $this->postJson(GC_API.'/contracts/'.$contract->id.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson(GC_API.'/contracts/'.$contract->id.'/activate')->assertStatus(422)->assertJsonPath('code', 'contract_invalid_transition');
    $this->postJson(GC_API.'/contracts/'.$contract->id.'/suspend')->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson(GC_API.'/contracts/'.$contract->id.'/activate')->assertOk();
});

test('the counters of a contract cannot be written through the API', function (): void {
    $contract = gcContract($this, gcSite($this), ['completed_passages_count' => 5]);

    $this->putJson(GC_API.'/contracts/'.$contract->id, [
        'site_id' => $contract->site_id, 'customer_id' => $contract->customer_id, 'season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30',
        'billing_mode' => 'per_passage', 'completed_passages_count' => 0, 'installments_billed' => 0,
    ])->assertOk();

    expect(SeasonalMaintenanceContract::query()->find($contract->id)->completed_passages_count)->toBe(5);
});

test('crews are saved with their members, licenses are typed by jurisdiction', function (): void {
    $crew = $this->postJson(GC_API.'/crews', [
        'code' => 'NORD', 'name' => 'Squadra Nord', 'default_vehicle_plate' => 'AB123CD', 'equipment' => ['lawnmowers' => 3, 'snow_plows' => 1], 'daily_capacity_minutes' => 450,
        'members' => [['name' => 'Luca Neri', 'role' => 'foreman', 'hourly_rate' => 30], ['name' => 'Anna Gialli', 'role' => 'gardener']],
    ])->assertCreated()->assertJsonCount(2, 'data.members')->assertJsonPath('data.equipment.lawnmowers', 3)->assertJsonPath('data.equipment.sprayers', 0)->json('data');

    $memberId = $crew['members'][0]['id'];

    $this->postJson(GC_API.'/licenses', ['crew_team_member_id' => $memberId, 'license_type' => 'PNRP', 'license_number' => 'PNRP-TI-9', 'issue_date' => '2025-01-01', 'expiry_date' => '2030-01-01'])->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'CH')
        ->assertJsonPath('data.is_valid', true);

    $this->postJson(GC_API.'/licenses', ['crew_team_member_id' => $memberId, 'license_type' => 'PAN', 'license_number' => 'PAN-1', 'issue_date' => '2020-01-01', 'expiry_date' => '2026-01-01'])->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.is_valid', false);

    $this->postJson(GC_API.'/licenses', ['crew_team_member_id' => $memberId, 'license_type' => 'XXX', 'license_number' => 'N', 'issue_date' => '2025-01-01', 'expiry_date' => '2024-01-01'])->assertStatus(422)->assertJsonValidationErrors(['license_type', 'expiry_date']);

    $this->getJson(GC_API.'/licenses?operator_id='.$memberId.'&only_valid=1')->assertOk()->assertJsonPath('meta.total', 1);

    // Members left out of an update are removed, the ones sent with their id are kept.
    $this->putJson(GC_API.'/crews/'.$crew['id'], ['code' => 'NORD', 'name' => 'Squadra Nord', 'members' => [['id' => $memberId, 'name' => 'Luca Neri', 'role' => 'foreman']]])->assertOk()->assertJsonCount(1, 'data.members');
});

test('green waste slips follow the waste codes of the jurisdiction of the site', function (): void {
    $site = gcSite($this);
    $swiss = gcSite($this, ['jurisdiction' => 'CH']);
    $payload = ['site_id' => $site->id, 'disposal_date' => '2026-05-04', 'waste_code' => 'CER 20 02 01', 'green_waste_weight_kg' => 240, 'compost_facility_id' => $this->composter->id, 'document_reference' => 'FIR-2026-118', 'disposal_cost' => 31.2, 'is_billable_to_customer' => true];

    $this->postJson(GC_API.'/slips', $payload)->assertCreated()
        ->assertJsonPath('data.slip_number', 'GW-2026-0001')
        ->assertJsonPath('data.compost_facility_name', 'Compost Lombardia Srl')
        ->assertJsonPath('data.is_billable_to_customer', true);

    $this->postJson(GC_API.'/slips', ['waste_code' => 'ABC'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['waste_code']);
    $this->postJson(GC_API.'/slips', ['site_id' => $swiss->id, 'waste_code' => 'OTR 20 02 01', 'document_reference' => 'OTR-77'] + $payload)->assertCreated();
    $this->postJson(GC_API.'/slips', ['site_id' => $swiss->id, 'waste_code' => 'XYZ 99'] + $payload)->assertStatus(422);

    expect(GreenWasteSlip::query()->count())->toBe(2);
});

test('the slip summary groups the weights by waste code and by composting facility', function (): void {
    $site = gcSite($this);

    foreach ([['CER 20 02 01', 100, 1], ['CER 20 02 01', 50.5, 1], ['CER 20 02 02', 300, 0]] as $index => [$code, $kg, $billable]) {
        $this->postJson(GC_API.'/slips', ['site_id' => $site->id, 'disposal_date' => '2026-05-04', 'waste_code' => $code, 'green_waste_weight_kg' => $kg, 'compost_facility_id' => $this->composter->id, 'disposal_cost' => 10, 'is_billable_to_customer' => (bool) $billable])->assertCreated();
    }

    $summary = $this->getJson(GC_API.'/slips/summary?from=2026-05-01&to=2026-05-31')->assertOk()
        ->assertJsonPath('data.total_kg', 450.5)
        ->assertJsonPath('data.billable_cost', 20)
        ->assertJsonCount(2, 'data.by_waste_code')
        ->assertJsonPath('data.by_facility.0.slips', 3)
        ->json('data');

    expect(collect($summary['by_waste_code'])->firstWhere('waste_code', 'CER 20 02 01')['kg'])->toBe(150.5);
});

test('the reference lists the license types, waste codes and registration hints by jurisdiction', function (): void {
    $this->getJson(GC_API.'/reference')->assertOk()
        ->assertJsonPath('data.license_types.CH', ['PER', 'PNRP', 'OPF'])
        ->assertJsonPath('data.license_types.IT', ['PAN'])
        ->assertJsonPath('data.waste_codes.IT.0.code', 'CER 20 02 01')
        ->assertJsonPath('data.registration_number_hint.CH', 'W-1234')
        ->assertJsonPath('data.condominium_withholding_percent', 4)
        ->assertJsonPath('data.dosage_units', ['ml/hl', 'g/100sqm', 'l/ha']);
});

test('the dashboard counts contracts, passages, treatments, waste and expiring licenses', function (): void {
    $site = gcSite($this);
    gcContract($this, $site, ['included_passages_count' => 16, 'completed_passages_count' => 8]);
    gcContract($this, gcSite($this), ['included_passages_count' => 10, 'completed_passages_count' => 4, 'status' => 'draft']);
    $crew = gcCrew($this);
    gcLicense($this, gcOperator($crew), ['expiry_date' => '2026-06-15']);
    $this->postJson(GC_API.'/slips', ['site_id' => $site->id, 'disposal_date' => '2026-05-04', 'waste_code' => 'CER 20 02 01', 'green_waste_weight_kg' => 75])->assertCreated();

    $this->getJson(GC_API.'/dashboard')->assertOk()
        ->assertJsonPath('data.active_contracts', 1)
        ->assertJsonPath('data.passages_executed', 8)
        ->assertJsonPath('data.passages_contracted', 16)
        ->assertJsonPath('data.green_waste_kg', 75)
        ->assertJsonPath('data.treatments_logged', 0)
        ->assertJsonPath('data.expiring_licenses.0.days_to_expiry', 42);
});

test('members that only read cannot write, finance can', function (): void {
    $site = gcSite($this);

    billingActor($this, 'accountant_readonly');
    $this->getJson(GC_API.'/sites/'.$site->id)->assertOk();
    $this->postJson(GC_API.'/sites', gcSitePayload($this))->assertForbidden();
    $this->deleteJson(GC_API.'/sites/'.$site->id)->assertForbidden();
});
