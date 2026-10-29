<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Events\BankReconciliationCompletedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Recon Tenant',
        'legal_name' => 'Treasury Recon SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->reconService = app(BankReconciliationServiceInterface::class);
    $this->paymentService = app(PaymentFactoryServiceInterface::class);

    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'UniCredit S.p.A.',
        'branch_name' => 'Milano Gae Aulenti',
        'account_number' => 'ACC-RECON-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT99Z020080100000000009988',
        'bic_swift' => 'UNCRITM1',
        'currency' => 'EUR',
        'opening_balance' => 200000.00,
        'current_balance' => 200000.00,
        'available_balance' => 200000.00,
        'is_active' => true,
    ]);
});

test('can import electronic statement and execute dual-pass auto reconciliation', function () {
    $userId = (string) Str::uuid7();

    // 1. Create and execute a payment batch so we have a payment execution and lines to reconcile against
    $batchData = [
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'PAY-FOR-RECON',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
    ];
    $lines = [
        [
            'supplier_id' => (string) Str::uuid7(),
            'invoice_id' => (string) Str::uuid7(),
            'end_to_end_id' => 'E2E-MATCH-PASS1-001',
            'beneficiary_name' => 'Acme Supplies S.r.l.',
            'beneficiary_iban' => 'IT55A020080100000000111222',
            'amount' => 4500.00,
            'currency' => 'EUR',
            'remittance_information' => 'Fattura 4500 Fornitore Acme',
        ],
    ];

    $batch = $this->paymentService->createBatch($batchData, $lines, $userId);
    $this->paymentService->submitForApproval($batch->id, $userId);
    $execution = $this->paymentService->executeBatch($batch->id, 'sepa_pain001', $userId);

    // 2. Import CAMT.053 Statement
    $statementData = [
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'statement_number' => 'STMT-CAMT053-2026-001',
        'statement_date' => now()->toDateString(),
        'opening_balance' => 200000.00,
        'closing_balance' => 195500.00, // 200k - 4.5k
        'format' => 'CAMT_053',
    ];

    $statementLines = [
        [
            'line_number' => 1,
            'booking_date' => now()->toDateString(),
            'value_date' => now()->toDateString(),
            'amount' => -4500.00,
            'currency' => 'EUR',
            'credit_debit_indicator' => 'DBIT',
            'transaction_code' => 'PMNT',
            'counterparty_name' => 'Acme Supplies S.r.l.',
            'counterparty_iban' => 'IT55A020080100000000111222',
            'remittance_information' => 'Fattura 4500 Fornitore Acme',
            'bank_reference' => 'E2E-MATCH-PASS1-001',
            'customer_reference' => 'E2E-MATCH-PASS1-001',
            'end_to_end_id' => 'E2E-MATCH-PASS1-001',
        ],
    ];

    $statement = $this->reconService->importStatement($statementData, $statementLines);

    expect($statement)->toBeInstanceOf(BankStatement::class)
        ->and($statement->lines)->toHaveCount(1);

    // 3. Run Dual-Pass Auto Reconciliation
    $recon = $this->reconService->runAutoReconciliation($statement->id, $userId);

    expect($recon)->toBeInstanceOf(BankReconciliation::class)
        ->and(strtolower((string) $recon->status))->toBe('completed');

    $line = BankStatementLine::where('bank_statement_id', $statement->id)->first();
    expect($line->reconciled)->toBeTrue()
        ->and(strtolower((string) $line->reconciled_status))->toBe('matched');
});

test('can perform manual reconciliation match and approve reconciliation run', function () {
    Event::fake([BankReconciliationCompletedEvent::class]);

    $userId = (string) Str::uuid7();

    $statement = BankStatement::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'statement_number' => 'STMT-MANUAL-001',
        'statement_date' => now()->toDateString(),
        'source_format' => 'CAMT_053',
        'account_iban' => $this->bankAccount->iban,
        'currency' => 'EUR',
        'total_entries' => 1,
        'content_hash' => hash('sha256', 'hash-manual'),
        'opening_balance' => 100000.00,
        'closing_balance' => 102000.00,
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
        'amount' => 2000.00,
        'currency' => 'EUR',
        'direction' => 'credit',
        'iso_bank_code' => 'INBD',
        'counterparty_name' => 'Direct Client Transfer',
        'remittance_information' => 'Invoice Payment 1234',
        'reconciled' => false,
        'reconciled_status' => 'unmatched',
    ]);

    $recon = BankReconciliation::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'reconciliation_number' => 'REC-MANUAL-001',
        'period_start_date' => now()->startOfMonth()->toDateString(),
        'period_end_date' => now()->endOfMonth()->toDateString(),
        'bank_statement_balance' => 102000.00,
        'gl_book_balance' => 100000.00,
        'difference_amount' => 2000.00,
        'status' => 'in_progress',
    ]);

    // Manual match
    $this->reconService->matchManually($line->id, [
        'bank_reconciliation_id' => $recon->id,
        'matched_entity_type' => 'receivable_collection',
        'matched_entity_id' => (string) Str::uuid7(),
        'allocated_amount' => 2000.00,
    ], $userId);

    $line->refresh();
    expect($line->reconciled)->toBeTrue()
        ->and(strtolower((string) $line->reconciled_status))->toBe('matched');

    // Approve reconciliation
    $approved = $this->reconService->approveReconciliation($recon->id, $userId);
    expect(strtolower((string) $approved->status))->toBe('approved')
        ->and($approved->approved_at)->not->toBeNull();

    Event::assertDispatched(BankReconciliationCompletedEvent::class);
});
