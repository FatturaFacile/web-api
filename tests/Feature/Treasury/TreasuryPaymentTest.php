<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentExecution;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Payment Tenant',
        'legal_name' => 'Treasury Payment SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->paymentService = app(PaymentFactoryServiceInterface::class);

    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Intesa Sanpaolo S.p.A.',
        'branch_name' => 'Milano Centrale',
        'account_number' => 'ACC-TEST-PAY-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT60X0542811101000000888999',
        'bic_swift' => 'BCITITMM',
        'currency' => 'EUR',
        'opening_balance' => 500000.00,
        'current_balance' => 500000.00,
        'available_balance' => 600000.00,
        'overdraft_limit' => 100000.00,
        'is_active' => true,
    ]);
});

test('can create, submit for approval and execute payment batch with ISO 20022 pain.001 generation', function () {
    Event::fake([PaymentExecutedEvent::class]);

    $userId = (string) Str::uuid7();

    // 1. Create Payment Batch
    $batchData = [
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'PAY-BATCH-2026-001',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
    ];

    $lines = [
        [
            'supplier_id' => (string) Str::uuid7(),
            'invoice_id' => (string) Str::uuid7(),
            'end_to_end_id' => 'E2E-2026-INV-1001',
            'beneficiary_name' => 'Fornitore Alfa S.r.l.',
            'beneficiary_iban' => 'IT99T030690160000000123456',
            'beneficiary_bic' => 'BCITITMM',
            'amount' => 12500.00,
            'currency' => 'EUR',
            'remittance_information' => 'Pagamento fattura 1001/2026',
        ],
        [
            'supplier_id' => (string) Str::uuid7(),
            'invoice_id' => (string) Str::uuid7(),
            'end_to_end_id' => 'E2E-2026-INV-1002',
            'beneficiary_name' => 'Fornitore Beta S.p.A.',
            'beneficiary_iban' => 'IT88U020080100000000654321',
            'beneficiary_bic' => 'UNCRITM1',
            'amount' => 7500.00,
            'currency' => 'EUR',
            'remittance_information' => 'Pagamento fattura 1002/2026',
        ],
    ];

    $batch = $this->paymentService->createBatch($batchData, $lines, $userId);

    expect($batch)->toBeInstanceOf(PaymentBatch::class)
        ->and(strtolower((string) $batch->status))->toBe('draft')
        ->and((float) $batch->total_amount)->toBe(20000.00)
        ->and($batch->item_count)->toBe(2)
        ->and($batch->lines)->toHaveCount(2);

    // 2. Submit for Approval (approves the batch in single/multi-tier approval matrix)
    $batch = $this->paymentService->submitForApproval($batch->id, $userId);
    expect(strtolower((string) $batch->status))->toBe('approved');

    // 3. Generate pain.001.001.09 XML
    $xml = $this->paymentService->generatePain001Xml($batch->id);
    expect($xml)->toContain('pain.001.001.09')
        ->and($xml)->toContain('Fornitore Alfa S.r.l.')
        ->and($xml)->toContain('12500.00')
        ->and($xml)->toContain('IT60X0542811101000000888999');

    // 4. Execute Batch
    $execution = $this->paymentService->executeBatch($batch->id, 'sepa_pain001', $userId);
    expect($execution)->toBeInstanceOf(PaymentExecution::class)
        ->and($execution->execution_status)->toBe('TRANSMITTED')
        ->and($execution->bank_transmission_reference)->not->toBeEmpty();

    $batch->refresh();
    expect(in_array(strtolower((string) $batch->status), ['transmitted', 'executed']))->toBeTrue();

    $this->bankAccount->refresh();
    // 500,000 - 20,000 = 480,000
    expect((float) $this->bankAccount->current_balance)->toBe(480000.00);

    Event::assertDispatched(PaymentExecutedEvent::class);
});

test('cannot execute an unapproved payment batch', function () {
    $batch = PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'PAY-UNAPPROVED',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 1000.00,
        'item_count' => 1,
        'status' => 'draft',
    ]);

    expect(fn () => $this->paymentService->executeBatch($batch->id, 'sepa_pain001', (string) Str::uuid7()))
        ->toThrow(Exception::class, 'must be approved');
});

test('can cancel a draft payment batch', function () {
    $batch = PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'PAY-TO-CANCEL',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 1500.00,
        'item_count' => 1,
        'status' => 'draft',
    ]);

    $cancelled = $this->paymentService->cancelBatch($batch->id, 'Incorrect invoice amounts', (string) Str::uuid7());
    expect(in_array(strtolower((string) $cancelled->status), ['cancelled', 'rejected']))->toBeTrue();
});
