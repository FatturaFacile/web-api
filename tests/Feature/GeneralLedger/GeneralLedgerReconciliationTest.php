<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\AccountingReconciliationServiceInterface;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\ReconciliationRun;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Reconciliation Test Tenant',
        'legal_name' => 'GL Reconciliation SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->reconciliationService = app(AccountingReconciliationServiceInterface::class);
    $this->postingService = app(DoubleEntryPostingServiceInterface::class);

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-REC',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $this->period = FiscalPeriod::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'fiscal_year_id' => $this->fiscalYear->id,
        'period_number' => 1,
        'period_name' => 'January 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    $this->arAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1100',
        'name' => 'Accounts Receivable',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'reconciliation_type' => 'customer',
        'is_reconciliation_account' => true,
        'is_active' => true,
    ]);

    $this->revenueAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '4000',
        'name' => 'Sales Revenue',
        'account_class' => 'revenue',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);
});

test('reconcileSubledger successfully reconciles AR GL control balance with customer ledger', function () {
    $customerId = (string) Str::uuid7();

    // Post AR entry with customer subledger
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-REC-AR',
        'description' => 'Invoice for reconciliation test',
        'lines' => [
            ['account_id' => $this->arAccount->id, 'debit_amount' => 5000.0, 'credit_amount' => 0.0, 'partner_type' => 'customer', 'partner_id' => $customerId],
            ['account_id' => $this->revenueAccount->id, 'debit_amount' => 0.0, 'credit_amount' => 5000.0],
        ],
    ]);

    $run = $this->reconciliationService->reconcileSubledger($this->tenantId, 'subledger_ar', $this->period->id);

    expect($run)->toBeInstanceOf(ReconciliationRun::class)
        ->and($run->status)->toBe('balanced')
        ->and((float) $run->variance_amount)->toBe(0.0)
        ->and((float) $run->gl_balance)->toBe(5000.0)
        ->and((float) $run->subledger_balance)->toBe(5000.0);
});

test('reconcileGrIr detects cleared or balanced goods receipt clearing accounts', function () {
    $grIrAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '2010',
        'name' => 'GR/IR Clearing',
        'account_class' => 'liability',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
        'reconciliation_type' => 'gr_ir',
        'is_reconciliation_account' => true,
        'is_active' => true,
    ]);

    $run = $this->reconciliationService->reconcileGrIr($this->tenantId, $this->period->id);

    expect($run)->toBeInstanceOf(ReconciliationRun::class)
        ->and($run->reconciliation_type)->toBe('gr_ir')
        ->and($run->status)->toBe('balanced');
});
