<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/CorporateConventionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ccvSetUp($this);
});

/**
 * @return array{convention: array<string, mixed>, enrollment: array<string, mixed>}
 */
function ccvWithPurchase(object $test, string $price = '100'): array
{
    $convention = ccvActiveConvention($test);
    $enrollment = ccvEnroll($test, $convention);
    $test->postJson('/api/v1/sales/corporate-conventions/pos/checkout', [
        'enrollment_id' => $enrollment['id'],
        'source_document_type' => 'POS_RECEIPT',
        'source_document_id' => (string) Str::uuid(),
        'transaction_date' => now()->toDateString(),
        'lines' => [['quantity' => 1, 'unit_price' => $price]],
    ])->assertCreated();

    return ['convention' => $convention, 'enrollment' => $enrollment];
}

it('lists the employer quotas waiting for consolidation per convention', function (): void {
    ccvWithPurchase($this);

    $this->getJson('/api/v1/sales/split-billings/pending')->assertOk();
    $this->getJson('/api/v1/sales/split-billings?status=PENDING_CONSOLIDATION')->assertOk()->assertJsonCount(1, 'data');
});

it('previews the consolidated invoice with an anonymized or nominal schedule', function (): void {
    $purchase = ccvWithPurchase($this);
    $body = ['convention_id' => $purchase['convention']['id'], 'period' => now()->format('Y-m')];

    $nominal = $this->postJson('/api/v1/sales/split-billings/preview', $body + ['anonymize' => false])->assertOk();
    $anonymous = $this->postJson('/api/v1/sales/split-billings/preview', $body + ['anonymize' => true])->assertOk();

    expect($nominal->json('data.rows_count'))->toBe(1)
        ->and($nominal->json('data.total_employer_quota'))->toBe('45.00')
        ->and(json_encode($nominal->json('data.schedule')))->toContain('Mario Rossi')
        ->and(json_encode($anonymous->json('data.schedule')))->not->toContain('Mario Rossi');
});

it('consolidates the quotas into one invoice, marks them invoiced and never bills them twice', function (): void {
    $purchase = ccvWithPurchase($this);
    $body = ['convention_id' => $purchase['convention']['id'], 'period' => now()->format('Y-m')];

    $response = $this->postJson('/api/v1/sales/split-billings/generate-invoice', $body)->assertCreated();

    expect($response->json('data.rows_count'))->toBe(1)
        ->and($response->json('data.total_employer_quota'))->toBe('45.00')
        ->and($response->json('data.invoice_id'))->not->toBeEmpty()
        ->and(DB::table('sales_convention_split_billings')->value('billing_status'))->toBe('INVOICED');

    $this->get($response->json('data.attachment_url'))->assertOk();
    $this->postJson('/api/v1/sales/split-billings/generate-invoice', $body)->assertStatus(422);
});

it('stores a purchase without employer quota as already settled', function (): void {
    $convention = ccvActiveConvention($this, ['employer_subsidy_enabled' => false, 'employer_subsidy_type' => null, 'employer_subsidy_value' => null]);
    $enrollment = ccvEnroll($this, $convention);

    $this->postJson('/api/v1/sales/corporate-conventions/pos/checkout', [
        'enrollment_id' => $enrollment['id'],
        'source_document_type' => 'POS_RECEIPT',
        'source_document_id' => (string) Str::uuid(),
        'lines' => [['quantity' => 1, 'unit_price' => 100]],
    ])->assertCreated();

    expect(DB::table('sales_convention_split_billings')->value('billing_status'))->toBe('PAID');
});

it('reserves the consolidated invoice to finance roles', function (): void {
    $purchase = ccvWithPurchase($this);
    treasuryActor($this, 'sales');

    $this->postJson('/api/v1/sales/split-billings/generate-invoice', ['convention_id' => $purchase['convention']['id'], 'period' => now()->format('Y-m')])->assertForbidden();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ccvVoucher(object $test, array $overrides = []): array
{
    return array_merge([
        'source_document_type' => 'POS_RECEIPT',
        'source_document_id' => (string) Str::uuid(),
        'jurisdiction' => 'IT_ART51_TUIR',
        'provider' => 'EDENRED',
        'welfare_category' => 'HEALTHCARE',
        'voucher_code' => 'EDN-'.Str::upper(Str::random(10)),
        'currency' => 'EUR',
        'gross_voucher_amount' => 100,
        'merchant_fee_percentage' => 4,
    ], $overrides);
}

it('redeems an Italian Art. 51 voucher computing the merchant fee and masking the code', function (): void {
    $payload = ccvVoucher($this, ['voucher_code' => 'EDN1234567890']);

    $response = $this->postJson('/api/v1/sales/welfare-vouchers/redeem', $payload)->assertCreated();

    expect($response->json('data.merchant_fee_amount'))->toBe('4.00')
        ->and($response->json('data.net_expected_reimbursement'))->toBe('96.00')
        ->and($response->json('data.reimbursement_status'))->toBe('PENDING_SETTLEMENT')
        ->and(json_encode($response->json('data')))->not->toContain('EDN1234567890')
        ->and(DB::table('pos_welfare_voucher_redemptions')->value('voucher_barcode_hash'))->toHaveLength(64);

    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this, ['voucher_code' => 'EDN1234567890']))->assertStatus(409);
});

it('enforces the welfare rules of each jurisdiction', function (): void {
    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this, ['currency' => 'CHF']))->assertStatus(422);
    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this, ['provider' => 'REKA_CARD', 'welfare_category' => 'CULTURE_LEISURE']))->assertStatus(422);
    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this, ['jurisdiction' => 'CH_REKA_LUNCHCHECK', 'provider' => 'LUNCH_CHECK', 'welfare_category' => 'SPORTS_FITNESS', 'currency' => 'CHF']))->assertStatus(422);
    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this, ['jurisdiction' => 'CH_REKA_LUNCHCHECK', 'provider' => 'LUNCH_CHECK', 'welfare_category' => 'MEAL_VOUCHER', 'currency' => 'CHF']))->assertCreated();
});

it('submits a batch and reconciles it against the provider statement flagging discrepancies', function (): void {
    $ok = $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this))->assertCreated()->json('data');
    $ko = $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this))->assertCreated()->json('data');

    $this->postJson('/api/v1/sales/welfare-vouchers/submit-batch', ['provider' => 'EDENRED', 'batch_reference' => 'B-2026-01', 'redemption_ids' => [$ok['id'], $ko['id']]])
        ->assertOk()->assertJsonPath('data.submitted', 2);

    $result = $this->postJson('/api/v1/sales/welfare-vouchers/reconcile', [
        'provider' => 'EDENRED',
        'batch_reference' => 'B-2026-01',
        'self_invoice_number' => 'SI-77',
        'lines' => [['redemption_id' => $ok['id'], 'settled_amount' => 96], ['redemption_id' => $ko['id'], 'settled_amount' => 90]],
    ])->assertOk();

    expect($result->json('data.reconciled'))->toBe(1)
        ->and($result->json('data.rejected'))->toBe(1)
        ->and($result->json('data.discrepancies.0.difference'))->toBe('-6.00')
        ->and(DB::table('pos_welfare_voucher_redemptions')->where('id', $ok['id'])->value('reimbursement_status'))->toBe('RECONCILED')
        ->and(DB::table('pos_welfare_voucher_redemptions')->where('id', $ko['id'])->value('reimbursement_status'))->toBe('REJECTED');

    $this->postJson('/api/v1/sales/welfare-vouchers/reconcile', ['provider' => 'EDENRED', 'batch_reference' => 'B-2026-01', 'lines' => [['redemption_id' => $ok['id'], 'settled_amount' => 96]]])->assertStatus(409);
});

it('refuses a statement that repeats a voucher and a batch of another provider', function (): void {
    $voucher = $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this))->assertCreated()->json('data');

    $this->postJson('/api/v1/sales/welfare-vouchers/submit-batch', ['provider' => 'PELLEGRINI', 'batch_reference' => 'B-X', 'redemption_ids' => [$voucher['id']]])->assertStatus(422);
    $this->postJson('/api/v1/sales/welfare-vouchers/reconcile', [
        'provider' => 'EDENRED',
        'batch_reference' => 'B-Y',
        'lines' => [['redemption_id' => $voucher['id'], 'settled_amount' => 96], ['redemption_id' => $voucher['id'], 'settled_amount' => 96]],
    ])->assertStatus(422);
});

it('summarises the vouchers per provider and status and keeps them private to the company', function (): void {
    $this->postJson('/api/v1/sales/welfare-vouchers/redeem', ccvVoucher($this))->assertCreated();

    $this->getJson('/api/v1/sales/welfare-vouchers/summary')->assertOk()->assertJsonPath('data.0.provider', 'EDENRED');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/sales/welfare-vouchers')->assertOk()->assertJsonCount(0, 'data');
});
