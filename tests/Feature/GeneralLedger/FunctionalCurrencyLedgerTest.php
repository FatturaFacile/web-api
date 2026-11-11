<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\FunctionalCurrencyBackfill;
use Modules\GeneralLedger\Services\ScheduledReversalService;

uses(RefreshDatabase::class);

/**
 * Every ledger balance and report is expressed in the accounting (functional) currency of the tenant: a EUR tenant
 * invoicing and collecting in USD sees EUR figures on its receivables, a balanced trial balance in EUR, the realised and
 * unrealised exchange differences where they belong, and the export and VAT register in EUR. Lines posted before the
 * functional columns existed are backfilled without guessing a rate, and the rest is reported by the audit command.
 */
function fcAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
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
    ]);
}

function fcPeriod(Tenant $tenant, string $date): FiscalPeriod
{
    return FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
}

function fcAccountId(Tenant $tenant, string $code): string
{
    return (string) ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');
}

/**
 * A posted entry written straight into the tables, as the history before the functional columns: the lines have no
 * functional amounts.
 *
 * @param  list<array{account: ChartOfAccount, debit: string, credit: string, currency: string, rate: string, functional: string}>  $lines
 */
function fcHistoricalEntry(Tenant $tenant, string $number, string $date, array $lines): string
{
    $entryId = (string) Str::uuid7();
    DB::table('journal_entries')->insert([
        'id' => $entryId, 'tenant_id' => $tenant->id, 'fiscal_period_id' => fcPeriod($tenant, $date)->id, 'entry_number' => $number,
        'posting_date' => $date, 'document_date' => $date, 'description' => 'Historical entry', 'status' => 'posted', 'total_amount' => '0',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($lines as $index => $line) {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'posting_date' => $date,
            'line_number' => $index + 1, 'account_id' => $line['account']->id, 'description' => 'Historical line',
            'debit_amount' => $line['debit'], 'credit_amount' => $line['credit'], 'currency' => $line['currency'],
            'exchange_rate' => $line['rate'], 'amount_in_functional_currency' => $line['functional'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $entryId;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->tenant = Tenant::create(['name' => 'Alfa FX', 'legal_name' => 'Alfa FX SpA', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '01234567890']);
    $this->user = User::create(['name' => 'Accountant', 'email' => Str::random(12).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Acme Inc', 'country' => 'IT']);
    $this->fxGain = fcAccount($this->tenant, '4810', 'revenue', 'credit');
    $this->fxLoss = fcAccount($this->tenant, '6810', 'expense', 'debit');
    $this->rounding = fcAccount($this->tenant, '6960', 'expense', 'debit');

    // 1 EUR = 1.25 USD at the invoice (0.8 EUR per USD), 1 EUR = 1.20 USD at the collection (0.833333 EUR per USD)
    ExchangeRate::create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.250000', 'effective_date' => '2026-06-01']);
    ExchangeRate::create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.200000', 'effective_date' => '2026-06-25']);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a USD invoice and its collection at another rate are reported in EUR everywhere', function (): void {
    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => [
        'fx_realised_gain' => $this->fxGain->id, 'fx_realised_loss' => $this->fxLoss->id,
        'fx_unrealised_gain' => $this->fxGain->id, 'fx_unrealised_loss' => $this->fxLoss->id, 'rounding' => $this->rounding->id,
    ]])->assertOk();

    $invoiceId = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => '2026-06-15', 'currency' => 'USD', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $invoiceEntry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'sales_invoice')->where('source_document_id', $invoiceId)->with('lines')->sole();
    $receivable = $invoiceEntry->lines->firstWhere('partner_type', 'customer');

    expect($receivable->currency)->toBe('USD')
        ->and((string) $receivable->exchange_rate)->toBe('0.800000')
        ->and((string) $receivable->debit_amount)->toBe('1220.0000')
        ->and((string) $receivable->functional_debit)->toBe('976.0000')
        ->and((string) $receivable->functional_credit)->toBe('0.0000')
        ->and($invoiceEntry->isBalanced())->toBeTrue();

    // Period-end revaluation of the open receivable at 1.20: unrealised gain, reversed the next day
    $this->travelTo(CarbonImmutable::parse('2026-06-27 10:00:00'));
    $revaluation = $this->postJson('/api/v1/general-ledger/fx-revaluations', ['revaluation_date' => '2026-06-27'])->assertCreated()->json('data.0');
    expect($revaluation['book_value'])->toBe('976.0000')
        ->and($revaluation['revalued_value'])->toBe('1016.6700')
        ->and($revaluation['difference'])->toBe('40.6700');

    // The close check compares the control account with the open USD item like with like (976 EUR on both sides)
    $checks = collect($this->getJson('/api/v1/general-ledger/fiscal-periods/'.fcPeriod($this->tenant, '2026-06-15')->id.'/close-checklist')->assertOk()->json('data.checks'));
    expect($checks->firstWhere('code', 'subledgers_reconciled')['passed'])->toBeTrue()
        ->and($checks->firstWhere('code', 'entries_balanced')['passed'])->toBeTrue();

    app(ScheduledReversalService::class)->processDue((string) $this->tenant->id, CarbonImmutable::parse('2026-06-28'), null);

    // Collection of the full USD amount on 30 June: bank at the rate of the day, receivable at the invoice rate
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $this->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => 1220, 'paid_at' => '2026-06-30'])->assertSuccessful();
    $payment = Payment::query()->where('invoice_id', $invoiceId)->sole();
    $paymentEntry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'customer_payment')->where('source_document_id', $payment->id)->with('lines')->sole();
    $byAccount = $paymentEntry->lines->keyBy('account_id');

    expect((string) $byAccount[fcAccountId($this->tenant, '1020')]->functional_debit)->toBe('1016.6663')
        ->and((string) $byAccount[fcAccountId($this->tenant, '1020')]->exchange_rate)->toBe('0.833333')
        ->and((string) $byAccount[$receivable->account_id]->functional_credit)->toBe('976.0000')
        ->and((string) $byAccount[$this->fxGain->id]->credit_amount)->toBe('40.6663')
        ->and($byAccount[$this->fxGain->id]->currency)->toBe('EUR')
        ->and($paymentEntry->isBalanced())->toBeTrue();

    // Trial balance of the year: balanced in EUR, the receivable closed at its EUR value, the realised gain only
    $trial = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    $rows = collect($trial['accounts'])->keyBy('account_code');

    expect($trial['currency'])->toBe('EUR')
        ->and($trial['is_balanced'])->toBeTrue()
        ->and($trial['totals']['debit'])->toBe($trial['totals']['credit'])
        ->and($rows['1100']['debit'])->toBe('1016.6700')
        ->and($rows['1100']['credit'])->toBe('1016.6700')
        ->and($rows['1100']['closing_balance'])->toBe('0.0000')
        ->and($rows['1020']['closing_balance'])->toBe('1016.6663')
        ->and($rows['4810']['credit'])->toBe('81.3363')
        ->and($rows['4810']['debit'])->toBe('40.6700');

    $period = $this->getJson('/api/v1/general-ledger/financial-statements/trial-balance?fiscal_period_id='.fcPeriod($this->tenant, '2026-06-15')->id)->assertOk()->json('data');
    expect($period['currency'])->toBe('EUR')->and($period['is_balanced'])->toBeTrue();

    // Account ledger of the receivable: EUR figures next to the USD amounts as booked
    $ledger = $this->getJson('/api/v1/general-ledger/reports/account-ledger?account_id='.$receivable->account_id.'&from=2026-06-01&to=2026-06-30')->assertOk()->json('data');
    $first = $ledger['lines'][0];

    expect($ledger['currency'])->toBe('EUR')
        ->and($ledger['closing_balance'])->toBe('0.0000')
        ->and($ledger['transaction_totals'])->toBeNull()
        ->and($first['currency'])->toBe('EUR')
        ->and($first['debit'])->toBe('976.0000')
        ->and($first['transaction_currency'])->toBe('USD')
        ->and($first['transaction_debit'])->toBe('1220.0000')
        ->and($first['transaction_credit'])->toBe('0.0000');

    $income = $this->getJson('/api/v1/general-ledger/reports/income-statement?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    expect($income['currency'])->toBe('EUR')->and($income['totals']['total_revenue'])->toBe('840.6663');

    // VAT register and prima nota export in EUR
    $register = $this->getJson('/api/v1/general-ledger/vat-registers/sales?from=2026-06-01&to=2026-06-30')->assertOk()->json();
    expect($register['totals']['taxable'])->toBe('800.00')->and($register['totals']['tax'])->toBe('176.00');

    $export = $this->getJson('/api/v1/accounting/general-ledger?from_date=2026-06-15&to_date=2026-06-15&per_page=100')->assertOk()->json('data');
    $exported = collect($export['entries'])->firstWhere('document_number', $invoiceEntry->document_number);
    expect($exported['currency'])->toBe('EUR')
        ->and($exported['total_debit'])->toEqual(976.0)
        ->and($export['totals']['balanced'])->toBeTrue();
});

test('a conversion residual of a few ten-thousandths goes to the rounding account, a bigger one is refused', function (): void {
    $receivable = fcAccount($this->tenant, '1100', 'asset', 'debit');
    $sales = fcAccount($this->tenant, '4000', 'revenue', 'credit');
    $posting = app(DoubleEntryPostingServiceInterface::class);
    $base = ['tenant_id' => $this->tenant->id, 'fiscal_period_id' => fcPeriod($this->tenant, '2026-06-15')->id, 'posting_date' => '2026-06-15', 'description' => 'USD'];
    $lines = [
        ['account_id' => $receivable->id, 'debit_amount' => '0.01', 'credit_amount' => '0', 'currency' => 'USD', 'exchange_rate' => '0.333333'],
        ['account_id' => $receivable->id, 'debit_amount' => '0.01', 'credit_amount' => '0', 'currency' => 'USD', 'exchange_rate' => '0.333333'],
        ['account_id' => $sales->id, 'debit_amount' => '0', 'credit_amount' => '0.02', 'currency' => 'USD', 'exchange_rate' => '0.333333'],
    ];

    expect(fn () => $posting->createAndPost($base + ['lines' => $lines]))->toThrow(LedgerPostingException::class, 'rounding');

    DB::table('gl_default_posting_accounts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'role' => 'rounding', 'account_id' => $this->rounding->id, 'created_at' => now(), 'updated_at' => now()]);
    $entry = $posting->createAndPost($base + ['lines' => $lines]);
    $roundingLine = $entry->lines->firstWhere('account_id', $this->rounding->id);

    expect($entry->lines)->toHaveCount(4)
        ->and($roundingLine->currency)->toBe('EUR')
        ->and((string) $roundingLine->functional_debit)->toBe('0.0001')
        ->and($entry->isBalanced())->toBeTrue();

    expect(fn () => $posting->createAndPost($base + ['lines' => [
        ['account_id' => $receivable->id, 'debit_amount' => '100', 'credit_amount' => '0', 'currency' => 'USD', 'exchange_rate' => '0.800000'],
        ['account_id' => $sales->id, 'debit_amount' => '0', 'credit_amount' => '80.05', 'currency' => 'EUR'],
    ]]))->toThrow(LedgerPostingException::class, 'accounting currency EUR');
});

test('a foreign line without a rate takes the rate of the exchange-rate service, and is refused without one', function (): void {
    $bank = fcAccount($this->tenant, '1030', 'asset', 'debit');
    $capital = fcAccount($this->tenant, '3000', 'equity', 'credit');
    $posting = app(DoubleEntryPostingServiceInterface::class);
    $lines = [
        ['account_id' => $bank->id, 'debit_amount' => '500', 'credit_amount' => '0', 'currency' => 'USD'],
        ['account_id' => $capital->id, 'debit_amount' => '0', 'credit_amount' => '500', 'currency' => 'USD'],
    ];

    $entry = $posting->createAndPost(['tenant_id' => $this->tenant->id, 'fiscal_period_id' => fcPeriod($this->tenant, '2026-06-15')->id, 'posting_date' => '2026-06-15', 'description' => 'USD deposit', 'bank_balance_managed' => true, 'lines' => $lines]);
    expect((string) $entry->lines->first()->exchange_rate)->toBe('0.800000')
        ->and((string) $entry->lines->first()->functional_debit)->toBe('400.0000')
        ->and((string) $entry->total_amount)->toBe('400.0000');

    expect(fn () => $posting->createAndPost(['tenant_id' => $this->tenant->id, 'fiscal_period_id' => fcPeriod($this->tenant, '2026-06-15')->id, 'posting_date' => '2026-06-15', 'description' => 'GBP', 'lines' => [
        ['account_id' => $bank->id, 'debit_amount' => '500', 'credit_amount' => '0', 'currency' => 'GBP'],
        ['account_id' => $capital->id, 'debit_amount' => '0', 'credit_amount' => '500', 'currency' => 'GBP'],
    ]]))->toThrow(LedgerPostingException::class, 'No exchange rate to convert GBP');
});

test('the backfill fills the history once, never guesses a rate and keeps posted lines immutable', function (): void {
    $bank = fcAccount($this->tenant, '1020', 'asset', 'debit');
    $sales = fcAccount($this->tenant, '4000', 'revenue', 'credit');

    fcHistoricalEntry($this->tenant, 'LEG-EUR', '2026-03-10', [
        ['account' => $bank, 'debit' => '100.0000', 'credit' => '0', 'currency' => 'EUR', 'rate' => '1.000000', 'functional' => '100.0000'],
        ['account' => $sales, 'debit' => '0', 'credit' => '100.0000', 'currency' => 'EUR', 'rate' => '1.000000', 'functional' => '100.0000'],
    ]);
    $usdEntry = fcHistoricalEntry($this->tenant, 'LEG-USD', '2026-03-11', [
        ['account' => $bank, 'debit' => '1000.0000', 'credit' => '0', 'currency' => 'USD', 'rate' => '0.900000', 'functional' => '900.0000'],
        ['account' => $sales, 'debit' => '0', 'credit' => '1000.0000', 'currency' => 'USD', 'rate' => '0.900000', 'functional' => '900.0000'],
    ]);
    fcHistoricalEntry($this->tenant, 'LEG-NORATE', '2026-03-12', [
        ['account' => $bank, 'debit' => '50.0000', 'credit' => '0', 'currency' => 'CHF', 'rate' => '1.000000', 'functional' => '50.0000'],
        ['account' => $sales, 'debit' => '0', 'credit' => '50.0000', 'currency' => 'CHF', 'rate' => '1.000000', 'functional' => '50.0000'],
    ]);

    $backfill = app(FunctionalCurrencyBackfill::class);
    expect($backfill->run((string) $this->tenant->id))->toBe(['accounting_currency' => 2, 'foreign_currency' => 2, 'unresolved' => 2]);

    $usdLines = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $usdEntry)->orderBy('line_number')->get();
    expect((string) $usdLines[0]->functional_debit)->toBe('900.0000')
        ->and((string) $usdLines[0]->functional_credit)->toBe('0.0000')
        ->and((string) $usdLines[1]->functional_credit)->toBe('900.0000')
        ->and($backfill->unresolved((string) $this->tenant->id)->pluck('entry_number')->unique()->values()->all())->toBe(['LEG-NORATE']);

    expect($backfill->run((string) $this->tenant->id))->toBe(['accounting_currency' => 0, 'foreign_currency' => 0, 'unresolved' => 2]);

    // The trial balance reads the converted USD entry at 900, the unresolved CHF one as booked
    $trial = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    expect(collect($trial['accounts'])->firstWhere('account_code', '1020')['debit'])->toBe('1050.0000');

    expect(fn () => DB::table('journal_lines')->where('id', $usdLines[0]->id)->update(['functional_debit' => '1.0000']))->toThrow(QueryException::class);
});

test('the audit command lists the lines without a rate and the entries not balanced in the accounting currency', function (): void {
    $bank = fcAccount($this->tenant, '1020', 'asset', 'debit');
    $sales = fcAccount($this->tenant, '4000', 'revenue', 'credit');

    fcHistoricalEntry($this->tenant, 'LEG-OK', '2026-03-10', [
        ['account' => $bank, 'debit' => '100.0000', 'credit' => '0', 'currency' => 'EUR', 'rate' => '1.000000', 'functional' => '100.0000'],
        ['account' => $sales, 'debit' => '0', 'credit' => '100.0000', 'currency' => 'EUR', 'rate' => '1.000000', 'functional' => '100.0000'],
    ]);

    $this->artisan('general-ledger:functional-currency-audit', ['--tenant' => (string) $this->tenant->id, '--backfill' => true])
        ->expectsOutputToContain('Every journal line has its amounts in the accounting currency')
        ->assertExitCode(0);

    fcHistoricalEntry($this->tenant, 'LEG-NORATE', '2026-03-12', [
        ['account' => $bank, 'debit' => '50.0000', 'credit' => '0', 'currency' => 'CHF', 'rate' => '1.000000', 'functional' => '50.0000'],
        ['account' => $sales, 'debit' => '0', 'credit' => '50.0000', 'currency' => 'CHF', 'rate' => '1.000000', 'functional' => '50.0000'],
    ]);
    fcHistoricalEntry($this->tenant, 'LEG-ROUND', '2026-03-13', [
        ['account' => $bank, 'debit' => '0.0100', 'credit' => '0', 'currency' => 'USD', 'rate' => '0.333333', 'functional' => '0.0033'],
        ['account' => $bank, 'debit' => '0.0100', 'credit' => '0', 'currency' => 'USD', 'rate' => '0.333333', 'functional' => '0.0033'],
        ['account' => $sales, 'debit' => '0', 'credit' => '0.0200', 'currency' => 'USD', 'rate' => '0.333333', 'functional' => '0.0067'],
    ]);

    $this->artisan('general-ledger:functional-currency-audit', ['--tenant' => (string) $this->tenant->id, '--backfill' => true])
        ->expectsOutputToContain('2 journal line(s) in a foreign currency without a usable exchange rate')
        ->expectsOutputToContain('LEG-NORATE')
        ->expectsOutputToContain('LEG-ROUND')
        ->assertExitCode(1);
});
