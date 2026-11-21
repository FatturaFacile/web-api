<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;

require_once __DIR__.'/AiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = ocrCompany('CH');
    $this->tenant = $this->company['tenant'];
    $this->accountCode = (string) DB::table('chart_of_accounts')->where('id', $this->company['account'])->value('code');
    $this->vatCode = (string) DB::table('vat_codes')->where('id', $this->company['vat'])->value('code');
});

function aiCodingBody(array $overrides = []): array
{
    return $overrides + ['description' => 'SBB GA Travelcard business trip Zurich', 'amount' => 189.5, 'counterparty' => 'SBB AG', 'source_type' => 'bank_transaction', 'source_reference' => 'tx-'.Str::random(6)];
}

test('the coding of a bank transaction is proposed from the lists of the company only', function (): void {
    ocrActAs($this->tenant);
    $gateway = aiFakeGateway(fn () => ['account_code' => $this->accountCode, 'vat_code' => $this->vatCode, 'cost_center_code' => 'CC1', 'field_confidences' => ['account' => 0.92, 'vat' => 0.97, 'cost_center' => 0.7], 'overall_confidence' => 0.9, 'reasoning' => 'Train tickets are travel expenses.']);

    $this->postJson('/api/v1/ai/accounting/coding', aiCodingBody())->assertCreated()
        ->assertJsonPath('data.kind', 'accounting_coding')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.proposed_payload.gl_account_id', $this->company['account'])
        ->assertJsonPath('data.proposed_payload.vat_code_id', $this->company['vat'])
        ->assertJsonPath('data.proposed_payload.vat_rate', 8.1)
        ->assertJsonPath('data.proposed_payload.cost_center_id', $this->company['cost_center'])
        ->assertJsonPath('data.field_confidences.account', 0.92)
        ->assertJsonPath('data.confidence', 0.7);

    // Personal data of a bank transaction is classified before it reaches the router, and the lists were in the prompt.
    expect($gateway->requests[0]->classification)->toBe(DataClassification::ConfidentialPii)
        ->and($gateway->requests[0]->prompt)->toContain($this->accountCode, $this->vatCode, 'statutory rates: 8.1, 3.8, 2.6, 0');
});

test('a code the model invented is no answer: its confidence is zero and nothing is linked', function (): void {
    ocrActAs($this->tenant);
    aiFakeGateway(fn () => ['account_code' => '9999-INVENTED', 'vat_code' => $this->vatCode, 'field_confidences' => ['account' => 0.99, 'vat' => 0.95], 'overall_confidence' => 0.97, 'reasoning' => 'x']);

    $this->postJson('/api/v1/ai/accounting/coding', aiCodingBody())->assertCreated()
        ->assertJsonPath('data.proposed_payload.gl_account_id', null)
        ->assertJsonPath('data.field_confidences.account', 0)
        ->assertJsonPath('data.confidence', 0);
});

test('approving a coding records the decision of the accountant', function (): void {
    $user = ocrActAs($this->tenant);
    aiFakeGateway(fn () => ['account_code' => $this->accountCode, 'vat_code' => $this->vatCode, 'field_confidences' => ['account' => 0.9, 'vat' => 0.9], 'overall_confidence' => 0.9, 'reasoning' => 'ok']);
    $id = $this->postJson('/api/v1/ai/accounting/coding', aiCodingBody())->json('data.id');

    $this->postJson("/api/v1/ai/suggestions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', $user->id);
});

test('a company without a chart of accounts gets a readable refusal', function (): void {
    $tenant = Tenant::create(['name' => 'Empty AG', 'legal_name' => 'Empty AG', 'vat_number' => '99999999999', 'fiscal_code' => '99999999999', 'country' => 'CH', 'default_currency' => 'CHF']);
    ocrActAs($tenant);
    aiFakeGateway(fn () => []);

    $this->postJson('/api/v1/ai/accounting/coding', aiCodingBody())->assertUnprocessable();
});

/**
 * One bank account, three open invoices of the customer and the bank transactions of a month.
 *
 * @return array{invoices: array<string, string>, transactions: array<string, string>}
 */
function aiReconciliationData(mixed $test): array
{
    $account = (string) Str::uuid();
    DB::table('bank_accounts')->insert(['id' => $account, 'tenant_id' => $test->tenant->id, 'bank_name' => 'UBS', 'iban' => 'CH9300762011623852957', 'created_at' => now(), 'updated_at' => now()]);
    $invoices = [];

    foreach (['A' => ['INV-100', 640.0], 'B' => ['INV-101', 300.0], 'C' => ['INV-102', 200.0]] as $key => [$number, $gross]) {
        $invoices[$key] = (string) Str::uuid();
        DB::table('invoices')->insert(['id' => $invoices[$key], 'tenant_id' => $test->tenant->id, 'contact_id' => $test->company['vendor'], 'invoice_number' => $number, 'date' => now()->subDays(20)->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'total_gross' => $gross, 'total_paid' => 0, 'payment_status' => 'unpaid', 'jurisdiction' => 'CH', 'created_at' => now(), 'updated_at' => now()]);
    }

    $transactions = [];

    foreach (['exact' => [640.0, 'Zahlung INV-100 Muster AG'], 'grouped' => [500.0, 'Sammelzahlung Muster AG'], 'partial' => [100.0, 'Teilzahlung INV-101'], 'unknown' => [77.77, 'Coffee shop refund']] as $key => [$amount, $text]) {
        $transactions[$key] = (string) Str::uuid();
        DB::table('bank_transactions')->insert(['id' => $transactions[$key], 'tenant_id' => $test->tenant->id, 'bank_account_id' => $account, 'booking_date' => now()->subDays(2)->toDateString(), 'value_date' => now()->subDays(2)->toDateString(), 'amount' => $amount, 'description' => $text, 'status' => 'unmatched', 'created_at' => now(), 'updated_at' => now()]);
    }

    return ['invoices' => $invoices, 'transactions' => $transactions];
}

test('bank transactions are matched to open invoices: exact, grouped and partial payments', function (): void {
    ocrActAs($this->tenant);
    $data = aiReconciliationData($this);

    $response = $this->postJson('/api/v1/ai/accounting/reconciliation')->assertOk();
    $byTransaction = collect($response->json('data'))->keyBy('proposed_payload.bank_transaction_id');

    expect($byTransaction)->toHaveCount(3)->and($byTransaction->has($data['transactions']['unknown']))->toBeFalse();

    $exact = $byTransaction[$data['transactions']['exact']];
    $grouped = $byTransaction[$data['transactions']['grouped']];
    $partial = $byTransaction[$data['transactions']['partial']];

    expect($exact['proposed_payload']['match_type'])->toBe('exact')
        ->and($exact['proposed_payload']['invoice_id'])->toBe($data['invoices']['A'])
        ->and($exact['confidence'])->toBeGreaterThanOrEqual(0.9)
        ->and($grouped['proposed_payload']['match_type'])->toBe('grouped')
        ->and($grouped['proposed_payload']['invoice_ids'])->toEqualCanonicalizing([$data['invoices']['B'], $data['invoices']['C']])
        ->and($partial['proposed_payload']['match_type'])->toBe('partial')
        ->and($partial['proposed_payload']['invoice_id'])->toBe($data['invoices']['B'])
        ->and($partial['reasoning'])->toContain('partial');

    // A second run finds the same open suggestions and creates no duplicates.
    $this->postJson('/api/v1/ai/accounting/reconciliation')->assertOk();
    expect(DB::table('ai_suggestions')->where('kind', 'reconciliation_match')->count())->toBe(3);
});

test('approving a match links the bank transaction to the invoice and nothing else', function (): void {
    ocrActAs($this->tenant);
    $data = aiReconciliationData($this);
    $id = collect($this->postJson('/api/v1/ai/accounting/reconciliation')->json('data'))->firstWhere('proposed_payload.match_type', 'exact')['id'];

    $this->postJson("/api/v1/ai/suggestions/{$id}/approve")->assertOk()->assertJsonPath('data.resulting_entity_type', 'bank_transactions');

    $transaction = DB::table('bank_transactions')->where('id', $data['transactions']['exact'])->first();

    expect($transaction->status)->toBe('matched')
        ->and($transaction->matched_invoice_id)->toBe($data['invoices']['A'])
        ->and(DB::table('invoices')->where('id', $data['invoices']['A'])->value('payment_status'))->toBe('unpaid');

    // The same transaction cannot be matched twice.
    $again = aiSuggestion($this->tenant, SuggestionKind::ReconciliationMatch, ['bank_transaction_id' => $data['transactions']['exact'], 'invoice_id' => $data['invoices']['B']]);

    $this->postJson("/api/v1/ai/suggestions/{$again->id}/approve")->assertUnprocessable();
});

test('only the unmatched transactions and open invoices of the company are considered', function (): void {
    $other = ocrCompany('CH');
    ocrActAs($other['tenant']);
    aiReconciliationData($this);

    $this->postJson('/api/v1/ai/accounting/reconciliation')->assertOk()->assertJsonCount(0, 'data');
});
