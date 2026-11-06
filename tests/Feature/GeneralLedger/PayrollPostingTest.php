<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\PayrollLedgerPosting;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollRun;

require_once __DIR__.'/../Payroll/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
});

/**
 * @return array{0: Tenant, 1: User}
 */
function payrollLedgerCompany(string $country): array
{
    $tenant = payrollTenant($country);
    $manager = payrollMember($tenant, 'owner');
    payrollProfile($tenant, payrollEmployee($tenant));

    if ($country === 'CH') {
        payrollSwissSetup($tenant, $manager);
    } else {
        ItalianPayrollSettings::query()->create(['tenant_id' => $tenant->id, 'inps_company_number' => '1234567890', 'inps_office_code' => '5100', 'inail_code' => '998']);
    }

    return [$tenant, $manager];
}

function payrollAccount(Tenant $tenant, string $code, string $type): string
{
    return (string) ChartOfAccount::create([
        'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type,
        'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit', 'currency' => $tenant->default_currency,
    ])->id;
}

/**
 * @return array<string, mixed>
 */
function payrollMapping(Tenant $tenant): array
{
    return [
        'salary_expense_account_id' => payrollAccount($tenant, '6400', 'expense'),
        'employer_cost_account_id' => payrollAccount($tenant, '6410', 'expense'),
        'expense_allowance_account_id' => payrollAccount($tenant, '6420', 'expense'),
        'employee_payable_account_id' => payrollAccount($tenant, '2300', 'liability'),
        'social_payable_account_id' => payrollAccount($tenant, '2310', 'liability'),
        'tax_payable_account_id' => payrollAccount($tenant, '2320', 'liability'),
        'rounding_account_id' => payrollAccount($tenant, '6960', 'expense'),
        'wage_type_accounts' => [ItalianWageCodes::Tfr => payrollAccount($tenant, '2330', 'liability')],
    ];
}

function payrollPosting(string $runId): PayrollLedgerPosting
{
    return PayrollLedgerPosting::withoutGlobalScopes()->where('payroll_run_id', $runId)->sole();
}

function payrollBalance(Tenant $tenant, string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');

    return number_format((float) JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) as balance')->value('balance'), 2, '.', '');
}

dataset('payroll countries', ['CH', 'IT']);

test('a closed run is posted once, with its cost, net pay, contributions and taxes', function (string $country): void {
    [$tenant, $manager] = payrollLedgerCompany($country);
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);
    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', payrollMapping($tenant))->assertOk();

    $run = payrollClosedRun($tenant, $manager, 2026, 1);
    $posting = payrollPosting($run->id);
    $totals = $posting->payload['totals'];
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'payroll_run')->where('source_document_id', $run->id)->sole();

    expect($posting->last_error)->toBeNull()
        ->and($posting->status)->toBe('posted')
        ->and($entry->isBalanced())->toBeTrue()
        ->and(payrollBalance($tenant, '6400'))->toBe($totals['gross_salary'])
        ->and(payrollBalance($tenant, '2300'))->toBe('-'.$totals['payout_amount'])
        ->and((float) payrollBalance($tenant, '2310'))->toBeLessThan(0.0)
        ->and((float) payrollBalance($tenant, '6410'))->toBeGreaterThan(0.0);

    if ($country === 'IT') {
        expect((float) payrollBalance($tenant, '2320'))->toBeLessThan(0.0)
            ->and((float) payrollBalance($tenant, '2330'))->toBeLessThan(0.0);
    }

    payrollApi($manager, $tenant)->postJson("/api/v1/general-ledger/payroll-postings/{$run->id}/post")->assertOk();

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->count())->toBe(1);
})->with('payroll countries');

test('without a mapping the run stays closed and can be posted later', function (string $country): void {
    [$tenant, $manager] = payrollLedgerCompany($country);
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);

    $run = payrollClosedRun($tenant, $manager, 2026, 1);
    $posting = payrollPosting($run->id);

    expect($run->status)->toBe('Final')
        ->and($posting->status)->toBe('pending')
        ->and($posting->last_error)->toContain('account')
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->exists())->toBeFalse();

    payrollApi($manager, $tenant)->postJson("/api/v1/general-ledger/payroll-postings/{$run->id}/post")
        ->assertStatus(422)
        ->assertJsonPath('errors.payroll_posting.0', fn (string $message): bool => str_contains($message, 'configure the'));

    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', payrollMapping($tenant))->assertOk();
    payrollApi($manager, $tenant)->getJson('/api/v1/general-ledger/payroll-postings?status=pending')->assertOk()->assertJsonCount(1, 'data');
    payrollApi($manager, $tenant)->postJson("/api/v1/general-ledger/payroll-postings/{$run->id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->where('status', 'posted')->count())->toBe(1);
})->with('payroll countries');

test('an account of a wage type without a mapping is refused by name', function (): void {
    [$tenant, $manager] = payrollLedgerCompany('IT');
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);
    $mapping = payrollMapping($tenant);
    unset($mapping['wage_type_accounts']);
    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', $mapping)->assertOk();

    $run = payrollClosedRun($tenant, $manager, 2026, 1);

    expect(payrollPosting($run->id)->status)->toBe('pending')
        ->and(payrollPosting($run->id)->last_error)->toContain(ItalianWageCodes::Tfr);
});

test('the run goes to the period of its payment date and waits when that period is missing', function (): void {
    [$tenant, $manager] = payrollLedgerCompany('CH');
    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', payrollMapping($tenant))->assertOk();

    $run = payrollClosedRun($tenant, $manager, 2026, 1);

    expect(payrollPosting($run->id)->status)->toBe('pending')
        ->and(payrollPosting($run->id)->last_error)->toContain('No fiscal period');

    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);
    payrollApi($manager, $tenant)->postJson("/api/v1/general-ledger/payroll-postings/{$run->id}/post")->assertOk();

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->sole();

    expect($entry->posting_date->toDateString())->toBe(payrollPosting($run->id)->run_date->toDateString())
        ->and($entry->posting_date->format('Y'))->toBe('2026');
});

test('reopening reverses the entry and closing again posts a new one', function (string $country): void {
    [$tenant, $manager] = payrollLedgerCompany($country);
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);
    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', payrollMapping($tenant))->assertOk();

    $run = payrollClosedRun($tenant, $manager, 2026, 1);
    $first = JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->sole();

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/reopen", ['reason' => 'Correzione'])->assertOk();

    expect($first->refresh()->status)->toBe('reversed')
        ->and(payrollPosting($run->id)->status)->toBe('reversed')
        ->and(payrollBalance($tenant, '6400'))->toBe('0.00')
        ->and(payrollBalance($tenant, '2300'))->toBe('0.00');

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/reopen", ['reason' => 'Di nuovo'])->assertStatus(422);
    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/finalize")->assertOk();

    $live = JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->where('status', 'posted')->where('journal_type', '!=', 'reversing')->sole();

    expect($live->id)->not->toBe($first->id)
        ->and(payrollPosting($run->id)->status)->toBe('posted')
        ->and(payrollPosting($run->id)->journal_entry_id)->toBe($live->id);
})->with('payroll countries');

test('a reversal falls in the open period when the period of the run is closed', function (): void {
    [$tenant, $manager] = payrollLedgerCompany('CH');
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);
    payrollApi($manager, $tenant)->putJson('/api/v1/general-ledger/payroll-settings', payrollMapping($tenant))->assertOk();

    $run = payrollClosedRun($tenant, $manager, 2026, 1);
    $date = payrollPosting($run->id)->run_date->toDateString();
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
    app(PeriodClosingServiceInterface::class)->closePeriod((string) $period->id, (string) $manager->id);

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/reopen", ['reason' => 'Correzione'])->assertOk();

    $reversal = JournalEntry::withoutGlobalScopes()->where('source_document_id', $run->id)->where('journal_type', 'reversing')->sole();

    expect($reversal->posting_date->toDateString())->toBe('2026-11-06')
        ->and(payrollPosting($run->id)->status)->toBe('reversed');
});

test('a run reopened before it was posted leaves the queue', function (): void {
    [$tenant, $manager] = payrollLedgerCompany('CH');
    $run = payrollClosedRun($tenant, $manager, 2026, 1);

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/reopen", ['reason' => 'Correzione'])->assertOk();

    expect(payrollPosting($run->id)->status)->toBe('withdrawn')
        ->and(PayrollRun::query()->withoutGlobalScopes()->findOrFail($run->id)->status)->not->toBe('Final');

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$run->id}/finalize")->assertOk();

    expect(payrollPosting($run->id)->status)->toBe('pending');
});
