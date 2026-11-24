<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
    ivActAs($this->tenant);
});

/**
 * The four quarterly settlements of 2026: 1,000 of output VAT and 900 of input VAT each, 100 to pay, posted on the account.
 *
 * @param  array<string, mixed>  $overrides
 */
function yearSettlements(object $test, array $overrides = [], string $ledger = '100.00'): void
{
    foreach ([['2026-01-01', '2026-03-31'], ['2026-04-01', '2026-06-30'], ['2026-07-01', '2026-09-30'], ['2026-10-01', '2026-12-31']] as [$from, $to]) {
        ivSettlement($test->tenant, $from, $to, $overrides + ['output_vat_amount' => '1000.0000', 'input_vat_amount' => '900.0000', 'payable_amount' => '100.00'], $ledger);
    }
}

it('adds up the periods and reconciles them with the Erario c/IVA account', function (): void {
    yearSettlements($this);

    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()
        ->assertJsonPath('data.total_output_vat', '4000.00')
        ->assertJsonPath('data.total_input_vat', '3600.00')
        ->assertJsonPath('data.annual_debit', '400.00')
        ->assertJsonPath('data.annual_credit', '0.00')
        ->assertJsonPath('data.ledger_balance', '400.00')
        ->assertJsonPath('data.difference', '0.00')
        ->assertJsonPath('data.is_reconciled', true)
        ->assertJsonPath('data.diagnostics', []);
});

it('subtracts the payments and the credit of the previous year from the annual debit', function (): void {
    yearSettlements($this, ['is_paid' => true, 'status' => 'paid']);
    DB::table('vat_settlements')->where('tenant_id', $this->tenant->id)->where('period_from', '2026-01-01')->update(['prior_credit_used' => '150.0000']);

    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()
        ->assertJsonPath('data.prior_year_credit', '150.00')
        ->assertJsonPath('data.paid_total', '400.00')
        ->assertJsonPath('data.annual_debit', '0.00')
        ->assertJsonPath('data.annual_credit', '150.00');
});

it('reports a ledger that differs from the settlements and refuses to finalize the year', function (): void {
    yearSettlements($this, [], '60.00');

    $response = $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()
        ->assertJsonPath('data.is_reconciled', false)
        ->assertJsonPath('data.difference', '160.00');

    expect(array_column($response->json('data.diagnostics'), 'code'))->toBe(['ledger_difference']);

    $this->postJson('/api/v1/italian-vat/annual-vat/2026/finalize')
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'annual_not_reconciled');
});

it('reports the months without a final settlement and the settlements not posted', function (): void {
    ivSettlement($this->tenant, '2026-01-01', '2026-03-31', [], '300.00');
    ivSettlement($this->tenant, '2026-04-01', '2026-06-30', ['status' => 'calculated'], null);

    $codes = array_column($this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()->json('data.diagnostics'), 'code');

    expect($codes)->toContain('settlements_not_posted', 'periods_missing');
});

it('adjusts the deducted VAT with the definitive pro-rata of the year', function (): void {
    yearSettlements($this);
    $this->postJson('/api/v1/italian-vat/register-entries', [
        'section_id' => ivSectionId('ACQ'), 'entry_date' => '2026-02-10', 'taxable_amount' => '4545.45', 'vat_rate' => 22, 'apply_pro_rata' => true,
    ])->assertCreated()->assertJsonPath('data.deductible_vat', '1000.00');

    $notDefinitive = $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk();

    expect(array_column($notDefinitive->json('data.diagnostics'), 'code'))->toBe(['pro_rata_not_definitive'])
        ->and($notDefinitive->json('data.pro_rata_adjustment'))->toBe('0.00');

    $this->putJson('/api/v1/italian-vat/pro-rata/2026', ['deductible_operations' => 800, 'total_operations' => 1000, 'definitive' => true])->assertOk();

    // 80 % of 1,000.00 of VAT is deductible: 200.00 deducted too much, so the annual debit grows by 200.00.
    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()
        ->assertJsonPath('data.pro_rata_percentage', 80)
        ->assertJsonPath('data.pro_rata_adjustment', '-200.00')
        ->assertJsonPath('data.annual_debit', '600.00')
        ->assertJsonPath('data.is_reconciled', true);
});

it('finalizes a reconciled year and freezes it', function (): void {
    yearSettlements($this);

    $this->postJson('/api/v1/italian-vat/annual-vat/2026/finalize')->assertOk()->assertJsonPath('data.status', 'finalized');
    $this->getJson('/api/v1/italian-vat/annual-vat/2026')->assertOk()->assertJsonPath('data.status', 'finalized');
    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'annual_already_finalized');
});

it('answers 404 for a year that was never calculated and isolates the tenants', function (): void {
    $this->getJson('/api/v1/italian-vat/annual-vat/2026')->assertNotFound()->assertJsonPath('invalid_params.0.code', 'annual_not_found');

    yearSettlements($this);
    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk();

    ivActAs(ivTenant('Other'));

    $this->getJson('/api/v1/italian-vat/annual-vat/2026')->assertNotFound();
    $this->postJson('/api/v1/italian-vat/annual-vat/2026/calculate')->assertOk()
        ->assertJsonPath('data.total_output_vat', '0.00')
        ->assertJsonPath('data.is_reconciled', false);
});
