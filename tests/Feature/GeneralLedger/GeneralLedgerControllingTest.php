<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
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
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\PostingAccountResolver;

uses(RefreshDatabase::class);

/**
 * Ledger controlling: settings and posting account resolver, manual journals with four-eyes approval, recurring
 * journals, accruals and deferrals with automatic reversal, period close checklist, year-end closing, FX revaluation of
 * open items, reports from posted data and the audit trail.
 */
function glcMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function glcActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function glcAccount(Tenant $tenant, string $code, string $type, string $normal, array $extra = []): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
        'is_active' => true,
        'allows_manual_posting' => true,
        ...$extra,
    ]);
}

function glcPeriod(Tenant $tenant, string $date): FiscalPeriod
{
    return FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @return array<string, mixed>
 */
function glcJournal(Tenant $tenant, string $date, array $lines, array $extra = []): array
{
    return [
        'fiscal_period_id' => (string) glcPeriod($tenant, $date)->id,
        'posting_date' => $date,
        'description' => 'Manual journal',
        'lines' => $lines,
        ...$extra,
    ];
}

function glcPost(Tenant $tenant, string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount, array $lineExtra = []): JournalEntry
{
    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenant->id,
        'fiscal_period_id' => glcPeriod($tenant, $date)->id,
        'posting_date' => $date,
        'description' => 'Service posting',
        'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => '0', ...$lineExtra],
            ['account_id' => $credit->id, 'debit_amount' => '0', 'credit_amount' => $amount, ...$lineExtra],
        ],
    ]);
}

function glcAssertBalanced(JournalEntry $entry): void
{
    $entry->load('lines');
    $debit = '0';
    $credit = '0';

    foreach ($entry->lines as $line) {
        $debit = bcadd($debit, (string) $line->debit_amount, 4);
        $credit = bcadd($credit, (string) $line->credit_amount, 4);
    }

    expect(bccomp($debit, $credit, 4))->toBe(0)->and(bccomp($debit, '0', 4))->toBe(1);
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SpA', 'default_currency' => 'EUR']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SpA', 'default_currency' => 'EUR']);
    $this->accountant = glcMember($this->tenant, 'accountant');
    $this->approver = glcMember($this->tenant, 'admin');
    $this->reader = glcMember($this->tenant, 'accountant_readonly');

    app(TenantContext::class)->setTenant($this->other);
    app(OpenFiscalYearAction::class)->execute((string) $this->other->id, 2026);
    $this->foreignAccount = glcAccount($this->other, '1000', 'asset', 'debit');

    app(TenantContext::class)->setTenant($this->tenant);
    $this->year2026 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);
    $this->year2027 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2027);

    $this->bank = glcAccount($this->tenant, '1000', 'asset', 'debit', ['reconciliation_type' => 'bank']);
    $this->receivable = glcAccount($this->tenant, '1100', 'asset', 'debit', ['reconciliation_type' => 'customer']);
    $this->prepaid = glcAccount($this->tenant, '1300', 'asset', 'debit');
    $this->payable = glcAccount($this->tenant, '2000', 'liability', 'credit', ['reconciliation_type' => 'supplier']);
    $this->accrued = glcAccount($this->tenant, '2300', 'liability', 'credit');
    $this->capital = glcAccount($this->tenant, '3000', 'equity', 'credit');
    $this->retained = glcAccount($this->tenant, '3100', 'equity', 'credit');
    $this->sales = glcAccount($this->tenant, '4000', 'revenue', 'credit');
    $this->otherIncome = glcAccount($this->tenant, '4100', 'revenue', 'credit');
    $this->fxGain = glcAccount($this->tenant, '4800', 'revenue', 'credit');
    $this->rent = glcAccount($this->tenant, '6000', 'expense', 'debit');
    $this->fxLoss = glcAccount($this->tenant, '6800', 'expense', 'debit');
    $this->automaticOnly = glcAccount($this->tenant, '1900', 'asset', 'debit', ['allows_manual_posting' => false]);
    $this->centre = CostCenter::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'ADM', 'name' => 'Administration']);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('ledger settings resolve configured accounts, fall back to preference codes and refuse wrong types', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['accounting' => ['accounts' => ['revenue_account_code' => '4000']]]]]);
    glcActAs($this->accountant, $this->tenant);

    // OpenAPI: posting_accounts is an object per role, each {account_id, account_code, account_name, source}.
    $settings = $this->getJson('/api/v1/general-ledger/settings')->assertOk()->json('data');
    expect(array_keys($settings))->toBe(['posting_accounts', 'manual_journal_approval_required', 'manual_journal_approval_threshold'])
        ->and(array_keys($settings['posting_accounts']))->toBe(PostingAccountRole::values())
        ->and(array_keys($settings['posting_accounts']['sales_revenue']))->toBe(['account_id', 'account_code', 'account_name', 'source']);

    $this->getJson('/api/v1/general-ledger/settings')->assertOk()
        ->assertJsonPath('data.posting_accounts.retained_earnings.account_id', null)
        ->assertJsonPath('data.posting_accounts.sales_revenue.account_id', (string) $this->sales->id)
        ->assertJsonPath('data.posting_accounts.sales_revenue.source', 'preferences');

    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['retained_earnings' => $this->sales->id]])->assertUnprocessable();
    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['retained_earnings' => $this->foreignAccount->id]])->assertUnprocessable();
    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['unknown_role' => $this->sales->id]])->assertUnprocessable();

    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['retained_earnings' => $this->retained->id], 'manual_journal_approval_required' => true, 'manual_journal_approval_threshold' => 1000])
        ->assertOk()
        ->assertJsonPath('data.posting_accounts.retained_earnings.source', 'settings')
        ->assertJsonPath('data.manual_journal_approval_required', true)
        ->assertJsonPath('data.manual_journal_approval_threshold', '1000.0000');

    expect(app(PostingAccountResolver::class)->account((string) $this->tenant->id, PostingAccountRole::RetainedEarnings)?->id)->toBe($this->retained->id);

    glcActAs($this->reader, $this->tenant);
    $this->getJson('/api/v1/general-ledger/settings')->assertOk();
    $this->putJson('/api/v1/general-ledger/settings', ['manual_journal_approval_required' => false])->assertForbidden();
});

test('a manual journal posts balanced, refuses unbalanced lines, automatic-only accounts and closed periods', function (): void {
    glcActAs($this->accountant, $this->tenant);
    $lines = [['account_id' => $this->rent->id, 'debit_amount' => '100.10', 'cost_center_id' => $this->centre->id], ['account_id' => $this->bank->id, 'credit_amount' => '100.10']];

    $response = $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-02-10', $lines, ['entry_number' => 'CLIENT-1']))->assertCreated()
        ->assertJsonPath('data.status', 'posted');
    $entry = JournalEntry::findOrFail($response->json('data.id'));

    expect($entry->journal_number)->toBe(1)->and($entry->entry_number)->not->toBe('CLIENT-1');
    glcAssertBalanced($entry);

    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-02-10', [['account_id' => $this->rent->id, 'debit_amount' => '100'], ['account_id' => $this->bank->id, 'credit_amount' => '99.99']]))->assertUnprocessable();
    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-02-10', [['account_id' => $this->automaticOnly->id, 'debit_amount' => '5'], ['account_id' => $this->bank->id, 'credit_amount' => '5']]))
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-02-10', [['account_id' => $this->foreignAccount->id, 'debit_amount' => '5'], ['account_id' => $this->bank->id, 'credit_amount' => '5']]))
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');

    app(PeriodClosingServiceInterface::class)->closePeriod((string) glcPeriod($this->tenant, '2026-03-15')->id, (string) $this->accountant->id);
    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-03-15', $lines))->assertUnprocessable();

    $this->postJson("/api/v1/general-ledger/journals/{$entry->id}/reverse", ['reversal_date' => '2026-02-20', 'reason' => 'Typo'])->assertOk()
        ->assertJsonPath('data.journal_type', 'reversing');
    expect($entry->refresh()->status)->toBe('reversed');
    $this->postJson("/api/v1/general-ledger/journals/{$entry->id}/reverse", ['reversal_date' => '2026-02-21'])->assertConflict();
    $this->postJson("/api/v1/general-ledger/journals/{$entry->id}/reverse", ['reversal_date' => '2026-03-20'])->assertConflict();

    glcActAs($this->reader, $this->tenant);
    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-02-10', $lines))->assertForbidden();
});

test('four-eyes approval keeps a manual journal pending until another person approves it', function (): void {
    glcActAs($this->accountant, $this->tenant);
    $this->putJson('/api/v1/general-ledger/settings', ['manual_journal_approval_required' => true, 'manual_journal_approval_threshold' => 500])->assertOk();
    $lines = [['account_id' => $this->rent->id, 'debit_amount' => '800', 'cost_center_id' => $this->centre->id], ['account_id' => $this->bank->id, 'credit_amount' => '800']];

    $small = $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-04-10', [['account_id' => $this->rent->id, 'debit_amount' => '50', 'cost_center_id' => $this->centre->id], ['account_id' => $this->bank->id, 'credit_amount' => '50']]))->assertCreated();
    expect($small->json('data.status'))->toBe('posted');

    $id = $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-04-10', $lines))->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.journal_number', null)
        ->json('data.id');

    $this->postJson("/api/v1/general-ledger/journals/{$id}/post")->assertConflict();
    $this->postJson("/api/v1/general-ledger/journals/{$id}/approve")->assertForbidden();

    $draftId = $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-04-11', $lines, ['status' => 'draft']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/post")->assertConflict();
    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

    glcActAs($this->approver, $this->tenant);
    $this->postJson("/api/v1/general-ledger/journals/{$id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.approved_by', (string) $this->approver->id);
    $this->postJson("/api/v1/general-ledger/journals/{$id}/approve")->assertConflict();
    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/reject", [])->assertUnprocessable();
    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/reject", ['reason' => 'Wrong account'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/approve")->assertConflict();

    expect(JournalEntry::findOrFail($id)->journal_number)->not->toBeNull();

    app(TenantContext::class)->setTenant($this->other);
    $foreign = glcPost($this->other, '2026-04-10', $this->foreignAccount, glcAccount($this->other, '4000', 'revenue', 'credit'), '10');
    glcActAs($this->approver, $this->tenant);
    $this->postJson("/api/v1/general-ledger/journals/{$foreign->id}/approve")->assertNotFound();
    $this->postJson("/api/v1/general-ledger/journals/{$foreign->id}/reverse", ['reversal_date' => '2026-04-11'])->assertNotFound();
});

test('a recurring journal posts each due run once, in its own period, and advances', function (): void {
    glcActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/general-ledger/recurring-journals', ['name' => 'Rent', 'description' => 'Office rent', 'frequency' => 'monthly', 'start_date' => '2026-01-31', 'lines' => [
        ['account_id' => $this->rent->id, 'debit_amount' => '1200', 'cost_center_id' => $this->centre->id], ['account_id' => $this->bank->id, 'credit_amount' => '1100'],
    ]])->assertUnprocessable();

    $recurringId = $this->postJson('/api/v1/general-ledger/recurring-journals', ['name' => 'Rent', 'description' => 'Office rent', 'frequency' => 'monthly', 'start_date' => '2026-01-31', 'end_date' => '2026-04-30', 'lines' => [
        ['account_id' => $this->rent->id, 'debit_amount' => '1200', 'cost_center_id' => $this->centre->id], ['account_id' => $this->payable->id, 'credit_amount' => '1200'],
    ]])->assertCreated()->assertJsonPath('data.next_run_date', '2026-01-31')->json('data.id');

    $this->postJson("/api/v1/general-ledger/recurring-journals/{$recurringId}/run", ['as_of' => '2026-01-01'])->assertConflict();

    $result = $this->postJson('/api/v1/general-ledger/scheduled-journals/run', ['as_of' => '2026-03-31'])->assertOk()->json('data');
    expect($result['recurring']['failed'])->toBe([])->and($result['recurring']['posted'])->toHaveCount(3);

    $entries = JournalEntry::query()->where('recurring_journal_id', $recurringId)->orderBy('posting_date')->get();
    expect($entries->map(fn (JournalEntry $entry): string => $entry->posting_date->toDateString())->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and($entries->pluck('fiscal_period_id')->unique())->toHaveCount(3);
    $entries->each(fn (JournalEntry $entry) => glcAssertBalanced($entry));

    $again = $this->postJson('/api/v1/general-ledger/scheduled-journals/run', ['as_of' => '2026-03-31'])->assertOk()->json('data');
    expect($again['recurring']['posted'])->toBe([]);

    $this->getJson("/api/v1/general-ledger/recurring-journals/{$recurringId}")->assertOk()
        ->assertJsonPath('data.next_run_date', '2026-04-30')
        ->assertJsonCount(3, 'data.runs');

    $this->postJson("/api/v1/general-ledger/recurring-journals/{$recurringId}/run", ['as_of' => '2026-04-30'])->assertOk();
    $this->getJson("/api/v1/general-ledger/recurring-journals/{$recurringId}")->assertJsonPath('data.status', 'ended');
    $this->postJson("/api/v1/general-ledger/recurring-journals/{$recurringId}/status", ['status' => 'active'])->assertConflict();
    $this->getJson('/api/v1/general-ledger/recurring-journals/'.Str::uuid7())->assertNotFound();
});

test('a deferral is computed by days, posted at the cut-off and reversed automatically the next day', function (): void {
    glcActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/general-ledger/accruals', ['accrual_type' => 'prepaid_expense', 'description' => 'Insurance', 'total_amount' => 1200, 'coverage_start' => '2026-10-01', 'coverage_end' => '2027-09-30', 'cutoff_date' => '2026-12-31', 'income_statement_account_id' => $this->rent->id, 'balance_sheet_account_id' => $this->accrued->id])
        ->assertUnprocessable()->assertJsonValidationErrors('balance_sheet_account_id');
    $this->postJson('/api/v1/general-ledger/accruals', ['accrual_type' => 'prepaid_expense', 'description' => 'Insurance', 'total_amount' => 1200, 'coverage_start' => '2026-10-01', 'coverage_end' => '2027-09-30', 'cutoff_date' => '2027-10-31', 'income_statement_account_id' => $this->rent->id, 'balance_sheet_account_id' => $this->prepaid->id])
        ->assertUnprocessable()->assertJsonValidationErrors('cutoff_date');

    $accrual = $this->postJson('/api/v1/general-ledger/accruals', ['accrual_type' => 'prepaid_expense', 'description' => 'Insurance', 'total_amount' => 1200, 'coverage_start' => '2026-10-01', 'coverage_end' => '2027-09-30', 'cutoff_date' => '2026-12-31', 'income_statement_account_id' => $this->rent->id, 'balance_sheet_account_id' => $this->prepaid->id, 'cost_center_id' => $this->centre->id])
        ->assertCreated()
        ->assertJsonPath('data.coverage_days', 365)
        ->assertJsonPath('data.accrued_days', 273)
        ->assertJsonPath('data.amount', '897.5300')
        ->assertJsonPath('data.reversal_date', '2027-01-01')
        ->json('data');

    $entry = JournalEntry::with('lines')->findOrFail($accrual['journal_entry_id']);
    $lines = $entry->lines->keyBy('account_id');
    expect($entry->posting_date->toDateString())->toBe('2026-12-31')
        ->and((string) $lines[$this->prepaid->id]->debit_amount)->toBe('897.5300')
        ->and((string) $lines[$this->rent->id]->credit_amount)->toBe('897.5300');
    glcAssertBalanced($entry);

    $result = $this->postJson('/api/v1/general-ledger/scheduled-journals/run', ['as_of' => '2027-01-01'])->assertOk()->json('data.reversals');
    expect($result['reversed'])->toHaveCount(1);
    $reversal = JournalEntry::with('lines')->findOrFail($result['reversed'][0]);
    expect($reversal->posting_date->toDateString())->toBe('2027-01-01')
        ->and((string) $reversal->lines->keyBy('account_id')[$this->prepaid->id]->credit_amount)->toBe('897.5300')
        ->and($entry->refresh()->status)->toBe('reversed');

    $this->getJson("/api/v1/general-ledger/accruals/{$accrual['id']}")->assertOk()->assertJsonPath('data.journal_status', 'reversed');

    $rateo = $this->postJson('/api/v1/general-ledger/accruals', ['accrual_type' => 'accrued_expense', 'description' => 'Interest', 'total_amount' => '365', 'coverage_start' => '2026-07-01', 'coverage_end' => '2027-06-30', 'cutoff_date' => '2026-12-31', 'income_statement_account_id' => $this->rent->id, 'balance_sheet_account_id' => $this->accrued->id, 'cost_center_id' => $this->centre->id])
        ->assertCreated()->assertJsonPath('data.accrued_days', 184)->json('data');
    expect($rateo['amount'])->toBe('184.0000');
});

test('the close checklist blocks the closing until drafts, due reversals and blocking tasks are cleared', function (): void {
    glcActAs($this->accountant, $this->tenant);
    $period = glcPeriod($this->tenant, '2026-05-15');
    $lines = [['account_id' => $this->rent->id, 'debit_amount' => '10', 'cost_center_id' => $this->centre->id], ['account_id' => $this->bank->id, 'credit_amount' => '10']];

    $draftId = $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-05-10', $lines, ['status' => 'draft']))->assertCreated()->json('data.id');
    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-04-30', $lines, ['auto_reverse_on' => '2026-05-01']))->assertCreated();
    $taskId = $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-tasks", ['title' => 'Bank reconciliation signed off'])->assertCreated()->json('data.id');

    $checklist = $this->getJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-checklist")->assertOk()->json('data');
    $checks = collect($checklist['checks'])->keyBy('code');
    expect(array_keys($checklist))->toBe(['fiscal_period_id', 'period_name', 'status', 'can_close', 'checks', 'tasks'])
        ->and(array_keys($checklist['checks'][0]))->toBe(['code', 'label', 'blocking', 'passed', 'count'])
        ->and(array_keys($checklist['tasks'][0]))->toBe(['id', 'fiscal_period_id', 'title', 'description', 'is_blocking', 'status', 'created_by', 'completed_by', 'completed_at', 'completion_note']);
    expect($checklist['can_close'])->toBeFalse()
        ->and($checks['no_drafts']['passed'])->toBeFalse()
        ->and($checks['automatic_reversals_posted']['count'])->toBe(1);

    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close")->assertConflict();

    $this->postJson("/api/v1/general-ledger/journals/{$draftId}/post")->assertOk();
    $this->postJson('/api/v1/general-ledger/scheduled-journals/run', ['as_of' => '2026-05-31'])->assertOk();
    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close")->assertConflict();
    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-tasks/{$taskId}/status", ['status' => 'waived'])->assertUnprocessable();
    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-tasks/{$taskId}/status", ['status' => 'done'])->assertOk()->assertJsonPath('data.completed_by', (string) $this->accountant->id);

    $this->getJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-checklist")->assertJsonPath('data.can_close', true);
    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close")->assertOk();
    $this->postJson("/api/v1/general-ledger/fiscal-periods/{$period->id}/close-tasks", ['title' => 'Late task'])->assertConflict();

    $foreignPeriod = glcPeriod($this->other, '2026-05-15');
    $this->getJson("/api/v1/general-ledger/fiscal-periods/{$foreignPeriod->id}/close-checklist")->assertNotFound();
});

test('the year-end closing needs the retained earnings account and closes income accounts on either side', function (): void {
    glcPost($this->tenant, '2026-02-10', $this->bank, $this->capital, '10000');
    glcPost($this->tenant, '2026-03-10', $this->receivable, $this->sales, '4000');
    glcPost($this->tenant, '2026-03-12', $this->otherIncome, $this->bank, '150');
    glcPost($this->tenant, '2026-04-10', $this->rent, $this->payable, '1500', ['cost_center_id' => $this->centre->id]);

    $closing = app(PeriodClosingServiceInterface::class);

    foreach ($this->year2026->periods as $period) {
        $closing->closePeriod((string) $period->id, (string) $this->accountant->id);
    }

    expect(fn () => $closing->closeFiscalYear((string) $this->year2026->id, (string) $this->accountant->id))
        ->toThrow(LedgerPostingException::class);
    expect(ChartOfAccount::query()->where('account_code', '3000')->count())->toBe(1);

    glcActAs($this->accountant, $this->tenant);
    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['retained_earnings' => $this->retained->id]])->assertOk();
    $this->postJson("/api/v1/general-ledger/fiscal-years/{$this->year2026->id}/close")->assertOk();

    $closingEntry = JournalEntry::with('lines')->where('journal_type', 'closing')->firstOrFail();
    glcAssertBalanced($closingEntry);
    $lines = $closingEntry->lines->keyBy('account_id');

    expect((string) $lines[$this->sales->id]->debit_amount)->toBe('4000.0000')
        ->and((string) $lines[$this->otherIncome->id]->credit_amount)->toBe('150.0000')
        ->and((string) $lines[$this->rent->id]->credit_amount)->toBe('1500.0000')
        ->and((string) $lines[$this->retained->id]->credit_amount)->toBe('2350.0000');

    $this->postJson('/api/v1/general-ledger/journals', glcJournal($this->tenant, '2026-12-15', [['account_id' => $this->bank->id, 'debit_amount' => '1'], ['account_id' => $this->capital->id, 'credit_amount' => '1']]))->assertUnprocessable();

    $sheet = $this->getJson('/api/v1/general-ledger/reports/balance-sheet?as_of=2026-12-31')->assertOk()->json('data');
    expect($sheet['is_balanced'])->toBeTrue()
        ->and($sheet['totals']['result_not_yet_closed'])->toBe('0.0000')
        ->and($sheet['totals']['total_assets'])->toBe('13850.0000');
});

test('reports read posted data with comparison, drill-down, dimensions and the audit trail', function (): void {
    glcPost($this->tenant, '2026-01-10', $this->bank, $this->capital, '5000');
    glcPost($this->tenant, '2026-02-10', $this->receivable, $this->sales, '3000');
    glcPost($this->tenant, '2026-02-15', $this->rent, $this->bank, '1000', ['cost_center_id' => $this->centre->id]);
    $reversed = glcPost($this->tenant, '2026-02-20', $this->rent, $this->bank, '400');
    app(DoubleEntryPostingServiceInterface::class)->reverseJournal((string) $reversed->id, '2026-02-21');
    glcPost($this->tenant, '2027-02-10', $this->receivable, $this->sales, '3500');

    glcActAs($this->reader, $this->tenant);

    $trial = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-02-01&to=2026-02-28')->assertOk()->json('data');
    $rows = collect($trial['accounts'])->keyBy('account_code');
    expect($trial['is_balanced'])->toBeTrue()
        ->and($rows['1000']['opening_balance'])->toBe('5000.0000')
        ->and($rows['1000']['closing_balance'])->toBe('4000.0000')
        ->and($rows['6000']['debit'])->toBe('1400.0000')
        ->and($rows['6000']['closing_balance'])->toBe('1000.0000')
        ->and($rows['6000']['drill_down'])->toContain((string) $this->rent->id);

    $income = $this->getJson('/api/v1/general-ledger/reports/income-statement?from=2027-01-01&to=2027-12-31&compare_from=2026-01-01&compare_to=2026-12-31')->assertOk()->json('data');
    $revenue = collect($income['sections']['revenue']['accounts'])->keyBy('account_code');
    expect($income['totals']['net_income'])->toBe('3500.0000')
        ->and($income['comparison_totals']['net_income'])->toBe('2000.0000')
        ->and($revenue['4000']['variance'])->toBe('500.0000');

    $sheet = $this->getJson('/api/v1/general-ledger/reports/balance-sheet?as_of=2026-12-31&compare_as_of=2026-01-31')->assertOk()->json('data');
    expect($sheet['is_balanced'])->toBeTrue()
        ->and($sheet['totals']['result_not_yet_closed'])->toBe('2000.0000')
        ->and($sheet['comparison_totals']['total_assets'])->toBe('5000.0000');

    $ledger = $this->getJson("/api/v1/general-ledger/reports/account-ledger?account_id={$this->bank->id}&from=2026-02-01&to=2026-02-28&per_page=2&page=2")->assertOk()->json('data');
    expect($ledger['opening_balance'])->toBe('5000.0000')
        ->and($ledger['meta']['total'])->toBe(3)
        ->and($ledger['lines'])->toHaveCount(1)
        ->and($ledger['lines'][0]['running_balance'])->toBe('4000.0000')
        ->and($ledger['closing_balance'])->toBe('4000.0000');
    $this->getJson("/api/v1/general-ledger/reports/account-ledger?account_id={$this->foreignAccount->id}&from=2026-02-01&to=2026-02-28")->assertNotFound();
    $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-03-01&to=2026-02-01')->assertUnprocessable();
    $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-02-01&to=2026-02-28&cost_center_id='.Str::uuid7())->assertUnprocessable();

    $dimensions = $this->getJson('/api/v1/general-ledger/reports/dimensions?dimension=cost_center&from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    $groups = collect($dimensions['groups'])->keyBy(fn (array $group): string => (string) ($group['dimension_code'] ?? 'none'));
    expect($dimensions['total'])->toBe('2000.0000')
        ->and($groups['ADM']['result'])->toBe('-1000.0000');

    $csv = $this->get('/api/v1/general-ledger/audit-trail/export?from=2026-01-01&to=2026-12-31')->assertOk()->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(5)->and($csv)->toContain('reversed');
    $json = $this->getJson('/api/v1/general-ledger/audit-trail/export?from=2026-01-01&to=2026-12-31&format=json')->assertOk()->json('data');
    expect($json)->toHaveCount(5);
});

test('open foreign-currency receivables are revalued at the closing rate and reversed the next day', function (): void {
    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $this->tenant->id,
        'fiscal_period_id' => glcPeriod($this->tenant, '2026-11-10')->id,
        'posting_date' => '2026-11-10',
        'description' => 'Invoice in USD',
        'lines' => [
            ['account_id' => $this->receivable->id, 'debit_amount' => '1000', 'credit_amount' => '0', 'currency' => 'USD', 'exchange_rate' => '0.900000', 'partner_type' => 'customer', 'partner_id' => (string) Str::uuid7()],
            ['account_id' => $this->sales->id, 'debit_amount' => '0', 'credit_amount' => '1000', 'currency' => 'USD', 'exchange_rate' => '0.900000'],
        ],
    ]);
    ExchangeRate::query()->create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.000000', 'effective_date' => '2026-12-30']);

    glcActAs($this->accountant, $this->tenant);
    $this->postJson('/api/v1/general-ledger/fx-revaluations', ['revaluation_date' => '2026-12-31'])->assertUnprocessable();

    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => ['fx_unrealised_gain' => $this->fxGain->id, 'fx_unrealised_loss' => $this->fxLoss->id]])->assertOk();
    $rows = $this->postJson('/api/v1/general-ledger/fx-revaluations', ['revaluation_date' => '2026-12-31'])->assertCreated()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['side'])->toBe('receivable')
        ->and($rows[0]['book_value'])->toBe('900.0000')
        ->and($rows[0]['revalued_value'])->toBe('1000.0000')
        ->and($rows[0]['difference'])->toBe('100.0000');

    $entry = JournalEntry::with('lines')->findOrFail($rows[0]['journal_entry_id']);
    glcAssertBalanced($entry);
    $lines = $entry->lines->keyBy('account_id');
    expect((string) $lines[$this->receivable->id]->debit_amount)->toBe('100.0000')
        ->and((string) $lines[$this->fxGain->id]->credit_amount)->toBe('100.0000')
        ->and($entry->auto_reverse_on->toDateString())->toBe('2027-01-01');

    $again = $this->postJson('/api/v1/general-ledger/fx-revaluations', ['revaluation_date' => '2026-12-31'])->assertCreated()->json('data');
    expect($again[0]['id'])->toBe($rows[0]['id']);

    glcActAs($this->reader, $this->tenant);
    $this->getJson('/api/v1/general-ledger/fx-revaluations')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/general-ledger/fx-revaluations', ['revaluation_date' => '2026-12-31'])->assertForbidden();
});

test('the chart of accounts protects accounts with postings and keeps codes unique', function (): void {
    glcPost($this->tenant, '2026-02-10', $this->bank, $this->capital, '100');
    glcActAs($this->accountant, $this->tenant);

    $this->deleteJson("/api/v1/general-ledger/accounts/{$this->bank->id}")->assertConflict();
    $this->putJson("/api/v1/general-ledger/accounts/{$this->bank->id}", ['account_code' => '1000', 'name' => 'Bank', 'account_type' => 'liability', 'normal_balance' => 'credit'])->assertConflict();
    $this->putJson("/api/v1/general-ledger/accounts/{$this->bank->id}", ['account_code' => '1000', 'name' => 'Main bank', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertOk();
    $this->postJson('/api/v1/general-ledger/accounts', ['account_code' => '1000', 'name' => 'Duplicate', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertUnprocessable();
    $this->putJson("/api/v1/general-ledger/accounts/{$this->prepaid->id}", ['account_code' => '1300', 'name' => 'Prepaid', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent_account_id' => $this->prepaid->id])->assertUnprocessable();
    $this->deleteJson("/api/v1/general-ledger/accounts/{$this->prepaid->id}")->assertOk();
    $this->deleteJson("/api/v1/general-ledger/accounts/{$this->foreignAccount->id}")->assertNotFound();

    $this->postJson('/api/v1/general-ledger/cost-centers', ['code' => 'ADM', 'name' => 'Again'])->assertUnprocessable();
    $centreId = $this->postJson('/api/v1/general-ledger/cost-centers', ['code' => 'SALES', 'name' => 'Sales'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/general-ledger/cost-centers/{$centreId}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/v1/general-ledger/profit-centers', ['code' => 'PC1', 'name' => 'Retail'])->assertCreated();
    // OpenAPI: data is a list of {id, code, name, is_active}.
    expect(array_keys($this->getJson('/api/v1/general-ledger/cost-centers')->assertOk()->json('data.0')))->toBe(['id', 'code', 'name', 'is_active'])
        ->and(array_keys($this->getJson('/api/v1/general-ledger/profit-centers')->assertOk()->json('data.0')))->toBe(['id', 'code', 'name', 'is_active']);
    $this->getJson('/api/v1/general-ledger/cost-centers?active=true')->assertOk()->assertJsonCount(1, 'data');

    glcActAs($this->reader, $this->tenant);
    $this->postJson('/api/v1/general-ledger/cost-centers', ['code' => 'X', 'name' => 'X'])->assertForbidden();
});

test('a manual journal without fiscal_period_id lands in the open period of its posting date', function (): void {
    glcActAs($this->accountant, $this->tenant);
    $lines = [['account_id' => $this->rent->id, 'debit_amount' => '50'], ['account_id' => $this->bank->id, 'credit_amount' => '50']];
    $payload = glcJournal($this->tenant, '2026-02-10', $lines);
    $expected = $payload['fiscal_period_id'];
    unset($payload['fiscal_period_id']);

    $created = $this->postJson('/api/v1/general-ledger/journals', $payload)->assertCreated();
    expect($created->json('data.fiscal_period_id'))->toBe($expected);

    $payload['posting_date'] = '2099-01-10';
    $this->postJson('/api/v1/general-ledger/journals', $payload)->assertUnprocessable();
});
