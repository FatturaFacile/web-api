<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Business Test Tenant',
        'legal_name' => 'GL Business SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->companyId = (string) Str::uuid7();
    $this->postingService = app(DoubleEntryPostingServiceInterface::class);
    $this->statementService = app(FinancialStatementServiceInterface::class);

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-STMT',
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

    // Create standard accounts
    $this->cash = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1000',
        'name' => 'Cash',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    $this->ar = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1100',
        'name' => 'AR',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_reconciliation_account' => true,
        'reconciliation_type' => 'customer',
        'is_active' => true,
    ]);

    $this->ap = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '2000',
        'name' => 'AP',
        'account_class' => 'liability',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
        'is_reconciliation_account' => true,
        'reconciliation_type' => 'supplier',
        'is_active' => true,
    ]);

    $this->equity = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '3000',
        'name' => 'Retained Earnings',
        'account_class' => 'equity',
        'account_type' => 'equity',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);

    $this->revenue = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '4000',
        'name' => 'Sales Revenue',
        'account_class' => 'revenue',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);

    $this->cogs = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '5000',
        'name' => 'COGS',
        'account_class' => 'cogs',
        'account_type' => 'cogs',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    $this->expense = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '6000',
        'name' => 'Salaries',
        'account_class' => 'expense',
        'account_type' => 'expense',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    // Post business activity
    // 1. Initial capital investment: Dr Cash 50,000 | Cr Equity 50,000
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-CAPITAL',
        'description' => 'Initial Capital',
        'lines' => [
            ['account_id' => $this->cash->id, 'debit_amount' => 50000.0, 'credit_amount' => 0.0],
            ['account_id' => $this->equity->id, 'debit_amount' => 0.0, 'credit_amount' => 50000.0],
        ],
    ]);

    // 2. Sale: Dr AR 15,000 | Cr Revenue 15,000
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-SALE',
        'description' => 'Sales Invoice',
        'lines' => [
            ['account_id' => $this->ar->id, 'debit_amount' => 15000.0, 'credit_amount' => 0.0, 'partner_type' => 'customer', 'partner_id' => (string) Str::uuid7()],
            ['account_id' => $this->revenue->id, 'debit_amount' => 0.0, 'credit_amount' => 15000.0],
        ],
    ]);

    // 3. COGS: Dr COGS 6,000 | Cr AP 6,000
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-COGS',
        'description' => 'COGS Vendor Invoice',
        'lines' => [
            ['account_id' => $this->cogs->id, 'debit_amount' => 6000.0, 'credit_amount' => 0.0],
            ['account_id' => $this->ap->id, 'debit_amount' => 0.0, 'credit_amount' => 6000.0, 'partner_type' => 'supplier', 'partner_id' => (string) Str::uuid7()],
        ],
    ]);

    // 4. Operating Expense: Dr Salaries 3,000 | Cr Cash 3,000
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-SALARY',
        'description' => 'Salaries Payment',
        'lines' => [
            ['account_id' => $this->expense->id, 'debit_amount' => 3000.0, 'credit_amount' => 0.0],
            ['account_id' => $this->cash->id, 'debit_amount' => 0.0, 'credit_amount' => 3000.0],
        ],
    ]);
});

test('trial balance accurately reflects debit and credit parity', function () {
    $tb = $this->statementService->generateTrialBalance($this->tenantId, $this->period->id);

    expect($tb['is_balanced'])->toBeTrue()
        ->and($tb['total_debit'])->toBe(74000.0)
        ->and($tb['total_credit'])->toBe(74000.0)
        ->and(count($tb['accounts']))->toBeGreaterThanOrEqual(6);
});

test('income statement computes gross profit and net income correctly', function () {
    $pnl = $this->statementService->generateIncomeStatement($this->tenantId, $this->period->id);

    expect((float) $pnl['total_revenue'])->toBe(15000.00)
        ->and((float) $pnl['total_cogs'])->toBe(6000.00)
        ->and((float) $pnl['gross_profit'])->toBe(9000.00)
        ->and((float) $pnl['total_operating_expenses'])->toBe(3000.00)
        ->and((float) $pnl['net_income'])->toBe(6000.00); // 15,000 - 6,000 - 3,000 = 6,000
});

test('balance sheet maintains fundamental accounting identity Assets = Liabilities + Equity', function () {
    $bs = $this->statementService->generateBalanceSheet($this->tenantId, $this->period->id);

    // Assets: Cash (50,000 - 3,000 = 47,000) + AR (15,000) = 62,000
    // Liabilities: AP (6,000)
    // Equity: Retained Earnings base (50,000) + Current Net Income (6,000) = 56,000
    // Liabilities + Equity = 6,000 + 56,000 = 62,000
    expect((float) $bs['total_assets'])->toBe(62000.00)
        ->and((float) $bs['total_liabilities'])->toBe(6000.00)
        ->and((float) $bs['total_equity'])->toBe(56000.00)
        ->and($bs['is_balanced'])->toBeTrue();
});
