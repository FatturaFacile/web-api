<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/FranchiseNetworkSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fnetSetUp($this);
});

it('lists the global presets next to the formulas of the company and keeps the presets read-only', function (): void {
    $presets = $this->getJson('/api/v1/franchise-network/formulas')->assertOk()->json('data');
    expect($presets)->not->toBeEmpty();

    $this->putJson('/api/v1/franchise-network/formulas/'.$presets[0]['id'], ['minimum_guarantee' => 5])->assertStatus(403)->assertJsonPath('code', 'formula_preset_read_only');
});

it('clones a preset into an editable formula of the company', function (): void {
    $preset = $this->getJson('/api/v1/franchise-network/formulas')->json('data.0');

    $clone = $this->postJson('/api/v1/franchise-network/formulas/'.$preset['id'].'/clone', ['code' => 'CLONE-1'])->assertCreated()->json('data');

    expect($clone['code'])->toBe('CLONE-1')->and($clone['id'])->not->toBe($preset['id']);
});

it('simulates a formula without saving anything', function (): void {
    $formula = fnetFormula($this, ['minimum_guarantee' => 1000]);

    $this->postJson('/api/v1/franchise-network/formulas/'.$formula['id'].'/simulate', ['net_sales' => 10000, 'period' => $this->period, 'opened_on' => now()->subYears(2)->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.royalty_charged', '1000.00')
        ->assertJsonPath('data.marketing_fund', '200.00');
});

it('calculates the royalty and the marketing fund of a month from the sell-out', function (): void {
    $formula = fnetFormula($this);
    $store = fnetStore($this, 'RB-01');
    fnetAgreement($this, $store['id'], $formula['id']);
    fnetSellOut($this, $store['id'], $this->period);

    $run = $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->assertCreated()->json('data');
    $statement = collect($run['statements'])->firstWhere('store_code', 'RB-01');

    expect($statement['net_sales'])->toBe('9500.00')
        ->and($statement['royalty_charged'])->toBe('570.00')
        ->and($statement['marketing_fund'])->toBe('190.00')
        ->and($statement['status'])->toBe('calculated');
});

it('recalculates the same period instead of duplicating the run', function (): void {
    $formula = fnetFormula($this);
    $store = fnetStore($this, 'RB-02');
    fnetAgreement($this, $store['id'], $formula['id']);
    fnetSellOut($this, $store['id'], $this->period);

    $first = $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->json('data.id');
    $second = $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->json('data.id');

    expect($second)->toBe($first);
});

it('skips a store without a sell-out and a directly operated store, saying why', function (): void {
    $formula = fnetFormula($this);
    $silent = fnetStore($this, 'RB-03');
    fnetAgreement($this, $silent['id'], $formula['id']);
    fnetStore($this, 'RB-04', ['store_type' => 'directly_operated']);

    $run = $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->assertCreated()->json('data');
    $reasons = collect($run['statements'])->pluck('skip_reason', 'store_code');

    expect($reasons['RB-03'])->toBe('no_sell_out')->and($reasons['RB-04'])->toBe('directly_operated');
});

it('lifts the royalty to the minimum guarantee and reports the adjustment', function (): void {
    $formula = fnetFormula($this, ['minimum_guarantee' => 1500]);
    $store = fnetStore($this, 'RB-05');
    fnetAgreement($this, $store['id'], $formula['id']);
    fnetSellOut($this, $store['id'], $this->period);

    $statement = collect($this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->json('data.statements'))->firstWhere('store_code', 'RB-05');

    expect($statement['royalty_charged'])->toBe('1500.00')->and($statement['minimum_adjustment'])->toBe('930.00');
});

it('refuses a negative sell-out', function (): void {
    $store = fnetStore($this, 'RB-06');

    $this->putJson('/api/v1/franchise-network/stores/'.$store['id'].'/sell-out/'.$this->period, ['gross_sales' => 100, 'returns' => 300, 'discounts' => 0])->assertStatus(422);
});

it('refuses to invoice a store that has no customer record', function (): void {
    $formula = fnetFormula($this);
    $store = fnetStore($this, 'RB-07');
    fnetAgreement($this, $store['id'], $formula['id']);
    fnetSellOut($this, $store['id'], $this->period);
    $run = $this->postJson('/api/v1/franchise-network/royalty-runs', ['period' => $this->period])->json('data');

    $this->postJson('/api/v1/franchise-network/royalty-runs/'.$run['id'].'/invoice')->assertStatus(422)->assertJsonPath('code', 'store_without_customer');
});

it('records loyalty movements and refuses a redemption above the balance', function (): void {
    $store = fnetStore($this, 'RB-08');
    $this->postJson('/api/v1/franchise-network/loyalty/transactions', ['store_id' => $store['id'], 'customer_ref' => 'C-1', 'type' => 'earn', 'points' => 100])->assertCreated();

    $this->postJson('/api/v1/franchise-network/loyalty/transactions', ['store_id' => $store['id'], 'earned_store_id' => $store['id'], 'customer_ref' => 'C-1', 'type' => 'redeem', 'points' => 500])
        ->assertStatus(409)->assertJsonPath('code', 'loyalty_balance_insufficient');
});
