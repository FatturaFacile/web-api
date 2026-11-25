<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/FranchiseNetworkSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fnetSetUp($this);
});

it('creates a store, lists it and issues a one-time link code', function (): void {
    $store = fnetStore($this, 'MI-01');

    $this->getJson('/api/v1/franchise-network/stores')->assertOk()->assertJsonFragment(['code' => 'MI-01']);
    $this->getJson('/api/v1/franchise-network/stores/'.$store['id'])->assertOk()->assertJsonPath('data.linked', false);
    $this->postJson('/api/v1/franchise-network/stores/'.$store['id'].'/link-code')->assertOk()->assertJsonStructure(['data' => ['code']]);
});

it('keeps the stores of a franchisor invisible to another company', function (): void {
    $store = fnetStore($this, 'MI-02');

    fnetActAs($this, 'owner', $this->otherTenant);

    $this->getJson('/api/v1/franchise-network/stores/'.$store['id'])->assertNotFound();
    $this->getJson('/api/v1/franchise-network/stores')->assertOk()->assertJsonMissing(['code' => 'MI-02']);
});

it('creates an agreement as a draft and activates it with its entry fee deferral schedule', function (): void {
    $store = fnetStore($this, 'MI-03');
    $formula = fnetFormula($this);

    $agreement = fnetAgreement($this, $store['id'], $formula['id'], ['entry_fee' => 12000, 'entry_fee_deferral_months' => 12], false);
    $this->assertSame('draft', $agreement['status']);

    $active = $this->postJson('/api/v1/franchise-network/agreements/'.$agreement['id'].'/activate')->assertOk()->json('data');

    expect($active['status'])->toBe('active');
    $this->getJson('/api/v1/franchise-network/agreements/'.$agreement['id'])->assertOk()->assertJsonCount(12, 'data.deferrals');
});

it('refuses a second active agreement for the same store', function (): void {
    $store = fnetStore($this, 'MI-04');
    $formula = fnetFormula($this);
    fnetAgreement($this, $store['id'], $formula['id']);

    $second = $this->postJson('/api/v1/franchise-network/agreements', fnetAgreementPayload($store['id'], $formula['id']))->assertCreated()->json('data');

    $this->postJson('/api/v1/franchise-network/agreements/'.$second['id'].'/activate')->assertStatus(409)->assertJsonPath('code', 'store_already_has_active_agreement');
});

it('refuses to activate an agreement whose exclusivity zone overlaps another one', function (): void {
    $formula = fnetFormula($this);
    $first = fnetStore($this, 'MI-05');
    $second = fnetStore($this, 'MI-06');
    fnetAgreement($this, $first['id'], $formula['id'], ['exclusivity_zone' => ['postal_codes' => ['20124', '20129']]]);

    $draft = fnetAgreement($this, $second['id'], $formula['id'], ['exclusivity_zone' => ['postal_codes' => ['20129']]], false);

    $this->postJson('/api/v1/franchise-network/agreements/'.$draft['id'].'/activate')->assertStatus(409)->assertJsonPath('code', 'exclusivity_zone_overlap');
});

it('refuses an agreement with blocking findings such as a deferral longer than the term', function (): void {
    $store = fnetStore($this, 'MI-07');
    $formula = fnetFormula($this);
    $draft = fnetAgreement($this, $store['id'], $formula['id'], ['entry_fee' => 5000, 'entry_fee_deferral_months' => 60], false);

    $this->postJson('/api/v1/franchise-network/agreements/'.$draft['id'].'/activate')->assertStatus(422)->assertJsonPath('code', 'entry_fee_deferral_exceeds_term');
});

it('renews an active agreement into a new draft and closes the old one on activation', function (): void {
    $store = fnetStore($this, 'MI-08');
    $formula = fnetFormula($this);
    $agreement = fnetAgreement($this, $store['id'], $formula['id']);

    $renewal = $this->postJson('/api/v1/franchise-network/agreements/'.$agreement['id'].'/renew', ['end_date' => now()->addYears(6)->toDateString()])->assertCreated()->json('data');

    expect($renewal['status'])->toBe('draft')->and($renewal['id'])->not->toBe($agreement['id']);
});

it('terminates an active agreement and refuses to terminate a draft', function (): void {
    $store = fnetStore($this, 'MI-09');
    $formula = fnetFormula($this);
    $active = fnetAgreement($this, $store['id'], $formula['id']);
    $draft = fnetAgreement($this, fnetStore($this, 'MI-10')['id'], $formula['id'], [], false);

    $this->postJson('/api/v1/franchise-network/agreements/'.$active['id'].'/terminate')->assertOk();
    $this->postJson('/api/v1/franchise-network/agreements/'.$draft['id'].'/terminate')->assertStatus(409)->assertJsonPath('code', 'agreement_not_active');
});

it('lists the alerts of an agreement that is about to expire', function (): void {
    $store = fnetStore($this, 'MI-11');
    $formula = fnetFormula($this);
    $start = now()->subYears(3)->addDays(60)->startOfDay();
    fnetAgreement($this, $store['id'], $formula['id'], [
        'start_date' => $start->toDateString(),
        'signed_on' => $start->toDateString(),
        'disclosure_delivered_on' => $start->copy()->subDays(60)->toDateString(),
        'end_date' => now()->addDays(60)->toDateString(),
    ]);

    $this->getJson('/api/v1/franchise-network/agreements/alerts')->assertOk()->assertJsonFragment(['code' => 'renewal_due']);
});

it('forbids the write endpoints to an accountant and to an area manager', function (): void {
    fnetStore($this, 'MI-12');

    fnetActAs($this, 'accountant');
    $this->postJson('/api/v1/franchise-network/stores', ['code' => 'X-1', 'name' => 'X', 'store_type' => 'franchisee', 'country' => 'IT', 'size_cluster' => 'small'])->assertForbidden();

    fnetActAs($this, 'area_manager');
    $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->assertForbidden();
});
