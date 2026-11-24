<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\CashPool;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\ReceivableCollection;

require_once __DIR__.'/TreasurySupport.php';

/*
 * HTTP coverage of the treasury endpoints that existed before the enterprise extension: happy path, validation,
 * read-only access (403 on writes) and tenant isolation (404 on records of another company).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->bank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $this->foreignBank = treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null);
});

function batchPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'bank_account_id' => $test->bank->id,
        'execution_date' => now()->addDay()->toDateString(),
        'lines' => [['recipient_name' => 'Fornitore Alfa', 'recipient_iban' => 'IT42L1234512345123456789012', 'amount' => 120.50, 'remittance_reference' => 'Fattura 1']],
    ], $overrides);
}

// ---------------------------------------------------------------------------------------------------------------
// Bank accounts
// ---------------------------------------------------------------------------------------------------------------

test('bank accounts are created, read, updated and deleted with a checked IBAN and a ledger account of the company', function (): void {
    $id = $this->postJson('/api/v1/treasury/bank-accounts', ['bank_name' => 'Banca Sella', 'iban' => 'it42 l123 4512 3451 2345 6789 012', 'currency' => 'eur', 'gl_account_id' => $this->accounts['1003']->id, 'opening_balance' => '250.00'])
        ->assertCreated()->assertJsonPath('iban', 'IT42L1234512345123456789012')->assertJsonPath('currency', 'EUR')->json('id');

    $this->getJson("/api/v1/treasury/bank-accounts/{$id}")->assertOk()->assertJsonPath('id', $id);
    $this->getJson('/api/v1/treasury/bank-accounts?currency=eur')->assertOk()->assertJsonCount(2, 'data');
    $this->putJson("/api/v1/treasury/bank-accounts/{$id}", ['bank_name' => 'Banca Sella SpA', 'overdraft_limit' => '5000'])->assertOk()->assertJsonPath('bank_name', 'Banca Sella SpA');
    $this->putJson("/api/v1/treasury/bank-accounts/{$id}", ['iban' => 'IT60X0542811101000000123456', 'currency' => 'CHF'])->assertUnprocessable()->assertJsonValidationErrors(['iban', 'currency']);

    $this->postJson('/api/v1/treasury/bank-accounts', ['bank_name' => 'Bad', 'iban' => 'IT00X0000000000000000000000', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors(['iban']);
    $this->postJson('/api/v1/treasury/bank-accounts', ['bank_name' => 'Twice', 'iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors(['iban']);
    $this->postJson('/api/v1/treasury/bank-accounts', ['bank_name' => 'Foreign ledger', 'iban' => 'IT84S0542811101000000654321', 'currency' => 'EUR', 'gl_account_id' => treasuryAccount($this->otherTenant, '1001', 'Theirs')->id])->assertUnprocessable()->assertJsonValidationErrors(['gl_account_id']);
    $this->postJson('/api/v1/treasury/bank-accounts', [])->assertUnprocessable()->assertJsonValidationErrors(['bank_name', 'iban', 'currency']);

    $this->getJson("/api/v1/treasury/bank-accounts/{$this->foreignBank->id}")->assertNotFound();
    $this->putJson("/api/v1/treasury/bank-accounts/{$this->foreignBank->id}", ['bank_name' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/v1/treasury/bank-accounts/{$this->foreignBank->id}")->assertNotFound();

    $this->deleteJson("/api/v1/treasury/bank-accounts/{$id}")->assertOk();
    $this->getJson("/api/v1/treasury/bank-accounts/{$id}")->assertNotFound();
    expect(BankAccount::withoutGlobalScopes()->find($this->foreignBank->id)->bank_name)->toBe($this->foreignBank->bank_name);
});

test('cash positions and the liquidity buffer cover only the accounts of the company', function (): void {
    $intraday = $this->getJson('/api/v1/treasury/positions/intraday')->assertOk()->json();
    $buffer = $this->getJson('/api/v1/treasury/positions/buffer')->assertOk()->json();

    expect(json_encode($intraday))->toContain($this->bank->iban)->not->toContain($this->foreignBank->iban)
        ->and((float) ($buffer['total_cash'] ?? $buffer['data']['total_cash']))->toBe(10000.0);
});

// ---------------------------------------------------------------------------------------------------------------
// Payment batches
// ---------------------------------------------------------------------------------------------------------------

test('a payment batch goes through approval, export, download and cancellation over HTTP', function (): void {
    $batchId = $this->postJson('/api/v1/treasury/payment-batches', batchPayload($this))->assertCreated()->json('id');
    $this->getJson('/api/v1/treasury/payment-batches')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/treasury/payment-batches/{$batchId}")->assertOk()->assertJsonPath('status', 'draft');

    $this->get("/api/v1/treasury/payment-batches/{$batchId}/download-xml")->assertOk()->assertHeader('Content-Type', 'application/xml');
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/submit-approval")->assertOk()->assertJsonPath('status', 'pending_approval');

    // The creator cannot approve; another finance user can.
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/approve")->assertUnprocessable();
    treasuryActor($this, 'admin');
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/approve")->assertOk()->assertJsonPath('status', 'approved');

    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/execute")->assertOk()->assertJsonPath('status', 'exported');
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/export")->assertUnprocessable();
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/pain002", ['xml' => '<not-a-report/>'])->assertUnprocessable();
    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/pain002", [])->assertUnprocessable()->assertJsonValidationErrors(['xml']);
    expect((string) $this->get("/api/v1/treasury/payment-batches/{$batchId}/download-xml")->getContent())->toBe((string) PaymentBatch::query()->find($batchId)->pain001_xml);

    $this->postJson("/api/v1/treasury/payment-batches/{$batchId}/cancel", ['reason' => 'Wrong supplier'])->assertOk()->assertJsonPath('status', 'rejected');
});

test('payment batches are validated and isolated', function (): void {
    $this->postJson('/api/v1/treasury/payment-batches', ['lines' => [['recipient_iban' => 'NOT-AN-IBAN', 'amount' => 0]]])->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id', 'lines.0.recipient_name', 'lines.0.recipient_iban', 'lines.0.amount']);

    $foreign = treasuryInTenant($this->otherTenant, fn () => PaymentBatch::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'bank_account_id' => $this->foreignBank->id, 'batch_reference' => 'THEIRS', 'format' => 'sepa_pain_001_it', 'payment_method' => 'sepa_credit_transfer', 'execution_date' => now()->toDateString(), 'total_amount' => 10, 'currency' => 'EUR', 'item_count' => 0, 'status' => 'draft', 'created_by' => (string) Str::uuid7()]));

    $this->getJson("/api/v1/treasury/payment-batches/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/treasury/payment-batches/{$foreign->id}/cancel")->assertNotFound();
    $this->get("/api/v1/treasury/payment-batches/{$foreign->id}/download-xml")->assertNotFound();
    expect(PaymentBatch::withoutGlobalScopes()->find($foreign->id)->status)->toBe('draft');
});

// ---------------------------------------------------------------------------------------------------------------
// Statements and reconciliations of the treasury module
// ---------------------------------------------------------------------------------------------------------------

test('a treasury statement is imported, auto-reconciled, listed and approved', function (): void {
    $statement = $this->postJson('/api/v1/treasury/statements/import', ['bank_account_id' => $this->bank->id, 'statement_date' => now()->toDateString(), 'opening_balance' => 10000, 'closing_balance' => 10100, 'lines' => [['booking_date' => now()->toDateString(), 'amount' => 100.00, 'bank_reference' => 'TRN-1']]])
        ->assertCreated()->json();
    $statementId = $statement['id'] ?? $statement['data']['id'];

    $reconciliation = $this->postJson('/api/v1/treasury/reconciliations/auto-reconcile', ['bank_statement_id' => $statementId])->assertOk()->json();
    $reconciliationId = $reconciliation['id'] ?? $reconciliation['data']['id'];

    $this->getJson('/api/v1/treasury/reconciliations')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/treasury/reconciliations/{$reconciliationId}/approve")->assertOk();
    expect(BankReconciliation::query()->find($reconciliationId)->status)->toBe('approved');

    $this->postJson('/api/v1/treasury/reconciliations/lines/'.Str::uuid7().'/match', [])->assertUnprocessable()->assertJsonValidationErrors(['bank_reconciliation_id', 'matched_entity_type', 'matched_entity_id']);
    $this->postJson('/api/v1/treasury/statements/import', ['bank_account_id' => $this->foreignBank->id, 'lines' => [['amount' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id']);
    $this->postJson('/api/v1/treasury/statements/import', ['bank_account_id' => $this->bank->id, 'lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson('/api/v1/treasury/reconciliations/auto-reconcile', ['bank_statement_id' => (string) Str::uuid7()])->assertUnprocessable()->assertJsonValidationErrors(['bank_statement_id']);

    $foreign = treasuryInTenant($this->otherTenant, fn () => BankReconciliation::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'bank_account_id' => $this->foreignBank->id, 'reconciliation_number' => 'THEIRS', 'period_start_date' => now()->toDateString(), 'period_end_date' => now()->toDateString(), 'bank_statement_balance' => 0, 'gl_book_balance' => 0, 'status' => 'in_progress']));
    $this->postJson("/api/v1/treasury/reconciliations/{$foreign->id}/approve")->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------
// Cash forecasts, collections, liquidity and FX exposure
// ---------------------------------------------------------------------------------------------------------------

test('cash forecasts are generated, listed, read and evaluated', function (): void {
    $forecast = $this->postJson('/api/v1/treasury/cash-forecasts/generate', ['horizon_days' => 30])->assertCreated()->json();
    $forecastId = $forecast['id'] ?? $forecast['data']['id'];

    $this->getJson('/api/v1/treasury/cash-forecasts')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/treasury/cash-forecasts/{$forecastId}")->assertOk();
    $this->getJson("/api/v1/treasury/cash-forecasts/{$forecastId}/evaluate-deficits")->assertOk();
    $this->postJson('/api/v1/treasury/cash-forecasts/generate', ['horizon_days' => 45])->assertUnprocessable()->assertJsonValidationErrors(['horizon_days']);

    $foreign = CashForecast::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail()->replicate(['id']);
    $foreign->forceFill(['id' => (string) Str::uuid7(), 'tenant_id' => $this->otherTenant->id, 'forecast_number' => 'THEIRS'])->saveQuietly();
    $this->getJson("/api/v1/treasury/cash-forecasts/{$foreign->id}")->assertNotFound();
});

test('collections are listed, aged, evaluated for dunning and recorded', function (): void {
    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente', 'email' => 'c@example.com', 'type' => 'customer', 'country' => 'IT']);
    $collection = ReceivableCollection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'expected_amount' => 300, 'collected_amount' => 0, 'due_date' => now()->subDays(40)->toDateString(), 'status' => 'pending']);
    $foreign = treasuryInTenant($this->otherTenant, fn () => ReceivableCollection::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'customer_id' => $customer->id, 'expected_amount' => 300, 'collected_amount' => 0, 'due_date' => now()->toDateString(), 'status' => 'pending']));

    $this->getJson('/api/v1/treasury/collections')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/treasury/collections/aging')->assertOk();
    $this->getJson('/api/v1/treasury/collections/dunning-readiness')->assertOk()->assertJsonPath('evaluated_count', 1);
    $this->postJson("/api/v1/treasury/collections/{$collection->id}/record", ['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    $this->postJson("/api/v1/treasury/collections/{$collection->id}/record", ['amount' => 100, 'collection_date' => now()->toDateString()])->assertOk();
    $this->postJson("/api/v1/treasury/collections/{$foreign->id}/record", ['amount' => 100])->assertNotFound();

    expect((float) $collection->refresh()->collected_amount)->toBe(100.0)->and((float) $foreign->refresh()->collected_amount)->toBe(0.0);
});

test('working capital, cash pool sweeps and FX exposure answer for the company only', function (): void {
    $this->getJson('/api/v1/treasury/liquidity/working-capital')->assertOk();
    $this->getJson('/api/v1/treasury/fx/exposure')->assertOk();

    $pool = CashPool::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'pool_name' => 'ZBA', 'pool_structure' => 'ZERO_BALANCE', 'header_bank_account_id' => $this->bank->id, 'currency' => 'EUR', 'sweeping_frequency' => 'DAILY', 'target_balance_floor' => 0, 'target_balance_ceiling' => 100000, 'is_active' => true]);
    $foreignPool = treasuryInTenant($this->otherTenant, fn () => CashPool::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'pool_name' => 'Theirs', 'pool_structure' => 'ZERO_BALANCE', 'header_bank_account_id' => $this->foreignBank->id, 'currency' => 'EUR', 'sweeping_frequency' => 'DAILY', 'target_balance_floor' => 0, 'target_balance_ceiling' => 100000, 'is_active' => true]));

    $this->postJson("/api/v1/treasury/liquidity/pools/{$pool->id}/sweep")->assertOk()->assertJsonPath('transfers_count', 0);
    $this->postJson("/api/v1/treasury/liquidity/pools/{$foreignPool->id}/sweep")->assertNotFound();
});

test('read-only roles read every treasury list and cannot write anywhere', function (): void {
    treasuryActor($this, 'accountant_readonly');

    foreach (['bank-accounts', 'positions/intraday', 'positions/buffer', 'payment-batches', 'reconciliations', 'cash-forecasts', 'collections', 'collections/aging', 'collections/dunning-readiness', 'liquidity/working-capital', 'fx/exposure', 'riba/batches', 'riba/portfolio'] as $path) {
        $this->getJson("/api/v1/treasury/{$path}")->assertOk();
    }

    $this->postJson('/api/v1/treasury/bank-accounts', ['bank_name' => 'RO', 'iban' => 'IT84S0542811101000000654321', 'currency' => 'EUR'])->assertForbidden();
    $this->putJson("/api/v1/treasury/bank-accounts/{$this->bank->id}", ['bank_name' => 'RO'])->assertForbidden();
    $this->deleteJson("/api/v1/treasury/bank-accounts/{$this->bank->id}")->assertForbidden();
    $this->postJson('/api/v1/treasury/payment-batches', batchPayload($this))->assertForbidden();
    $this->postJson('/api/v1/treasury/cash-forecasts/generate', [])->assertForbidden();
    $this->postJson('/api/v1/treasury/statements/import', [])->assertForbidden();
    $this->postJson('/api/v1/treasury/liquidity/intercompany-transfer', [])->assertForbidden();

    expect(BankAccount::query()->count())->toBe(1)->and($this->bank->refresh()->bank_name)->not->toBe('RO');
});

test('the Ri.Ba. portfolio lists the batches and receipts of the company by status', function (): void {
    $this->getJson('/api/v1/treasury/riba/batches')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/treasury/riba/portfolio')->assertOk()->assertJsonPath('data.by_status', [])->assertJsonPath('data.open_receipts', []);
    $this->getJson('/api/v1/treasury/riba/portfolio?due_from=2026-02-01&due_to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors(['due_to']);
    $this->getJson('/api/v1/treasury/riba/batches?bank_account_id=nope')->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id']);
});
