<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Concurrency Tenant',
        'legal_name' => 'Treasury Concurrency SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->paymentService = app(PaymentFactoryServiceInterface::class);
    $this->reconService = app(BankReconciliationServiceInterface::class);

    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Deutsche Bank SpA',
        'branch_name' => 'Milan Financial Branch',
        'account_number' => 'ACC-CONC-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT88D0310401600000000001234',
        'currency' => 'EUR',
        'opening_balance' => 50000.00,
        'current_balance' => 50000.00,
        'available_balance' => 50000.00,
        'is_active' => true,
    ]);
});

test('prevents double execution of the same payment batch under concurrent requests', function () {
    $batch = PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'BATCH-CONCURRENT-001',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 10000.00,
        'item_count' => 1,
        'status' => 'APPROVED',
    ]);

    PaymentLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'payment_batch_id' => $batch->id,
        'line_number' => 1,
        'recipient_type' => 'supplier',
        'recipient_id' => (string) Str::uuid7(),
        'recipient_name' => 'Concurrent Supplier S.r.l.',
        'recipient_iban' => 'IT77D0310401600000000004321',
        'amount' => 10000.00,
        'currency' => 'EUR',
        'instruction_id' => 'INSTR-CONC-001',
        'end_to_end_id' => 'E2E-CONC-001',
        'remittance_reference' => 'Concurrent payment',
        'execution_date' => now()->toDateString(),
        'status' => 'pending',
    ]);

    $userId = (string) Str::uuid7();

    // First execution succeeds
    $execution1 = $this->paymentService->executeBatch($batch->id, 'sepa_pain001', $userId);
    expect($execution1->execution_status)->toBe('TRANSMITTED');

    $batch->refresh();
    expect(in_array(strtolower((string) $batch->status), ['transmitted', 'executed']))->toBeTrue();

    // Attempting second concurrent execution throws Exception
    expect(fn () => $this->paymentService->executeBatch($batch->id, 'sepa_pain001', $userId))
        ->toThrow(Exception::class);
});

test('statement line cannot be matched more than once in reconciliation', function () {
    $userId = (string) Str::uuid7();

    $statement = BankStatement::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'statement_number' => 'STMT-CONC-001',
        'statement_date' => now()->toDateString(),
        'source_format' => 'CAMT_053',
        'account_iban' => $this->bankAccount->iban,
        'currency' => 'EUR',
        'total_entries' => 1,
        'content_hash' => hash('sha256', 'hash-conc'),
        'opening_balance' => 50000.00,
        'closing_balance' => 55000.00,
        'status' => 'imported',
        'format' => 'CAMT_053',
        'reconciliation_status' => 'unreconciled',
    ]);

    $line = BankStatementLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_statement_id' => $statement->id,
        'bank_account_id' => $this->bankAccount->id,
        'line_number' => 1,
        'booking_date' => now()->toDateString(),
        'value_date' => now()->toDateString(),
        'amount' => 5000.00,
        'currency' => 'EUR',
        'direction' => 'credit',
        'iso_bank_code' => 'INBD',
        'reconciled' => false,
        'reconciled_status' => 'unmatched',
    ]);

    $recon = BankReconciliation::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'reconciliation_number' => 'REC-CONC-001',
        'period_start_date' => now()->startOfMonth()->toDateString(),
        'period_end_date' => now()->endOfMonth()->toDateString(),
        'bank_statement_balance' => 55000.00,
        'gl_book_balance' => 50000.00,
        'difference_amount' => 5000.00,
        'status' => 'in_progress',
    ]);

    // First manual match succeeds
    $this->reconService->matchManually($line->id, [
        'bank_reconciliation_id' => $recon->id,
        'matched_entity_type' => 'receivable_collection',
        'matched_entity_id' => (string) Str::uuid7(),
        'allocated_amount' => 5000.00,
    ], $userId);

    $line->refresh();
    expect($line->reconciled)->toBeTrue()
        ->and(strtolower((string) $line->reconciled_status))->toBe('matched');

    // Second manual match fails
    expect(fn () => $this->reconService->matchManually($line->id, [
        'bank_reconciliation_id' => $recon->id,
        'matched_entity_type' => 'receivable_collection',
        'matched_entity_id' => (string) Str::uuid7(),
        'allocated_amount' => 5000.00,
    ], $userId))->toThrow(Exception::class);
});
