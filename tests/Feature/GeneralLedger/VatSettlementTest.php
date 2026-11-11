<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\VatSettlementServiceInterface;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Listeners\CreditNoteAccountingListener;
use Modules\GeneralLedger\Listeners\InvoiceAccountingListener;
use Modules\GeneralLedger\Listeners\ProcurementAccountingListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\VatCode;
use Modules\GeneralLedger\Models\VatRegister;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Models\VatTaxSetting;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, user: User}
 */
function vatTenant(string $country, string $currency = 'EUR', string $role = 'owner'): array
{
    $tenant = Tenant::create([
        'name' => "VAT {$country} ".Str::random(4),
        'legal_name' => "VAT {$country}",
        'country' => $country,
        'default_currency' => $currency,
        'vat_number' => $country === 'IT' ? 'IT01234567890' : 'CHE-123.456.789',
        'fiscal_code' => $country === 'IT' ? '01234567890' : null,
    ]);

    $user = User::create(['name' => 'Accounting', 'email' => Str::random(10).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    vatCalendar($tenant, 2026);
    vatCalendar($tenant, 2027);

    return ['tenant' => $tenant, 'user' => $user];
}

function vatCalendar(Tenant $tenant, int $year): void
{
    app(TenantContext::class)->setTenant($tenant);

    $fiscalYear = FiscalYear::create([
        'tenant_id' => (string) $tenant->id,
        'year_code' => "FY{$year}",
        'start_date' => "{$year}-01-01",
        'end_date' => "{$year}-12-31",
        'status' => 'open',
    ]);

    foreach (range(1, 12) as $month) {
        $first = CarbonImmutable::create($year, $month, 1);

        FiscalPeriod::create([
            'tenant_id' => (string) $tenant->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => $month,
            'period_name' => $first->format('F Y'),
            'start_date' => $first->toDateString(),
            'end_date' => $first->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }
}

function vatAccount(Tenant $tenant, string $code, string $type = 'liability'): ChartOfAccount
{
    app(TenantContext::class)->setTenant($tenant);

    return ChartOfAccount::firstOrCreate(
        ['tenant_id' => (string) $tenant->id, 'account_code' => $code],
        ['name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type, 'normal_balance' => 'credit', 'is_active' => true]
    );
}

function vatPeriodIdOf(Tenant $tenant, string $date): string
{
    app(TenantContext::class)->setTenant($tenant);

    return (string) FiscalPeriod::where('tenant_id', $tenant->id)
        ->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)
        ->value('id');
}

/**
 * @param  array<string, mixed>  $vatLine
 */
function vatSale(Tenant $tenant, string $date, string $net, string $tax, ?string $rate, array $vatLine = [], string $number = ''): JournalEntry
{
    $gross = bcadd($net, $tax, 2);

    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, $date),
        'source_document_type' => 'sales_invoice',
        'source_document_id' => (string) Str::uuid7(),
        'document_number' => $number !== '' ? $number : 'INV-'.Str::random(6),
        'posting_date' => $date,
        'document_date' => $date,
        'lines' => [
            ['account_id' => vatAccount($tenant, '1100', 'asset')->id, 'debit_amount' => $gross, 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '4000', 'revenue')->id, 'debit_amount' => 0, 'credit_amount' => $net],
            ['account_id' => vatAccount($tenant, '2200')->id, 'debit_amount' => 0, 'credit_amount' => $tax, 'vat_tax_amount' => $tax, 'vat_base_amount' => $net, 'vat_rate_percent' => $rate, ...$vatLine],
        ],
    ]);
}

function vatCreditNote(Tenant $tenant, string $date, string $net, string $tax, string $rate): JournalEntry
{
    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, $date),
        'source_document_type' => 'credit_note',
        'source_document_id' => (string) Str::uuid7(),
        'document_number' => 'CN-'.Str::random(6),
        'posting_date' => $date,
        'document_date' => $date,
        'lines' => [
            ['account_id' => vatAccount($tenant, '4090', 'revenue')->id, 'debit_amount' => $net, 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '2200')->id, 'debit_amount' => $tax, 'credit_amount' => 0, 'vat_tax_amount' => $tax, 'vat_base_amount' => $net, 'vat_rate_percent' => $rate],
            ['account_id' => vatAccount($tenant, '1100', 'asset')->id, 'debit_amount' => 0, 'credit_amount' => bcadd($net, $tax, 2)],
        ],
    ]);
}

function vatPurchase(Tenant $tenant, string $date, string $net, string $tax, string $account = '1400', ?string $supplierId = null): JournalEntry
{
    $supplierId ??= (string) Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Fornitore SA', 'type' => 'supplier', 'country' => $tenant->country])->id;

    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, $date),
        'source_document_type' => 'vendor_invoice',
        'source_document_id' => (string) Str::uuid7(),
        'document_number' => 'ACQ-'.Str::random(6),
        'posting_date' => $date,
        'document_date' => $date,
        'lines' => [
            ['account_id' => vatAccount($tenant, '6000', 'expense')->id, 'debit_amount' => $net, 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, $account, 'asset')->id, 'debit_amount' => $tax, 'credit_amount' => 0, 'vat_tax_amount' => $tax, 'vat_base_amount' => $net, 'vat_rate_percent' => '22'],
            ['account_id' => vatAccount($tenant, '2000')->id, 'debit_amount' => 0, 'credit_amount' => bcadd($net, $tax, 2), 'partner_type' => 'supplier', 'partner_id' => $supplierId],
        ],
    ]);
}

function vatService(Tenant $tenant): VatSettlementServiceInterface
{
    app(TenantContext::class)->setTenant($tenant);

    return app(VatSettlementServiceInterface::class);
}

function vatQuarter(Tenant $tenant, int $quarter = 1, int $year = 2026): VatSettlement
{
    return vatService($tenant)->calculate((string) $tenant->id, VatPeriod::of(VatPeriod::QUARTERLY, $year, $quarter));
}

function vatMonth(Tenant $tenant, int $month, int $year = 2026): VatSettlement
{
    return vatService($tenant)->calculate((string) $tenant->id, VatPeriod::of(VatPeriod::MONTHLY, $year, $month));
}

test('the Swiss statement of the desktop case comes from the posted entries', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    vatSale($tenant, '2026-07-10', '1000.00', '81.00', '8.10');
    vatPurchase($tenant, '2026-08-05', '617.28', '50.00');

    $settlement = vatService($tenant)->calculate((string) $tenant->id, VatPeriod::of(VatPeriod::SEMIANNUAL, 2026, 2));

    expect($settlement->country)->toBe('CH')
        ->and($settlement->currency)->toBe('CHF')
        ->and($settlement->year)->toBe(2026)
        ->and($settlement->period)->toBe(2)
        ->and((string) $settlement->turnover)->toBe('1000.00')
        ->and((string) $settlement->output_vat_amount)->toBe('81.0000')
        ->and((string) $settlement->input_vat_amount)->toBe('50.0000')
        ->and((string) $settlement->payable_amount)->toBe('31.00')
        ->and((string) $settlement->interest_amount)->toBe('0.00')
        ->and($settlement->rate_breakdown)->toBe([['rate' => '8.1', 'taxable' => '1000.00', 'tax' => '81.00']])
        ->and($settlement->status)->toBe('calculated');
});

test('the Italian quarter of the desktop case: split payment excluded, credit carried over, 1% interest', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');

    // Previous quarter closed with a credit of 20.00.
    vatCalendar($tenant, 2025);
    vatPurchase($tenant, '2025-12-10', '90.91', '20.00');
    $previous = vatQuarter($tenant, 4, 2025);
    vatService($tenant)->post((string) $tenant->id, (string) $previous->id);

    vatSale($tenant, '2026-01-15', '1000.00', '220.00', '22');
    vatSale($tenant, '2026-02-15', '500.00', '110.00', '22', ['vat_treatment' => 'split_payment']);
    vatPurchase($tenant, '2026-03-01', '454.55', '100.00');

    $settlement = vatQuarter($tenant, 1);

    expect((string) $settlement->output_vat_amount)->toBe('220.0000')
        ->and((string) $settlement->prior_credit_used)->toBe('20.0000')
        ->and((string) $settlement->interest_amount)->toBe('1.00')
        ->and((string) $settlement->payable_amount)->toBe('101.00')
        ->and((string) $settlement->taxable_turnover)->toBe('1500.00')
        ->and((string) $settlement->previous_settlement_id)->toBe((string) $previous->id);
});

test('credit notes in debit reduce the VAT due', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    vatCreditNote($tenant, '2026-01-20', '100.00', '22.00', '22');

    $settlement = vatMonth($tenant, 1);

    expect((string) $settlement->output_vat_amount)->toBe('198.0000')
        ->and((string) $settlement->turnover)->toBe('900.00')
        ->and((string) $settlement->taxable_turnover)->toBe('900.00')
        ->and((string) $settlement->payable_amount)->toBe('198.00');
});

test('supplier credit notes reduce the input VAT', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    vatPurchase($tenant, '2026-01-11', '500.00', '110.00');

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-12'),
        'source_document_type' => 'vendor_invoice',
        'source_document_id' => (string) Str::uuid7(),
        'posting_date' => '2026-01-12',
        'lines' => [
            ['account_id' => vatAccount($tenant, '2000')->id, 'debit_amount' => '122.00', 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '6000', 'expense')->id, 'debit_amount' => 0, 'credit_amount' => '100.00'],
            ['account_id' => vatAccount($tenant, '1400', 'asset')->id, 'debit_amount' => 0, 'credit_amount' => '22.00', 'vat_base_amount' => '100.00', 'vat_rate_percent' => '22'],
        ],
    ]);

    expect((string) vatMonth($tenant, 1)->input_vat_amount)->toBe('88.0000');
});

test('a reversed entry cancels out within the period', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $mistake = vatSale($tenant, '2026-01-11', '500.00', '110.00', '22');

    app(DoubleEntryPostingServiceInterface::class)->reverseJournal((string) $mistake->id, '2026-01-12', 'Wrong customer');

    $settlement = vatMonth($tenant, 1);

    expect((string) $settlement->output_vat_amount)->toBe('220.0000')
        ->and((string) $settlement->turnover)->toBe('1000.00');
});

test('draft entries and other tenants do not count', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    ['tenant' => $other] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    vatSale($other, '2026-01-10', '9000.00', '1980.00', '22');

    app(TenantContext::class)->setTenant($tenant);
    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-12'),
        'status' => 'draft',
        'posting_date' => '2026-01-12',
        'lines' => [
            ['account_id' => vatAccount($tenant, '1100', 'asset')->id, 'debit_amount' => '122.00', 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '2200')->id, 'debit_amount' => 0, 'credit_amount' => '122.00'],
        ],
    ]);

    expect((string) vatMonth($tenant, 1)->output_vat_amount)->toBe('220.0000');
});

test('posting clears the VAT accounts against the tax authority and the settlement is not read as a document', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    vatPurchase($tenant, '2026-01-11', '100.00', '22.00');

    $settlement = vatMonth($tenant, 1);
    $journal = vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect($journal->entry_number)->toBe('VAT-2026-M01')
        ->and($journal->status)->toBe('posted')
        ->and((string) $journal->total_amount)->toBe('220.0000');

    $balance = fn (string $code): string => (string) DB::table('journal_lines')
        ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
        ->where('journal_lines.tenant_id', $tenant->id)
        ->where('chart_of_accounts.account_code', $code)
        ->selectRaw('COALESCE(SUM(credit_amount - debit_amount), 0) as balance')
        ->value('balance');

    expect((float) $balance('2200'))->toBe(0.0)
        ->and((float) $balance('1400'))->toBe(0.0)
        ->and((float) $balance('2210'))->toBe(198.0);

    $settlement->refresh();

    expect($settlement->status)->toBe('posted')
        ->and((string) $settlement->settlement_journal_id)->toBe((string) $journal->id)
        ->and($settlement->posted_at)->not->toBeNull();

    // The next month starts clean: the clearing entry of January falls in January and is excluded anyway.
    expect((string) vatMonth($tenant, 2)->output_vat_amount)->toBe('0.0000');
});

test('a credit carried over stays on the tax authority account and the balances add up', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatPurchase($tenant, '2026-01-11', '1000.00', '220.00');
    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    vatSale($tenant, '2026-02-10', '2000.00', '440.00', '22');
    $february = vatMonth($tenant, 2);

    expect((string) $february->prior_credit_used)->toBe('220.0000')
        ->and((string) $february->payable_amount)->toBe('220.00');

    $journal = vatService($tenant)->post((string) $tenant->id, (string) $february->id);
    $authority = vatAccount($tenant, '2210');
    $line = JournalLine::where('journal_entry_id', $journal->id)->where('account_id', $authority->id)->first();

    // February clears 440.00: the 220.00 credit of January is already a debit on the tax authority account.
    expect((float) $line->credit_amount)->toBe(440.0);

    $authorityBalance = (float) JournalLine::where('tenant_id', $tenant->id)->where('account_id', $authority->id)->selectRaw('SUM(credit_amount - debit_amount) as balance')->value('balance');
    expect($authorityBalance)->toBe(220.0);
});

test('a credit runs down across the following periods', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatPurchase($tenant, '2026-01-11', '1000.00', '220.00');
    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    vatSale($tenant, '2026-02-10', '500.00', '110.00', '22');
    $february = vatMonth($tenant, 2);
    expect((string) $february->payable_amount)->toBe('-110.00')
        ->and((string) $february->net_vat_credit)->toBe('110.0000');
    vatService($tenant)->post((string) $tenant->id, (string) $february->id);

    expect((string) vatMonth($tenant, 3)->prior_credit_used)->toBe('110.0000');
});

test('the quarterly interest is posted to its own account', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');

    $settlement = vatQuarter($tenant, 1);
    $journal = vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect((string) $settlement->interest_amount)->toBe('2.20')
        ->and((string) $settlement->payable_amount)->toBe('222.20');

    $interestLine = JournalLine::where('journal_entry_id', $journal->id)->where('account_id', vatAccount($tenant, '7500', 'expense')->id)->first();
    $authorityLine = JournalLine::where('journal_entry_id', $journal->id)->where('account_id', vatAccount($tenant, '2210')->id)->first();

    expect((float) $interestLine->debit_amount)->toBe(2.2)
        ->and((float) $authorityLine->credit_amount)->toBe(222.2);
});

test('a posted settlement cannot be recalculated nor posted again', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);
    $journal = vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect(fn () => vatMonth($tenant, 1))->toThrow(VatSettlementException::class, 'cannot be recalculated')
        ->and(fn () => vatService($tenant)->post((string) $tenant->id, (string) $settlement->id))->toThrow(VatSettlementException::class, 'already posted');

    $entries = JournalEntry::where('tenant_id', $tenant->id)->where('source_document_type', 'vat_settlement')->count();

    expect($entries)->toBe(1)
        ->and($settlement->refresh()->status)->toBe('posted')
        ->and((string) $settlement->settlement_journal_id)->toBe((string) $journal->id);
});

test('a quarter cannot be calculated over a posted month', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    expect(fn () => vatQuarter($tenant, 1))->toThrow(VatSettlementException::class, 'already covers this period');
});

test('a settlement overtaken by the ledger must be calculated again before posting', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);

    vatSale($tenant, '2026-01-20', '100.00', '22.00', '22');

    expect(fn () => vatService($tenant)->post((string) $tenant->id, (string) $settlement->id))->toThrow(VatSettlementException::class, 'calculate it again');

    $recalculated = vatMonth($tenant, 1);

    expect((string) $recalculated->id)->toBe((string) $settlement->id)
        ->and((string) $recalculated->output_vat_amount)->toBe('242.0000');
});

test('an earlier period cannot be posted after a later one', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $january = vatMonth($tenant, 1);
    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 2)->id);

    expect(fn () => vatService($tenant)->post((string) $tenant->id, (string) $january->id))->toThrow(VatSettlementException::class, 'later VAT settlement');
});

test('the figures of a posted settlement are immutable in the model and in the database', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);
    vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);
    $settlement->refresh();

    expect(fn () => $settlement->update(['output_vat_amount' => '1.00']))->toThrow(VatSettlementException::class, 'immutable')
        ->and(fn () => $settlement->delete())->toThrow(VatSettlementException::class, 'immutable');
});

test('the database refuses to change a posted settlement behind the model', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);
    vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect(fn () => DB::table('vat_settlements')->where('id', $settlement->id)->update(['status' => 'calculated']))
        ->toThrow(QueryException::class, 'immutable');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'The trigger exists on PostgreSQL only.');

test('the journal entry of a settlement cannot be reversed', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $journal = vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    expect(fn () => app(DoubleEntryPostingServiceInterface::class)->reverseJournal((string) $journal->id, '2026-02-01'))
        ->toThrow(InvalidArgumentException::class, 'cannot be reversed');
});

test('a period without VAT is posted without an entry and still carries the credit', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatPurchase($tenant, '2026-01-11', '1000.00', '220.00');
    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    $february = vatMonth($tenant, 2);
    expect(vatService($tenant)->post((string) $tenant->id, (string) $february->id))->toBeNull()
        ->and($february->refresh()->status)->toBe('posted')
        ->and((string) vatMonth($tenant, 3)->prior_credit_used)->toBe('220.0000');
});

test('a debt below the minimum is not paid and moves to the next period', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '100.00', '22.00', '22');
    $january = vatMonth($tenant, 1);

    expect($january->below_minimum_payment)->toBeTrue();
    vatService($tenant)->post((string) $tenant->id, (string) $january->id);

    expect(fn () => vatService($tenant)->markPaid((string) $tenant->id, (string) $january->id, '2026-02-16'))->toThrow(VatSettlementException::class, 'no amount to pay');

    vatSale($tenant, '2026-02-10', '400.00', '88.00', '22');
    $february = vatMonth($tenant, 2);

    expect((string) $february->previous_debit)->toBe('22.00')
        ->and((string) $february->payable_amount)->toBe('110.00')
        ->and($february->below_minimum_payment)->toBeFalse();
});

test('paying registers the entry against the bank and closes the settlement', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);

    expect(fn () => vatService($tenant)->markPaid((string) $tenant->id, (string) $settlement->id, '2026-02-16'))->toThrow(VatSettlementException::class, 'Only a posted');

    vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);
    $bank = vatAccount($tenant, '1020', 'asset');
    $paid = vatService($tenant)->markPaid((string) $tenant->id, (string) $settlement->id, '2026-02-16', (string) $bank->id);

    expect($paid->status)->toBe('paid')
        ->and($paid->is_paid)->toBeTrue()
        ->and($paid->payment_date->toDateString())->toBe('2026-02-16')
        ->and((float) JournalLine::where('journal_entry_id', $paid->payment_journal_id)->where('account_id', $bank->id)->value('credit_amount'))->toBe(220.0)
        ->and(fn () => vatService($tenant)->markPaid((string) $tenant->id, (string) $settlement->id, '2026-02-17'))->toThrow(VatSettlementException::class, 'Only a posted')
        ->and(fn () => $paid->update(['payment_date' => '2026-03-01']))->toThrow(VatSettlementException::class, 'immutable');
});

test('the accounts are configurable and include those of the VAT codes', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    $output = vatAccount($tenant, '2201');
    $reduced = vatAccount($tenant, '2202');
    $input = vatAccount($tenant, '1170', 'asset');
    $authority = vatAccount($tenant, '2206');

    VatTaxSetting::create([
        'tenant_id' => (string) $tenant->id,
        'output_vat_account_ids' => [(string) $output->id],
        'input_vat_account_ids' => [(string) $input->id],
        'settlement_account_id' => (string) $authority->id,
    ]);
    VatCode::create([
        'tenant_id' => (string) $tenant->id,
        'code' => 'CH26',
        'description' => 'Reduced',
        'rate' => '2.60',
        'tax_rate_percent' => '2.60',
        'country' => 'CH',
        'country_iso' => 'CH',
        'gl_sales_tax_account_id' => (string) $reduced->id,
    ]);

    $posting = app(DoubleEntryPostingServiceInterface::class);
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-10'),
        'source_document_type' => 'sales_invoice',
        'posting_date' => '2026-01-10',
        'lines' => [
            ['account_id' => vatAccount($tenant, '1100', 'asset')->id, 'debit_amount' => '1107.10', 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '4000', 'revenue')->id, 'debit_amount' => 0, 'credit_amount' => '1000.00'],
            ['account_id' => $output->id, 'debit_amount' => 0, 'credit_amount' => '81.00', 'vat_base_amount' => '1000.00', 'vat_rate_percent' => '8.1'],
            ['account_id' => $reduced->id, 'debit_amount' => 0, 'credit_amount' => '26.10', 'vat_base_amount' => '1003.85', 'vat_rate_percent' => '2.6'],
        ],
    ]);
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-11'),
        'posting_date' => '2026-01-11',
        'lines' => [
            ['account_id' => $input->id, 'debit_amount' => '10.00', 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '2000')->id, 'debit_amount' => 0, 'credit_amount' => '10.00'],
        ],
    ]);

    $settlement = vatQuarter($tenant, 1);
    $journal = vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect((string) $settlement->output_vat_amount)->toBe('107.1000')
        ->and((string) $settlement->input_vat_amount)->toBe('10.0000')
        ->and((float) JournalLine::where('journal_entry_id', $journal->id)->where('account_id', $reduced->id)->value('debit_amount'))->toBe(26.1)
        ->and((float) JournalLine::where('journal_entry_id', $journal->id)->where('account_id', $authority->id)->value('credit_amount'))->toBe(97.1);
});

test('a foreign currency document is settled in the accounting currency', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-10'),
        'source_document_type' => 'sales_invoice',
        'posting_date' => '2026-01-10',
        'lines' => [
            ['account_id' => vatAccount($tenant, '1100', 'asset')->id, 'debit_amount' => '1081.00', 'credit_amount' => 0, 'currency' => 'EUR', 'exchange_rate' => '0.95'],
            ['account_id' => vatAccount($tenant, '4000', 'revenue')->id, 'debit_amount' => 0, 'credit_amount' => '1000.00', 'currency' => 'EUR', 'exchange_rate' => '0.95'],
            ['account_id' => vatAccount($tenant, '2200')->id, 'debit_amount' => 0, 'credit_amount' => '81.00', 'currency' => 'EUR', 'exchange_rate' => '0.95', 'vat_base_amount' => '1000.00', 'vat_rate_percent' => '8.1'],
        ],
    ]);

    $settlement = vatQuarter($tenant, 1);

    expect((string) $settlement->output_vat_amount)->toBe('76.9500')
        ->and((string) $settlement->turnover)->toBe('950.00');
});

test('the invoice listener records the real rate, the taxable and the split payment apart', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    app(TenantContext::class)->setTenant($tenant);

    $invoice = SalesInvoice::create([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => (string) Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Comune di Roma', 'type' => 'client', 'country' => 'IT'])->id,
        'invoice_number' => 'FT-1',
        'date' => '2026-01-15',
        'currency' => 'EUR',
        'split_payment' => true,
        'total_net' => 500,
        'total_tax' => 110,
        'total_gross' => 610,
        'status' => 'posted',
    ]);

    app(InvoiceAccountingListener::class)->handle(new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: 'FT-1',
        customerId: (string) $invoice->contact_id,
        date: '2026-01-15',
        dueDate: null,
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 500.0,
        totalTax: 110.0,
        totalGross: 610.0,
        revenueLines: [['line_id' => 'L1', 'account_id' => null, 'amount' => 500.0]],
        taxLines: [['line_id' => 'L1', 'account_id' => null, 'tax_rate' => 22.0, 'amount' => 110.0]],
        glJournalEntryId: null,
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    $line = JournalLine::where('tenant_id', $tenant->id)->where('vat_treatment', 'split_payment')->first();

    expect($line)->not->toBeNull()
        ->and((string) $line->vat_rate_percent)->toBe('22.00')
        ->and((string) $line->vat_base_amount)->toBe('500.0000')
        ->and(ChartOfAccount::find($line->account_id)->account_code)->toBe('2205');

    $settlement = vatMonth($tenant, 1);

    expect((string) $settlement->output_vat_amount)->toBe('0.0000')
        ->and((string) $settlement->taxable_turnover)->toBe('500.00');
});

test('credit notes of a Swiss tenant use its currency and the real rate of the line', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    app(TenantContext::class)->setTenant($tenant);

    $customer = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Kunde AG', 'type' => 'client', 'country' => 'CH']);
    $creditNote = SalesInvoice::create([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => (string) $customer->id,
        'invoice_number' => 'CN-1',
        'type' => 'credit_note',
        'date' => '2026-01-20',
        'currency' => 'CHF',
        'total_gross' => 108.10,
        'status' => 'posted',
    ]);

    app(CreditNoteAccountingListener::class)->handle(new CreditNoteIssuedEvent(
        creditNoteId: (string) $creditNote->id,
        creditNoteNumber: 'CN-1',
        originalInvoiceId: null,
        customerId: (string) $customer->id,
        creditedAmount: 108.10,
        adjustmentLines: [['quantity' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 8.1]],
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    $entry = JournalEntry::where('source_document_id', $creditNote->id)->first();
    $vatLine = JournalLine::where('journal_entry_id', $entry->id)->where('vat_tax_amount', '>', 0)->first();

    expect($entry->document_number)->toBe('CN-1')
        ->and($entry->posting_date->toDateString())->toBe('2026-01-20')
        ->and($vatLine->currency)->toBe('CHF')
        ->and((string) $vatLine->vat_rate_percent)->toBe('8.10')
        ->and((float) $vatLine->debit_amount)->toBe(8.1);

    expect(JournalLine::where('journal_entry_id', $entry->id)->pluck('currency')->unique()->all())->toBe(['CHF'])
        ->and(ChartOfAccount::where('tenant_id', $tenant->id)->where('account_code', '4090')->value('currency'))->toBe('CHF');
});

test('a credit note without a stored document falls back to the currency of the tenant', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    app(TenantContext::class)->setTenant($tenant);

    app(CreditNoteAccountingListener::class)->handle(new CreditNoteIssuedEvent(
        creditNoteId: (string) Str::uuid7(),
        creditNoteNumber: 'CN-5',
        originalInvoiceId: null,
        customerId: (string) Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Kunde AG', 'type' => 'client', 'country' => 'CH'])->id,
        creditedAmount: 108.10,
        adjustmentLines: [['quantity' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 8.1]],
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    expect(JournalLine::where('tenant_id', $tenant->id)->pluck('currency')->unique()->all())->toBe(['CHF']);
});

test('a credit note with several rates splits the VAT line by rate', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    app(TenantContext::class)->setTenant($tenant);

    $customer = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Rossi', 'type' => 'client', 'country' => 'IT']);
    $event = new CreditNoteIssuedEvent(
        creditNoteId: (string) Str::uuid7(),
        creditNoteNumber: 'CN-2',
        originalInvoiceId: null,
        customerId: (string) $customer->id,
        creditedAmount: 232.0,
        adjustmentLines: [
            ['quantity' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 22.0],
            ['quantity' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 10.0],
        ],
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    );

    app(CreditNoteAccountingListener::class)->handle($event);

    $rates = JournalLine::where('tenant_id', $tenant->id)->whereNotNull('vat_rate_percent')->orderByDesc('vat_rate_percent')->get();

    expect($rates->pluck('vat_rate_percent')->map(fn ($rate) => (string) $rate)->all())->toBe(['22.00', '10.00'])
        ->and((float) $rates[0]->debit_amount)->toBe(22.0)
        ->and((float) $rates[1]->debit_amount)->toBe(10.0);
});

test('a credit note that states no rate is refused rather than assumed at 22%', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    app(TenantContext::class)->setTenant($tenant);

    expect(fn () => app(CreditNoteAccountingListener::class)->handle(new CreditNoteIssuedEvent(
        creditNoteId: (string) Str::uuid7(),
        creditNoteNumber: 'CN-3',
        originalInvoiceId: null,
        customerId: (string) Str::uuid7(),
        creditedAmount: 108.10,
        adjustmentLines: [],
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    )))->toThrow(InvalidArgumentException::class, 'does not state its VAT rate');
});

test('without lines the rate comes from the original invoice', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    app(TenantContext::class)->setTenant($tenant);

    $customer = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Kunde AG', 'type' => 'client', 'country' => 'CH']);
    $original = SalesInvoice::create([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => (string) $customer->id,
        'invoice_number' => 'FT-9',
        'date' => '2026-01-10',
        'total_net' => 1000,
        'total_tax' => 81,
        'total_gross' => 1081,
        'status' => 'posted',
    ]);

    app(CreditNoteAccountingListener::class)->handle(new CreditNoteIssuedEvent(
        creditNoteId: (string) Str::uuid7(),
        creditNoteNumber: 'CN-4',
        originalInvoiceId: (string) $original->id,
        customerId: (string) $customer->id,
        creditedAmount: 108.10,
        adjustmentLines: [],
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    expect((float) JournalLine::where('tenant_id', $tenant->id)->where('vat_tax_amount', '>', 0)->value('debit_amount'))->toBe(8.1);
});

test('registers show sales and purchases with stable protocol numbers after posting', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22', number: 'FT-1');
    vatCreditNote($tenant, '2026-01-20', '100.00', '22.00', '22');
    vatPurchase($tenant, '2026-01-11', '100.00', '22.00');

    Sanctum::actingAs($user);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $draft = $this->withHeaders($headers)->getJson('/api/v1/general-ledger/vat-registers/sales?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($draft->json('data.0.protocol_number'))->toBeNull()
        ->and($draft->json('data.0.document_number'))->toBe('FT-1')
        ->and($draft->json('data.0.taxable_amount'))->toBe('1000.00')
        ->and($draft->json('data.1.vat_amount'))->toBe('-22.00')
        ->and($draft->json('totals.tax'))->toBe('198.00');

    vatService($tenant)->post((string) $tenant->id, (string) vatMonth($tenant, 1)->id);

    $sales = $this->withHeaders($headers)->getJson('/api/v1/general-ledger/vat-registers/sales?from=2026-01-01&to=2026-01-31')->assertOk();
    $purchases = $this->withHeaders($headers)->getJson('/api/v1/general-ledger/vat-registers/purchases?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($sales->json('data.0.protocol_number'))->toBe(1)
        ->and($sales->json('data.1.protocol_number'))->toBe(2)
        ->and($purchases->json('data.0.protocol_number'))->toBe(1)
        ->and($purchases->json('data.0.partner_name'))->toBe('Fornitore SA')
        ->and(VatRegister::where('tenant_id', $tenant->id)->where('register_type', 'sales')->value('last_protocol_number'))->toBe(2);

    $csv = $this->withHeaders($headers)->get('/api/v1/general-ledger/vat-registers/sales?from=2026-01-01&to=2026-01-31&format=csv')->assertOk();

    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('1;2026-01-10;2026-01-10;FT-1;sales_invoice;;1000,00;22;220,00;');
});

test('the API runs the whole Italian flow and exports LIPE and F24', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '10000.00', '2200.00', '22');
    vatPurchase($tenant, '2026-02-10', '4000.00', '880.00');

    Sanctum::actingAs($user);
    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);

    $calculated = $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])->assertOk();
    $id = $calculated->json('data.id');

    expect($calculated->json('data.interest_amount'))->toBe('13.20')
        ->and($calculated->json('data.payable_amount'))->toBe('1333.20')
        ->and($calculated->json('data.settlement_period'))->toBe('2026-Q1')
        ->and($calculated->json('data.status'))->toBe('calculated');

    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/post")->assertOk()->assertJsonPath('data.entry_number', 'VAT-2026-Q1');
    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/post")->assertStatus(409)->assertJsonPath('error_code', 'VAT_SETTLEMENT_CONFLICT');
    $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])->assertStatus(409);

    $lipe = $this->get("/api/v1/general-ledger/tax-settlements/{$id}/lipe")->assertOk();

    expect($lipe->headers->get('Content-Disposition'))->toContain('IT01234567890_LI_26T01.xml')
        ->and($lipe->getContent())->toContain('<iv:ImportoDaVersare>1333,20</iv:ImportoDaVersare>')
        ->and($lipe->getContent())->toContain('<iv:TotaleOperazioniAttive>10000,00</iv:TotaleOperazioniAttive>')
        ->and($lipe->getContent())->toContain('<iv:TotaleOperazioniPassive>4000,00</iv:TotaleOperazioniPassive>');

    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}/f24")->assertOk()
        ->assertJsonPath('data.tax_code', '6031')
        ->assertJsonPath('data.reference_year', 2026)
        ->assertJsonPath('data.amount', '1333.20')
        ->assertJsonPath('data.payment_due', true)
        ->assertJsonPath('data.summary', 'F24 sezione Erario: codice tributo 6031, anno 2026, importo 1333,20.');

    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/pay", ['payment_date' => '2026-05-16'])->assertOk()->assertJsonPath('data.status', 'paid');
    $this->getJson('/api/v1/general-ledger/tax-settlements?status=paid')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}/statement")->assertStatus(422);
});

test('the existing calculate route still takes a fiscal period and fills the mandatory columns', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');

    Sanctum::actingAs($user);

    $response = $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id])
        ->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-10')])
        ->assertOk()
        ->assertJsonPath('data.status', 'calculated')
        ->assertJsonPath('data.output_vat_amount', 220)
        ->assertJsonPath('data.net_vat_payable', 198 + 22);

    $row = DB::table('vat_settlements')->where('id', $response->json('data.id'))->first();

    expect((int) $row->year)->toBe(2026)
        ->and((int) $row->period)->toBe(1)
        ->and($row->period_type)->toBe('monthly');
});

test('the settings periodicity is the default of a calculation and accounts must be of the tenant', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('CH', 'CHF');
    ['tenant' => $other] = vatTenant('CH', 'CHF');
    $foreignAccount = vatAccount($other, '2200');
    $ownAccount = vatAccount($tenant, '2200');

    Sanctum::actingAs($user);
    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);

    $this->putJson('/api/v1/general-ledger/tax-settings', ['output_vat_account_ids' => [(string) $foreignAccount->id]])->assertUnprocessable();
    $this->putJson('/api/v1/general-ledger/tax-settings', ['periodicity' => 'semiannual', 'output_vat_account_ids' => [(string) $ownAccount->id]])->assertOk();
    $this->getJson('/api/v1/general-ledger/tax-settings')->assertOk()->assertJsonPath('data.periodicity', 'semiannual');

    $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['year' => 2026, 'period' => 2])
        ->assertOk()
        ->assertJsonPath('data.period_type', 'semiannual')
        ->assertJsonPath('data.period_from', '2026-07-01');
});

test('the Swiss statement is exported as CSV and PDF, LIPE and F24 are refused', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('CH', 'CHF');
    vatSale($tenant, '2026-01-10', '1000.00', '81.00', '8.1');

    Sanctum::actingAs($user);
    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);

    $id = $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])->json('data.id');

    $csv = $this->get("/api/v1/general-ledger/tax-settlements/{$id}/statement")->assertOk();

    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('mwst-2026-Q1.csv')
        ->and($csv->getContent())->toContain("200;Cifra d'affari totale;;;1000,00")
        ->and($csv->getContent())->toContain("303;Imposta all'aliquota 8.1%;1000,00;8.1;81,00")
        ->and($csv->getContent())->toContain("500;Importo dovuto all'AFC;;;81,00");

    $pdf = $this->get("/api/v1/general-ledger/tax-settlements/{$id}/statement?format=pdf")->assertOk();

    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();

    $this->get("/api/v1/general-ledger/tax-settlements/{$id}/lipe")->assertStatus(422);
    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}/f24")->assertStatus(422);
});

test('the Italian half year does not exist', function (): void {
    ['tenant' => $italian] = vatTenant('IT');

    expect(fn () => vatService($italian)->calculate((string) $italian->id, VatPeriod::of(VatPeriod::SEMIANNUAL, 2026, 1)))->toThrow(VatSettlementException::class, 'monthly or quarterly');
});

test('calculate needs a period', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT');
    Sanctum::actingAs($user);

    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id])
        ->postJson('/api/v1/general-ledger/tax-settlements/calculate', [])
        ->assertUnprocessable();
});

test('owner, admin, accountant and the accounting permission are allowed, a member is not', function (string $role, ?array $permissions, int $status): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT', role: $role);

    if ($permissions !== null) {
        $user->tenants()->updateExistingPivot($tenant->id, ['permissions' => json_encode($permissions)]);
    }

    Sanctum::actingAs($user);

    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id])
        ->getJson('/api/v1/general-ledger/tax-settlements')
        ->assertStatus($status);
})->with([
    'owner' => ['owner', null, 200],
    'admin' => ['admin', null, 200],
    'accountant' => ['accountant', null, 200],
    'member with gl.*' => ['member', ['gl.*'], 200],
    'member with the posting permission' => ['member', ['gl.journals.post'], 200],
    'member' => ['member', null, 403],
    'member with another grant' => ['member', ['crm.*'], 403],
]);

test('every action is forbidden to a member', function (): void {
    ['tenant' => $tenant, 'user' => $owner] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);

    $member = User::create(['name' => 'Member', 'email' => Str::random(10).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $member->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    Sanctum::actingAs($member);
    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);

    $id = (string) $settlement->id;

    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}")->assertForbidden();
    $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['year' => 2026, 'period' => 1])->assertForbidden();
    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/post")->assertForbidden();
    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/pay", ['payment_date' => '2026-02-16'])->assertForbidden();
    $this->get("/api/v1/general-ledger/tax-settlements/{$id}/lipe")->assertForbidden();
    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}/f24")->assertForbidden();
    $this->get("/api/v1/general-ledger/tax-settlements/{$id}/statement")->assertForbidden();
    $this->getJson('/api/v1/general-ledger/tax-settings')->assertForbidden();
    $this->putJson('/api/v1/general-ledger/tax-settings', [])->assertForbidden();
    $this->getJson('/api/v1/general-ledger/vat-registers/sales?from=2026-01-01&to=2026-01-31')->assertForbidden();
});

test('a tenant reaches neither the settlements nor the fiscal periods of another', function (): void {
    ['tenant' => $tenant, 'user' => $user] = vatTenant('IT');
    ['tenant' => $other] = vatTenant('IT');
    vatSale($other, '2026-01-10', '9000.00', '1980.00', '22', number: 'SECRET-1');
    $foreign = vatMonth($other, 1);

    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);

    $id = (string) $foreign->id;

    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}")->assertNotFound();
    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/post")->assertNotFound();
    $this->postJson("/api/v1/general-ledger/tax-settlements/{$id}/pay", ['payment_date' => '2026-02-16'])->assertNotFound();
    $this->get("/api/v1/general-ledger/tax-settlements/{$id}/lipe")->assertNotFound();
    $this->getJson("/api/v1/general-ledger/tax-settlements/{$id}/f24")->assertNotFound();
    $this->postJson('/api/v1/general-ledger/tax-settlements/calculate', ['fiscal_period_id' => vatPeriodIdOf($other, '2026-01-10')])->assertNotFound();
    $this->getJson('/api/v1/general-ledger/tax-settlements')->assertOk()->assertJsonCount(0, 'data');

    $register = $this->getJson('/api/v1/general-ledger/vat-registers/sales?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($register->json('data'))->toBe([])
        ->and($foreign->refresh()->status)->toBe('calculated');
});

test('a user acting for a tenant they do not belong to is refused', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    ['user' => $stranger] = vatTenant('IT');

    Sanctum::actingAs($stranger);

    $response = $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id])->getJson('/api/v1/general-ledger/tax-settlements');

    expect($response->status())->toBeIn([403, 404]);
});

test('the database refuses a second ledger entry for the same settlement', function (): void {
    ['tenant' => $tenant] = vatTenant('IT');
    vatSale($tenant, '2026-01-10', '1000.00', '220.00', '22');
    $settlement = vatMonth($tenant, 1);
    vatService($tenant)->post((string) $tenant->id, (string) $settlement->id);

    expect(fn () => app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => vatPeriodIdOf($tenant, '2026-01-31'),
        'source_document_type' => 'vat_settlement',
        'source_document_id' => (string) $settlement->id,
        'journal_type' => 'adjusting',
        'lines' => [
            ['account_id' => vatAccount($tenant, '2200')->id, 'debit_amount' => '1.00', 'credit_amount' => 0],
            ['account_id' => vatAccount($tenant, '2210')->id, 'debit_amount' => 0, 'credit_amount' => '1.00'],
        ],
    ]))->toThrow(QueryException::class);
});

test('a supplier liability of a Swiss tenant reaches the return with its taxable and rate', function (): void {
    ['tenant' => $tenant] = vatTenant('CH', 'CHF');
    app(TenantContext::class)->setTenant($tenant);

    app(ProcurementAccountingListener::class)->handlePurchaseLiabilityReady(new PurchaseLiabilityReadyEvent(
        matchLogId: (string) Str::uuid7(),
        purchaseOrderId: (string) Str::uuid7(),
        supplierId: (string) Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Lieferant AG', 'type' => 'supplier', 'country' => 'CH'])->id,
        netAmount: 1000.0,
        taxAmount: 81.0,
        grossAmount: 1081.0,
        currency: 'CHF',
        vendorInvoiceNumber: 'L-77',
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    $line = JournalLine::where('tenant_id', $tenant->id)->where('debit_amount', 81)->first();
    $today = CarbonImmutable::parse($line->posting_date);

    expect($line->currency)->toBe('CHF')
        ->and((string) $line->vat_rate_percent)->toBe('8.10')
        ->and((string) $line->vat_base_amount)->toBe('1000.0000');

    if ($today->year < 2026 || $today->year > 2027) {
        vatCalendar($tenant, $today->year);
    }

    $settlement = vatService($tenant)->calculate((string) $tenant->id, VatPeriod::of(VatPeriod::MONTHLY, $today->year, $today->month));

    expect((string) $settlement->input_vat_amount)->toBe('81.0000')
        ->and((string) $settlement->passive_operations)->toBe('1000.00');
});
