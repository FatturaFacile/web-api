<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

function ledgerMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function ledgerActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function ledgerAccount(string $code, string $type, string $normal, array $extra = []): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => test()->tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
        ...$extra,
    ]);
}

function ledgerPost(string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount, ?string $costCenterId = null): JournalEntry
{
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => test()->tenant->id,
        'fiscal_period_id' => $period->id,
        'posting_date' => $date,
        'description' => 'Test entry',
        'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0, 'cost_center_id' => $costCenterId],
            ['account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount, 'cost_center_id' => $costCenterId],
        ],
    ]);
}

function closeYear(FiscalYear $year, User $user): void
{
    $closing = app(PeriodClosingServiceInterface::class);

    foreach ($year->periods as $period) {
        $closing->closePeriod((string) $period->id, (string) $user->id);
    }

    $closing->closeFiscalYear((string) $year->id, (string) $user->id);
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'default_currency' => 'EUR']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->accountant = ledgerMember($this->tenant, 'accountant');

    app(TenantContext::class)->setTenant($this->tenant);
    $this->year2025 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2025);
    $this->year2026 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->bank = ledgerAccount('1000', 'asset', 'debit', ['reconciliation_type' => 'bank']);
    $this->receivable = ledgerAccount('1100', 'asset', 'debit', ['reconciliation_type' => 'customer']);
    $this->stock = ledgerAccount('1200', 'asset', 'debit', ['reporting_class' => 'inventory']);
    $this->machinery = ledgerAccount('1500', 'asset', 'debit', ['reporting_class' => 'non_current_asset']);
    $this->payable = ledgerAccount('2000', 'liability', 'credit', ['reconciliation_type' => 'supplier']);
    $this->loan = ledgerAccount('2500', 'liability', 'credit', ['reporting_class' => 'non_current_liability']);
    $this->capital = ledgerAccount('3000', 'equity', 'credit');
    $this->sales = ledgerAccount('4000', 'revenue', 'credit');
    $this->materials = ledgerAccount('5000', 'cogs', 'debit');
    $this->rent = ledgerAccount('6000', 'expense', 'debit');
    $this->depreciation = ledgerAccount('6500', 'expense', 'debit', ['reporting_class' => 'non_cash_expense']);
    $this->centre = CostCenter::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'ADM', 'name' => 'Administration']);
    DefaultPostingAccount::query()->create(['tenant_id' => $this->tenant->id, 'role' => PostingAccountRole::RetainedEarnings->value, 'account_id' => $this->capital->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('several periods stay open at once and each date posts in its own one', function (): void {
    $january = ledgerPost('2026-01-15', $this->bank, $this->capital, '100.00');
    $march = ledgerPost('2026-03-15', $this->bank, $this->capital, '100.00');

    expect($january->fiscal_period_id)->not->toBe($march->fiscal_period_id)
        ->and(FiscalPeriod::query()->where('fiscal_year_id', $this->year2026->id)->where('status', 'open')->count())->toBe(12);
});

test('closing a year carries the balances forward, once, without the income accounts', function (): void {
    ledgerPost('2025-01-10', $this->bank, $this->capital, '10000.00');
    ledgerPost('2025-02-10', $this->receivable, $this->sales, '4000.00');
    ledgerPost('2025-03-10', $this->rent, $this->payable, '1500.00');

    closeYear($this->year2025, $this->accountant);

    $opening = JournalEntry::query()->where('journal_type', 'opening')->firstOrFail();
    $lines = $opening->lines->keyBy('account_id');

    expect($opening->posting_date->toDateString())->toBe('2026-01-01')
        ->and($opening->entry_number)->toBe('OPEN-FY2026')
        ->and((float) $lines[$this->bank->id]->debit_amount)->toBe(10000.0)
        ->and((float) $lines[$this->receivable->id]->debit_amount)->toBe(4000.0)
        ->and((float) $lines[$this->payable->id]->credit_amount)->toBe(1500.0)
        ->and((float) $lines[$this->capital->id]->credit_amount)->toBe(12500.0)
        ->and($lines->has($this->sales->id))->toBeFalse()
        ->and($lines->has($this->rent->id))->toBeFalse();

    $again = app(PeriodClosingServiceInterface::class)->carryForwardOpeningBalances((string) $this->year2025->id, (string) $this->accountant->id);

    expect($again->id)->toBe($opening->id)
        ->and(JournalEntry::query()->where('journal_type', 'opening')->count())->toBe(1);
});

test('a carry-forward needs a closed year and the next one is opened when missing', function (): void {
    ledgerPost('2026-01-10', $this->bank, $this->capital, '10.00');

    ledgerActAs($this->accountant, $this->tenant);
    $this->postJson("/api/v1/general-ledger/fiscal-years/{$this->year2026->id}/carry-forward")->assertConflict();

    app(TenantContext::class)->setTenant($this->tenant);
    closeYear($this->year2026->fresh(), $this->accountant);

    expect(FiscalYear::query()->where('year_code', 'FY2027')->exists())->toBeTrue()
        ->and(JournalEntry::query()->where('entry_number', 'OPEN-FY2027')->exists())->toBeTrue();
});

test('the comparison reads both years correctly after the first one was closed and carried forward', function (): void {
    ledgerPost('2025-01-10', $this->bank, $this->capital, '10000.00');
    ledgerPost('2025-02-10', $this->receivable, $this->sales, '4000.00');
    closeYear($this->year2025, $this->accountant);
    ledgerPost('2026-02-10', $this->receivable, $this->sales, '6000.00');

    ledgerActAs($this->accountant, $this->tenant);
    $data = $this->getJson("/api/v1/general-ledger/financial-statements/comparative?fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');

    $sales = collect($data['income_statement'])->firstWhere('account_code', '4000');
    $receivable = collect($data['balance_sheet'])->firstWhere('account_code', '1100');

    expect($data['previous_fiscal_year'])->toBe('FY2025')
        ->and((float) $sales['current'])->toBe(6000.0)
        ->and((float) $sales['previous'])->toBe(4000.0)
        ->and((float) $sales['change'])->toBe(2000.0)
        ->and((float) $sales['change_percent'])->toBe(50.0)
        ->and((float) $receivable['current'])->toBe(10000.0)
        ->and((float) $receivable['previous'])->toBe(4000.0)
        ->and((float) $data['result']['current'])->toBe(6000.0)
        ->and((float) $data['result']['previous'])->toBe(4000.0);
});

test('a reversed entry cancels out in the comparison', function (): void {
    $entry = ledgerPost('2026-02-10', $this->receivable, $this->sales, '1000.00');
    app(DoubleEntryPostingServiceInterface::class)->reverseJournal((string) $entry->id, '2026-02-11');

    ledgerActAs($this->accountant, $this->tenant);
    $data = $this->getJson("/api/v1/general-ledger/financial-statements/comparative?fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');

    expect((float) $data['result']['current'])->toBe(0.0);
});

test('a user scheme reclassifies accounts, subtotals rows and reports what it left out', function (): void {
    ledgerPost('2025-02-10', $this->receivable, $this->sales, '1000.00');
    ledgerPost('2026-02-10', $this->receivable, $this->sales, '3000.00');
    ledgerPost('2026-03-10', $this->materials, $this->payable, '1200.00');
    ledgerPost('2026-03-11', $this->rent, $this->payable, '300.00');

    ledgerActAs($this->accountant, $this->tenant);

    $schemeId = $this->postJson('/api/v1/general-ledger/statement-schemes', [
        'name' => 'Contribution margin',
        'statement_type' => 'income_statement',
        'rows' => [
            ['code' => 'H1', 'label' => 'Operations', 'row_type' => 'heading'],
            ['code' => 'REV', 'label' => 'Revenue', 'row_type' => 'accounts', 'account_prefixes' => ['4']],
            ['code' => 'VAR', 'label' => 'Variable costs', 'row_type' => 'accounts', 'sign' => -1, 'account_prefixes' => ['5']],
            ['code' => 'MC', 'label' => 'Contribution margin', 'row_type' => 'subtotal', 'formula' => [['row' => 'REV'], ['row' => 'VAR']]],
        ],
    ])->assertCreated()->json('data.id');

    $data = $this->getJson("/api/v1/general-ledger/financial-statements/reclassified?scheme_id={$schemeId}&fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');
    $rows = collect($data['rows'])->keyBy('code');

    expect($rows['H1']['current'])->toBeNull()
        ->and((float) $rows['REV']['current'])->toBe(3000.0)
        ->and((float) $rows['REV']['previous'])->toBe(1000.0)
        ->and((float) $rows['VAR']['current'])->toBe(-1200.0)
        ->and((float) $rows['MC']['current'])->toBe(1800.0)
        ->and(collect($data['unmapped_accounts'])->pluck('account_code')->all())->toBe(['6000']);
});

test('a subtotal cannot read a row below it, so a scheme cannot be circular', function (): void {
    ledgerActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/general-ledger/statement-schemes', [
        'name' => 'Circular',
        'statement_type' => 'income_statement',
        'rows' => [
            ['code' => 'A', 'label' => 'A', 'row_type' => 'subtotal', 'formula' => [['row' => 'B']]],
            ['code' => 'B', 'label' => 'B', 'row_type' => 'subtotal', 'formula' => [['row' => 'A']]],
        ],
    ])->assertUnprocessable();
});

test('the cash flow statement adds up to the change in cash', function (): void {
    ledgerPost('2026-01-05', $this->bank, $this->capital, '50000.00');
    ledgerPost('2026-01-06', $this->bank, $this->loan, '20000.00');
    ledgerPost('2026-01-07', $this->machinery, $this->bank, '30000.00');
    ledgerPost('2026-02-01', $this->stock, $this->payable, '8000.00');
    ledgerPost('2026-03-01', $this->receivable, $this->sales, '15000.00');
    ledgerPost('2026-03-15', $this->bank, $this->receivable, '9000.00');
    ledgerPost('2026-04-01', $this->rent, $this->bank, '2000.00');
    ledgerPost('2026-12-31', $this->depreciation, $this->machinery, '3000.00');

    ledgerActAs($this->accountant, $this->tenant);
    $data = $this->getJson("/api/v1/general-ledger/financial-statements/cash-flow-statement?fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');

    expect((float) $data['net_income'])->toBe(10000.0)
        ->and((float) $data['non_cash_expenses'])->toBe(3000.0)
        ->and((float) $data['operating_cash_flow'])->toBe(7000.0)
        ->and((float) $data['investing_cash_flow'])->toBe(-30000.0)
        ->and((float) $data['financing_cash_flow'])->toBe(70000.0)
        ->and((float) $data['net_cash_flow'])->toBe(47000.0)
        ->and((float) $data['change_in_cash'])->toBe(47000.0)
        ->and($data['is_reconciled'])->toBeTrue()
        ->and(collect($data['assumed_classes'])->pluck('account_code')->all())->toContain('1000', '1100', '2000', '3000');
});

test('ratios come from the classes of the accounts, and a missing denominator gives null', function (): void {
    ledgerActAs($this->accountant, $this->tenant);
    $empty = $this->getJson("/api/v1/general-ledger/financial-statements/ratios?fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');
    expect($empty['liquidity']['current_ratio'])->toBeNull();

    app(TenantContext::class)->setTenant($this->tenant);
    ledgerPost('2026-01-05', $this->bank, $this->capital, '4000.00');
    ledgerPost('2026-01-06', $this->stock, $this->payable, '2000.00');
    ledgerPost('2026-02-01', $this->receivable, $this->sales, '10000.00');
    ledgerPost('2026-02-02', $this->materials, $this->stock, '6000.00');
    ledgerPost('2026-02-03', $this->rent, $this->payable, '2000.00');

    ledgerActAs($this->accountant, $this->tenant);
    $data = $this->getJson("/api/v1/general-ledger/financial-statements/ratios?fiscal_year_id={$this->year2026->id}")->assertOk()->json('data');

    // Current assets: bank 4000 + stock -4000 + receivable 10000 = 10000. Current liabilities: 4000. Equity: 4000 + 2000.
    expect((float) $data['liquidity']['working_capital'])->toBe(6000.0)
        ->and((float) $data['liquidity']['current_ratio'])->toBe(2.5)
        ->and((float) $data['liquidity']['quick_ratio'])->toBe(3.5)
        ->and((float) $data['liquidity']['cash_ratio'])->toBe(1.0)
        ->and((float) $data['profitability']['gross_margin'])->toBe(0.4)
        ->and((float) $data['profitability']['net_margin'])->toBe(0.2)
        ->and((float) $data['profitability']['return_on_assets'])->toBe(0.2)
        ->and((float) $data['profitability']['return_on_equity'])->toBe(0.3333)
        ->and((float) $data['solvency']['debt_to_equity'])->toBe(0.6666);
});

describe('budgets', function (): void {
    beforeEach(function (): void {
        ledgerActAs($this->accountant, $this->tenant);
        $this->budgetId = $this->postJson('/api/v1/general-ledger/budgets', ['fiscal_year_id' => $this->year2026->id, 'name' => 'Base'])->assertCreated()->json('data.id');
        $lines = [];

        foreach (range(1, 12) as $period) {
            $lines[] = ['account_id' => $this->rent->id, 'cost_center_id' => $this->centre->id, 'period_number' => $period, 'amount' => '100.00'];
            $lines[] = ['account_id' => $this->sales->id, 'period_number' => $period, 'amount' => '1000.00'];
        }

        $this->putJson("/api/v1/general-ledger/budgets/{$this->budgetId}/lines", ['lines' => $lines])->assertOk();
    });

    test('variance compares actual and budget by account and cost centre, money as strings', function (): void {
        app(TenantContext::class)->setTenant($this->tenant);
        ledgerPost('2026-01-10', $this->rent, $this->bank, '130.10', $this->centre->id);
        ledgerPost('2026-02-10', $this->rent, $this->bank, '90.20', $this->centre->id);
        ledgerPost('2026-01-10', $this->receivable, $this->sales, '1100.00');
        ledgerPost('2026-01-11', $this->materials, $this->payable, '77.00');

        ledgerActAs($this->accountant, $this->tenant);
        $data = $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/variance?period_from=1&period_to=2")->assertOk()->json('data');
        $rows = collect($data['rows']);
        $rent = $rows->firstWhere('account_code', '6000');

        expect($rent['budget'])->toBe('200.0000')
            ->and($rent['actual'])->toBe('220.3000')
            ->and($rent['variance'])->toBe('20.3000')
            ->and((float) $rent['variance_percent'])->toBe(10.15)
            ->and($rent['cost_center_code'])->toBe('ADM')
            ->and($rows->firstWhere('account_code', '4000')['actual'])->toBe('1100.0000')
            ->and($rows->firstWhere('account_code', '5000')['budget'])->toBe('0.0000')
            ->and($rows->firstWhere('account_code', '1000'))->toBeNull();

        $filtered = $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/variance?cost_center_id={$this->centre->id}")->assertOk()->json('data.rows');
        expect($filtered)->toHaveCount(1);
    });

    test('versions keep the earlier one, and only a draft can change', function (): void {
        $this->postJson("/api/v1/general-ledger/budgets/{$this->budgetId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->putJson("/api/v1/general-ledger/budgets/{$this->budgetId}/lines", ['lines' => [['account_id' => $this->rent->id, 'period_number' => 1, 'amount' => '1']]])->assertConflict();

        $second = $this->postJson("/api/v1/general-ledger/budgets/{$this->budgetId}/new-version")->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->putJson("/api/v1/general-ledger/budgets/{$second}/lines", ['lines' => [['account_id' => $this->sales->id, 'period_number' => 1, 'amount' => '5000.00']]])->assertOk();

        expect($this->getJson("/api/v1/general-ledger/budgets/{$second}")->json('data.lines'))->toHaveCount(24);

        $first = collect($this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}")->json('data.lines'))->firstWhere('period_number', 1);
        expect($first['amount'])->not->toBe('5000.0000');

        $this->postJson("/api/v1/general-ledger/budgets/{$second}/approve")->assertOk();
        $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}")->assertJsonPath('data.status', 'superseded');
    });

    test('the rolling forecast uses actuals, then the budget or the run rate', function (): void {
        app(TenantContext::class)->setTenant($this->tenant);
        ledgerPost('2026-01-10', $this->rent, $this->bank, '150.00', $this->centre->id);
        ledgerPost('2026-02-10', $this->rent, $this->bank, '250.00', $this->centre->id);

        ledgerActAs($this->accountant, $this->tenant);

        $byBudget = collect($this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/rolling-forecast?as_of_period=2")->assertOk()->json('data.rows'))->firstWhere('account_code', '6000');
        $byRate = collect($this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/rolling-forecast?as_of_period=2&method=run_rate")->assertOk()->json('data.rows'))->firstWhere('account_code', '6000');

        expect($byBudget['actual_to_date'])->toBe('400.0000')
            ->and($byBudget['forecast_remaining'])->toBe('1000.0000')
            ->and($byBudget['full_year_forecast'])->toBe('1400.0000')
            ->and($byBudget['full_year_budget'])->toBe('1200.0000')
            ->and($byBudget['months'][1]['basis'])->toBe('actual')
            ->and($byBudget['months'][3]['basis'])->toBe('budget')
            ->and($byRate['full_year_forecast'])->toBe('2400.0000');
    });

    test('roles without ledger access and other tenants stay out', function (): void {
        ledgerActAs(ledgerMember($this->tenant, 'member'), $this->tenant);
        $this->getJson('/api/v1/general-ledger/budgets')->assertForbidden();
        $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/variance")->assertForbidden();

        ledgerActAs(ledgerMember($this->other, 'admin'), $this->other);
        $this->getJson('/api/v1/general-ledger/budgets')->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}")->assertNotFound();
        $this->getJson("/api/v1/general-ledger/budgets/{$this->budgetId}/variance")->assertNotFound();
        $this->postJson('/api/v1/general-ledger/budgets', ['fiscal_year_id' => $this->year2026->id, 'name' => 'Theirs'])->assertUnprocessable();
        $this->getJson("/api/v1/general-ledger/financial-statements/ratios?fiscal_year_id={$this->year2026->id}")->assertNotFound();
    });
});

test('budget lines refuse the accounts of another tenant', function (): void {
    ledgerActAs($this->accountant, $this->tenant);
    $budgetId = $this->postJson('/api/v1/general-ledger/budgets', ['fiscal_year_id' => $this->year2026->id, 'name' => 'Base'])->json('data.id');
    $foreign = ChartOfAccount::factory()->create(['tenant_id' => $this->other->id, 'company_id' => null, 'account_code' => '9999']);

    $this->putJson("/api/v1/general-ledger/budgets/{$budgetId}/lines", ['lines' => [['account_id' => $foreign->id, 'period_number' => 1, 'amount' => '1']]])->assertUnprocessable();
});
