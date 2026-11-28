<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;

require_once __DIR__.'/BondedWarehousesSupport.php';

uses(RefreshDatabase::class);

test('an inbound MRN creates the lot, engages the CGU and writes the ledger entry', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);

    $response = bwInbound($this, $authorization, 1)->assertCreated()
        ->assertJsonPath('data.mrn_number', bwMrn(1))
        ->assertJsonPath('data.customs_status', 'NonUnion_Suspended')
        ->assertJsonPath('data.suspended_duty_amount', '100.00')
        ->assertJsonPath('data.suspended_vat_amount', '242.00')
        ->assertJsonPath('data.cgu_engaged_amount', '342.00')
        ->assertJsonPath('data.remaining_quantity', '100.0000');

    $entry = CguLedgerEntry::query()->where('authorization_id', $authorization->id)->sole();

    expect($authorization->refresh()->cgu_utilized_amount)->toBe('342.00')
        ->and($entry->movement_direction->value)->toBe('Engage')
        ->and($entry->reference_type->value)->toBe('MRN_Inbound')
        ->and($entry->reference_id)->toBe($response->json('data.id'))
        ->and($entry->total_cgu_delta)->toBe('342.00')
        ->and($entry->running_utilized_balance)->toBe('342.00')
        ->and($entry->remaining_plafond)->toBe('9658.00');
});

test('an inbound MRN that would exceed the CGU ceiling is refused and writes nothing', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this, ['cgu_max_ceiling_amount' => 300]);

    bwInbound($this, $authorization, 1)->assertStatus(422)
        ->assertJsonPath('code', 'cgu_ceiling_exceeded')
        ->assertJsonPath('context.available', '300.00')
        ->assertJsonPath('context.requested', '342.00');

    expect(BondedMrnLot::query()->count())->toBe(0)
        ->and(CguLedgerEntry::query()->count())->toBe(0)
        ->and($authorization->refresh()->cgu_utilized_amount)->toBe('0.00');
});

test('the MRN, the TARIC code and the duplicate are validated', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);

    bwInbound($this, $authorization, 1, ['mrn_number' => '26IT123'])->assertStatus(422)->assertJsonValidationErrors('mrn_number');
    bwInbound($this, $authorization, 1, ['taric_hs_code' => '0901'])->assertStatus(422)->assertJsonValidationErrors('taric_hs_code');

    bwInbound($this, $authorization, 1)->assertCreated();
    bwInbound($this, $authorization, 1)->assertStatus(409)->assertJsonPath('code', 'mrn_already_registered');
    bwInbound($this, $authorization, 1, ['mrn_item_number' => 2])->assertCreated();
});

test('the declaration type must fit the regime and the authorization must be valid on the entry date', function (): void {
    bwSetUp($this);
    $cdu = bwAuthorization($this);
    $ozl = bwAuthorization($this, ['regime_type' => 'Swiss_OZL', 'currency' => 'CHF']);
    $expired = bwAuthorization($this, ['valid_to' => '2027-03-31']);
    $depot = bwAuthorization($this, ['regime_type' => 'VatDepot_Art50Bis']);

    bwInbound($this, $cdu, 1, ['declaration_type' => 'Passar_Import'])->assertStatus(422)->assertJsonPath('code', 'declaration_type_not_allowed');
    bwInbound($this, $ozl, 2, ['declaration_type' => 'IM7'])->assertStatus(422)->assertJsonPath('code', 'declaration_type_not_allowed');
    bwInbound($this, $ozl, 3, ['declaration_type' => 'Passar_Import'])->assertCreated();
    bwInbound($this, $cdu, 4, ['declaration_type' => 'T1'])->assertCreated();
    bwInbound($this, $expired, 5)->assertStatus(422)->assertJsonPath('code', 'authorization_inactive');
    bwInbound($this, $depot, 6)->assertStatus(422)->assertJsonPath('code', 'regime_not_customs_warehouse');
});

test('the plafond endpoint reports ceiling, utilized and available per authorization', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);

    $row = collect($this->getJson(BW_API.'/cgu/plafond')->assertOk()->json('data'))->firstWhere('authorization_id', $authorization->id);

    expect($row['ceiling'])->toBe('10000.00')
        ->and($row['utilized'])->toBe('342.00')
        ->and($row['available'])->toBe('9658.00')
        ->and($row['utilization_percent'])->toBe(3.42);

    $this->getJson(BW_API.'/cgu/ledger?authorization_id='.$authorization->id)->assertOk()->assertJsonCount(1, 'data');
});

test('authorizations are created, changed and deleted, and the ceiling cannot drop below the guarantee in use', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);

    $payload = bwAuthorizationPayload($this, ['authorization_code' => $authorization->authorization_code, 'cgu_max_ceiling_amount' => 200]);
    $this->putJson(BW_API.'/authorizations/'.$authorization->id, $payload)->assertStatus(422)->assertJsonPath('code', 'ceiling_below_utilized');

    $this->putJson(BW_API.'/authorizations/'.$authorization->id, array_merge($payload, ['cgu_max_ceiling_amount' => 20000]))->assertOk()->assertJsonPath('data.cgu_max_ceiling_amount', '20000.00');
    $this->deleteJson(BW_API.'/authorizations/'.$authorization->id)->assertStatus(409)->assertJsonPath('code', 'authorization_in_use');

    $free = bwAuthorization($this);
    $this->deleteJson(BW_API.'/authorizations/'.$free->id)->assertOk();
    expect(CustomsWarehouseAuthorization::query()->whereKey($free->id)->exists())->toBeFalse();
});

test('the authorization code is unique per company and an AEO status needs its certificate', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);

    $this->postJson(BW_API.'/authorizations', bwAuthorizationPayload($this, ['authorization_code' => $authorization->authorization_code]))->assertStatus(422)->assertJsonValidationErrors('authorization_code');
    $this->postJson(BW_API.'/authorizations', bwAuthorizationPayload($this, ['aeo_certificate_number' => null]))->assertStatus(422)->assertJsonValidationErrors('aeo_certificate_number');
    $this->postJson(BW_API.'/authorizations', bwAuthorizationPayload($this, ['aeo_status' => 'None', 'aeo_certificate_number' => null, 'aeo_next_audit_date' => null]))->assertCreated();
});

test('another company never sees the authorizations, lots or ledger', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1);

    treasuryActAs($this, treasuryActor($this, 'accountant', $this->otherTenant), $this->otherTenant);

    $this->getJson(BW_API.'/authorizations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(BW_API.'/mrn-lots')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(BW_API.'/cgu/ledger')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(BW_API.'/mrn-lots/'.$lot->id)->assertNotFound();
});

test('read access does not write and a role without access sees nothing', function (): void {
    bwSetUp($this, 'accountant_readonly');

    $this->getJson(BW_API.'/authorizations')->assertOk();
    $this->postJson(BW_API.'/authorizations', bwAuthorizationPayload($this))->assertForbidden();

    treasuryActAs($this, treasuryActor($this, 'sales'));
    $this->getJson(BW_API.'/authorizations')->assertForbidden();
});

test('the MRN lots are filtered by MRN, TARIC code, origin and status', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);
    bwLot($this, $authorization, 2, ['taric_hs_code' => '18010000', 'country_of_origin' => 'GH']);

    $this->getJson(BW_API.'/mrn-lots?mrn='.bwMrn(2))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(BW_API.'/mrn-lots?taric=1801')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.country_of_origin', 'GH');
    $this->getJson(BW_API.'/mrn-lots?origin=br')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(BW_API.'/mrn-lots?status=FullyDischarged')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(BW_API.'/mrn-lots?regime_type=CDU_Private_7100')->assertOk()->assertJsonCount(2, 'data');
});
