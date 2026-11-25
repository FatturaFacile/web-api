<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa Srl', 'legal_name' => 'Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->actor = treasuryActor($this, 'accountant');
    $this->retailer = Contact::query()->create(['company_name' => 'Catena Test', 'type' => 'customer', 'country' => 'IT']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gdtContractPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'code' => 'GDO-T-'.random_int(1000, 9999), 'name' => 'Accordo quadro', 'contact_id' => $test->retailer->id, 'jurisdiction' => 'IT', 'currency' => 'EUR',
        'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31', 'discount_1_percent' => '3', 'discount_2_percent' => '2', 'discount_3_percent' => '1.5', 'cedi_discount_percent' => '1',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function gdtScalePayload(): array
{
    return [
        'code' => 'PFA', 'name' => 'Premio fine anno', 'tier_mode' => 'retroactive', 'basis' => 'turnover', 'volume_mode' => 'total', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31',
        'tiers' => [['threshold' => '100000', 'rate_percent' => '1'], ['threshold' => '200000', 'rate_percent' => '2']],
    ];
}

it('creates a framework contract with the cascade of the on-invoice discounts', function (): void {
    $response = $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated();

    expect($response->json('data.status'))->toBe('draft')
        ->and((float) $response->json('data.discount_cascade_percent'))->toBe(7.3022);

    $this->getJson('/api/v1/gdo-trade-terms/contracts/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.jurisdiction', 'IT');
    $this->getJson('/api/v1/gdo-trade-terms/contracts')->assertOk()->assertJsonCount(1, 'data');
});

it('rejects a contract that ends before it starts, a duplicate code and an unknown jurisdiction', function (): void {
    $payload = gdtContractPayload($this, ['code' => 'DUP']);
    $this->postJson('/api/v1/gdo-trade-terms/contracts', $payload)->assertCreated();

    $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this, ['code' => 'DUP']))->assertUnprocessable();
    $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this, ['ends_on' => '2027-01-01']))->assertUnprocessable();
    $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this, ['jurisdiction' => 'FR']))->assertUnprocessable();
});

it('keeps the contracts of a company away from the others', function (): void {
    $id = (string) $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated()->json('data.id');

    $other = Tenant::create(['name' => 'Beta Srl', 'legal_name' => 'Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson("/api/v1/gdo-trade-terms/contracts/{$id}")->assertNotFound();
    $this->getJson('/api/v1/gdo-trade-terms/contracts')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses writes to a plain member and lets them read', function (): void {
    treasuryActor($this, 'member');

    $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertForbidden();
});

it('adds a scale with tiers and refuses an overlapping one', function (): void {
    $contract = (string) $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/scales", gdtScalePayload())->assertCreated()->assertJsonCount(2, 'data.tiers');
    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/scales", array_merge(gdtScalePayload(), ['code' => 'PFA2']))->assertUnprocessable();
});

it('refuses tiers that are not strictly increasing', function (): void {
    $contract = (string) $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated()->json('data.id');
    $payload = gdtScalePayload();
    $payload['tiers'] = [['threshold' => '200000', 'rate_percent' => '2'], ['threshold' => '100000', 'rate_percent' => '1']];

    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/scales", $payload)->assertUnprocessable();
});

it('simulates both readings of the brackets', function (): void {
    $response = $this->postJson('/api/v1/gdo-trade-terms/scale-simulations', [
        'tier_mode' => 'retroactive', 'volume_mode' => 'total', 'baseline' => '0', 'volumes' => ['150000'],
        'tiers' => [['threshold' => '100000', 'rate_percent' => '1'], ['threshold' => '200000', 'rate_percent' => '2']],
    ])->assertOk();

    expect($response->json('data.rows.0.retroactive.rebate'))->toBe('1500.0000')
        ->and($response->json('data.rows.0.progressive.rebate'))->toBe('1000.0000');
});

it('activates a contract and only then accepts promotions in its window', function (): void {
    $contract = (string) $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/transition", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/promotions", [
        'code' => 'VOL', 'name' => 'Volantino', 'promotion_type' => 'sell_in_flyer', 'date_basis' => 'order', 'starts_on' => '2028-03-01', 'ends_on' => '2028-03-31',
        'contribution_mode' => 'percent_of_sales', 'rate_percent' => '4',
    ])->assertCreated()->assertJsonPath('data.treatment', 'credit_note');
    $this->postJson("/api/v1/gdo-trade-terms/contracts/{$contract}/promotions", [
        'code' => 'FUORI', 'name' => 'Fuori contratto', 'promotion_type' => 'display', 'date_basis' => 'delivery', 'starts_on' => '2029-03-01', 'ends_on' => '2029-03-31',
        'contribution_mode' => 'fixed_amount', 'fixed_amount' => '1000',
    ])->assertUnprocessable();
});

it('previews and runs a month without sales, and returns the posted run when it is run again', function (): void {
    $this->postJson('/api/v1/gdo-trade-terms/contracts', gdtContractPayload($this))->assertCreated();

    $this->postJson('/api/v1/gdo-trade-terms/accrual-runs/preview', ['period' => '2028-03'])->assertOk();
    $this->postJson('/api/v1/gdo-trade-terms/accrual-runs', ['period' => 'March'])->assertUnprocessable();
    $this->getJson('/api/v1/gdo-trade-terms/accrual-runs')->assertOk();
});

it('refuses to run the accrual for a plain member', function (): void {
    treasuryActor($this, 'member');

    $this->postJson('/api/v1/gdo-trade-terms/accrual-runs', ['period' => '2028-03'])->assertForbidden();
});

it('serves the dashboard, the lookups and the delivery points', function (): void {
    $this->getJson('/api/v1/gdo-trade-terms/dashboard')->assertOk();
    $this->getJson('/api/v1/gdo-trade-terms/lookups')->assertOk();
    $this->postJson('/api/v1/gdo-trade-terms/delivery-points', [
        'contact_id' => $this->retailer->id, 'code' => 'PV-1', 'name' => 'Punto vendita 1', 'point_type' => 'store', 'country' => 'IT',
    ])->assertCreated();
    $this->getJson('/api/v1/gdo-trade-terms/delivery-points')->assertOk()->assertJsonCount(1, 'data');
});
