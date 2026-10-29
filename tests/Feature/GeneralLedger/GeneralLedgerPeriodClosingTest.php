<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Period Closing Test Tenant',
        'legal_name' => 'GL Period Closing SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->postingService = app(DoubleEntryPostingServiceInterface::class);
    $this->closingService = app(PeriodClosingServiceInterface::class);

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-CLOSE',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    for ($m = 1; $m <= 12; $m++) {
        FiscalPeriod::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId,
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => $m,
            'period_name' => "Month {$m}",
            'start_date' => sprintf('2026-%02d-01', $m),
            'end_date' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $m))),
            'status' => 'open',
        ]);
    }

    $this->period1 = FiscalPeriod::where('fiscal_year_id', $this->fiscalYear->id)->where('period_number', 1)->first();

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
});

test('period close fails if unposted draft journals exist', function () {
    JournalEntry::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period1->id,
        'entry_number' => 'JE-DRAFT-001',
        'posting_date' => '2026-01-10',
        'document_date' => '2026-01-10',
        'description' => 'Draft unposted entry',
        'status' => 'draft',
        'total_amount' => 500.0,
    ]);

    $this->closingService->closePeriod((string) $this->period1->id, 'admin_user');
})->throws(InvalidArgumentException::class);

test('period close succeeds when no drafts exist and locks subledgers', function () {
    $this->closingService->closePeriod((string) $this->period1->id, 'admin_user');
    $this->period1->refresh();

    expect($this->period1->status)->toBe('closed')
        ->and($this->period1->isLockedFor('GL'))->toBeTrue();
});

test('posting to a closed fiscal period is rejected', function () {
    $this->closingService->closePeriod((string) $this->period1->id, 'admin_user');

    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period1->id,
        'entry_number' => 'JE-CLOSED-FAIL',
        'description' => 'Posting to closed period',
        'lines' => [
            ['account_id' => $this->cash->id, 'debit_amount' => 100.0, 'credit_amount' => 0.0],
            ['account_id' => $this->revenue->id, 'debit_amount' => 0.0, 'credit_amount' => 100.0],
        ],
    ]);
})->throws(InvalidArgumentException::class);

test('year-end close zeroes out PnL accounts to retained earnings', function () {
    // Post revenue in period 1
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period1->id,
        'entry_number' => 'JE-REV-YEAR',
        'description' => 'Year Revenue',
        'lines' => [
            ['account_id' => $this->cash->id, 'debit_amount' => 10000.0, 'credit_amount' => 0.0],
            ['account_id' => $this->revenue->id, 'debit_amount' => 0.0, 'credit_amount' => 10000.0],
        ],
    ]);

    // Close all 12 periods
    for ($m = 1; $m <= 12; $m++) {
        $p = FiscalPeriod::where('fiscal_year_id', $this->fiscalYear->id)->where('period_number', $m)->first();
        $this->closingService->closePeriod((string) $p->id, 'admin_user');
    }

    $closingRun = $this->closingService->closeFiscalYear((string) $this->fiscalYear->id, 'admin_user');

    $this->fiscalYear->refresh();
    expect($this->fiscalYear->status)->toBe('closed')
        ->and($this->fiscalYear->is_locked)->toBeTrue()
        ->and((float) $closingRun->net_income_amount)->toBe(10000.00);
});
