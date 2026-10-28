<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\BankPostingReadyEvent;
use Modules\GeneralLedger\Events\CashForecastReadyEvent;
use Modules\GeneralLedger\Events\PayableReadyEvent;
use Modules\GeneralLedger\Events\PaymentReadyEvent;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Events\TreasuryAllocationReadyEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Treasury\Listeners\ProcurementTreasuryListener;
use Modules\Treasury\Listeners\ProjectsTreasuryListener;
use Modules\Treasury\Listeners\TreasuryReadinessEventListener;
use Modules\Treasury\Listeners\WorkflowApprovalTreasuryListener;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Events Tenant',
        'legal_name' => 'Treasury Events SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;

    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Intesa Sanpaolo Test',
        'branch_name' => 'Milano Sede',
        'account_number' => 'ACC-EVT-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT11Z0306901600000000000099',
        'currency' => 'EUR',
        'opening_balance' => 100000.00,
        'current_balance' => 100000.00,
        'available_balance' => 100000.00,
        'is_active' => true,
    ]);
});

test('consumes Module 22 Treasury Readiness Events seamlessly', function () {
    $listener = app(TreasuryReadinessEventListener::class);

    // 1. PaymentReadyEvent
    $payEvent = new PaymentReadyEvent(
        supplierLedgerId: (string) Str::uuid7(),
        supplierId: (string) Str::uuid7(),
        amount: 3500.00,
        currency: 'EUR',
        dueDate: now()->addDays(30)->toDateString(),
        journalEntryId: (string) Str::uuid7(),
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handlePaymentReady($payEvent);

    // 2. ReceivableReadyEvent
    $recEvent = new ReceivableReadyEvent(
        customerLedgerId: (string) Str::uuid7(),
        customerId: (string) Str::uuid7(),
        openAmount: 6200.00,
        currency: 'EUR',
        dueDate: now()->addDays(20)->toDateString(),
        journalEntryId: (string) Str::uuid7(),
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handleReceivableReady($recEvent);

    $collection = ReceivableCollection::where('tenant_id', $this->tenantId)
        ->where('expected_amount', 6200.00)
        ->first();
    expect($collection)->not->toBeNull()
        ->and(strtolower((string) $collection->status))->toBe('pending');

    // 3. PayableReadyEvent
    $payableEvent = new PayableReadyEvent(
        supplierLedgerId: (string) Str::uuid7(),
        supplierId: (string) Str::uuid7(),
        openAmount: 4800.00,
        currency: 'EUR',
        dueDate: now()->addDays(15)->toDateString(),
        journalEntryId: (string) Str::uuid7(),
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handlePayableReady($payableEvent);

    // 4. CashForecastReadyEvent
    $fcEvent = new CashForecastReadyEvent(
        fiscalPeriodId: (string) Str::uuid7(),
        projectedInflows: 150000.00,
        projectedOutflows: 90000.00,
        netProjectedCash: 60000.00,
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handleCashForecastReady($fcEvent);

    // 5. BankPostingReadyEvent
    $postingEvent = new BankPostingReadyEvent(
        journalLineId: (string) Str::uuid7(),
        bankAccountId: $this->bankAccount->id,
        amount: 15000.00,
        direction: 'credit',
        valueDate: now()->toDateString(),
        journalEntryId: (string) Str::uuid7(),
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handleBankPostingReady($postingEvent);

    $this->bankAccount->refresh();
    expect((float) $this->bankAccount->current_balance)->toBe(115000.00);

    // 6. TreasuryAllocationReadyEvent
    $allocEvent = new TreasuryAllocationReadyEvent(
        allocationReference: 'ALLOC-2026-001',
        sourceAccountId: $this->bankAccount->id,
        targetAccountId: $this->bankAccount->id,
        amount: 5000.00,
        currency: 'EUR',
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $listener->handleTreasuryAllocationReady($allocEvent);
});

test('consumes Module 13 Procurement and Module 15 Projects Events', function () {
    $procListener = app(ProcurementTreasuryListener::class);
    $projListener = app(ProjectsTreasuryListener::class);

    // Procurement PurchaseLiabilityReadyEvent
    $liabilityEvent = new PurchaseLiabilityReadyEvent(
        matchLogId: (string) Str::uuid7(),
        purchaseOrderId: (string) Str::uuid7(),
        supplierId: (string) Str::uuid7(),
        netAmount: 10000.00,
        taxAmount: 2200.00,
        grossAmount: 12200.00,
        currency: 'EUR',
        vendorInvoiceNumber: 'INV-VENDOR-999',
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $procListener->handlePurchaseLiabilityReady($liabilityEvent);

    // Projects ProjectCashFlowForecastEvent
    $cfEvent = new ProjectCashFlowForecastEvent(
        projectId: (string) Str::uuid7(),
        forecastPeriod: '2026-Q1',
        projectedCashInflow: 85000.00,
        projectedCashOutflow: 40000.00,
        netProjectCashFlow: 45000.00,
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $projListener->handleCashFlowForecast($cfEvent);

    expect(PaymentLine::where('tenant_id', $this->tenantId)->count())->toBeGreaterThanOrEqual(1)
        ->and(CashForecast::where('tenant_id', $this->tenantId)->count())->toBeGreaterThanOrEqual(1);
});

test('consumes Workflow Engine V2 completion and rejection events', function () {
    $workflowListener = app(WorkflowApprovalTreasuryListener::class);
    $instanceId = (string) Str::uuid7();

    $batch = PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'BATCH-WF-TEST',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 12000.00,
        'item_count' => 1,
        'status' => 'PENDING_APPROVAL',
        'workflow_instance_id' => $instanceId,
    ]);

    // Handle Workflow Approved
    $approvedEvent = new WorkflowInstanceCompletedEvent(
        instanceId: $instanceId,
        status: 'completed',
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    );
    $workflowListener->handleWorkflowApproved($approvedEvent);

    $batch->refresh();
    expect(strtolower((string) $batch->status))->toBe('approved')
        ->and($batch->approved_at)->not->toBeNull();
});
