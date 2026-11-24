<?php

declare(strict_types=1);

use App\Actions\Sales\RecordPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\SplitPaymentReceivableAdjuster;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Comune di Roma', 'country' => 'IT', 'vat_number' => '01234567897']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);
});

/**
 * A split-payment invoice as the posting wrote it before the fix: the receivable carries the gross.
 */
function legacySplitPaymentInvoice(string $number): SalesInvoice
{
    $tenantId = (string) test()->tenant->id;
    $accounts = app(LedgerAccountResolver::class);
    $invoice = SalesInvoice::create([
        'tenant_id' => $tenantId, 'contact_id' => test()->customer->id, 'invoice_type' => 'standard', 'status' => 'posted', 'invoice_number' => $number,
        'date' => '2027-02-10', 'due_date' => '2027-03-10', 'currency' => 'EUR', 'exchange_rate' => 1, 'jurisdiction' => 'IT', 'split_payment' => true,
        'total_net' => '1000.00', 'total_tax' => '220.00', 'total_gross' => '1220.00', 'receivable_amount' => '1220.00', 'total_paid' => 0, 'payment_status' => 'unpaid', 'posted_at' => now(),
    ]);
    $account = static fn (string $code, string $type, string $balance): string => (string) ChartOfAccount::firstOrCreate(
        ['tenant_id' => $tenantId, 'account_code' => $code],
        ['id' => (string) Str::uuid7(), 'name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type, 'normal_balance' => $balance, 'currency' => 'EUR', 'is_active' => true],
    )->id;

    $entry = app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenantId, 'company_id' => $tenantId,
        'fiscal_period_id' => FiscalPeriod::query()->where('tenant_id', $tenantId)->where('start_date', '<=', '2027-02-10')->where('end_date', '>=', '2027-02-10')->value('id'),
        'entry_number' => 'AR-INV-'.Str::random(6), 'source_document_type' => 'sales_invoice', 'source_document_id' => (string) $invoice->id, 'document_number' => $number,
        'posting_date' => '2027-02-10', 'document_date' => '2027-02-10', 'journal_type' => 'standard', 'description' => "Legacy posting {$number}",
        'lines' => [
            ['account_id' => $accounts->receivables($tenantId)->id, 'debit_amount' => 1220.0, 'credit_amount' => 0.0, 'partner_type' => 'customer', 'partner_id' => (string) test()->customer->id, 'description' => 'AR'],
            ['account_id' => $account('4000', 'revenue', 'credit'), 'debit_amount' => 0.0, 'credit_amount' => 1000.0, 'description' => 'Revenue'],
            ['account_id' => $account('2205', 'liability', 'credit'), 'debit_amount' => 0.0, 'credit_amount' => 220.0, 'vat_tax_amount' => 220.0, 'vat_base_amount' => 1000.0, 'vat_rate_percent' => 22, 'description' => 'VAT'],
        ],
    ]);
    $invoice->update(['gl_journal_entry_id' => $entry->id]);

    return $invoice->refresh();
}

function splitAdjustmentBalance(string $code): float
{
    return round((float) DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('chart_of_accounts.tenant_id', test()->tenant->id)
        ->where('chart_of_accounts.account_code', $code)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) AS balance')
        ->value('balance'), 2);
}

test('the dry run lists the legacy invoices and amounts and posts nothing', function (): void {
    $legacy = legacySplitPaymentInvoice('FT-OLD-1');
    app(InvoiceService::class)->createInvoice([
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => '2027-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'split_payment' => true,
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 22]],
    ]);

    $this->artisan('general-ledger:adjust-split-payment-receivables', ['--tenant' => [$this->tenant->id]])
        ->expectsOutputToContain('1 invoice(s)')
        ->expectsTable(['Invoice', 'Date', 'Receivable posted', 'Amount due', 'Adjustment'], [['FT-OLD-1', '2027-02-10', '1220.00', '1000.00', '220.00']])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(JournalEntry::query()->where('source_document_type', SplitPaymentReceivableAdjuster::Source)->count())->toBe(0)
        ->and(splitAdjustmentBalance('1100'))->toBe(1720.0)
        ->and($legacy->refresh()->receivable_amount)->toBe('1220.00');
});

test('apply posts one balanced adjusting entry per invoice, clears the receivable and is idempotent', function (): void {
    $legacy = legacySplitPaymentInvoice('FT-OLD-2');
    app(RecordPayment::class)->execute(Invoice::query()->findOrFail($legacy->id), ['amount' => '1000.00', 'paid_at' => '2027-03-15', 'payment_method' => 'MP05']);

    expect(splitAdjustmentBalance('1100'))->toBe(220.0);

    $this->artisan('general-ledger:adjust-split-payment-receivables', ['--tenant' => [$this->tenant->id], '--apply' => true, '--date' => '2027-04-01'])->assertSuccessful();

    $entry = JournalEntry::query()->where('source_document_type', SplitPaymentReceivableAdjuster::Source)->where('source_document_id', $legacy->id)->sole();

    expect((float) $entry->lines()->sum('debit_amount'))->toBe((float) $entry->lines()->sum('credit_amount'))
        ->and($entry->posting_date->toDateString())->toBe('2027-04-01')
        ->and(splitAdjustmentBalance('1100'))->toBe(0.0)
        ->and(splitAdjustmentBalance('2205'))->toBe(0.0)
        ->and($legacy->refresh()->receivable_amount)->toBe('1000.00')
        ->and($legacy->payment_status)->toBe('paid')
        ->and(DB::table('customer_ledger')->where('journal_entry_id', $legacy->gl_journal_entry_id)->value('status'))->toBe('settled');

    $this->artisan('general-ledger:adjust-split-payment-receivables', ['--tenant' => [$this->tenant->id], '--apply' => true, '--date' => '2027-04-01'])->assertSuccessful();

    expect(JournalEntry::query()->where('source_document_type', SplitPaymentReceivableAdjuster::Source)->count())->toBe(1)
        ->and(app(SplitPaymentReceivableAdjuster::class)->adjust($legacy, now()->toImmutable()))->toBeNull();
});

test('only the tenant asked for is adjusted', function (): void {
    legacySplitPaymentInvoice('FT-OLD-3');
    $other = Tenant::create(['name' => 'Bianchi', 'legal_name' => 'Bianchi', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->artisan('general-ledger:adjust-split-payment-receivables', ['--tenant' => [$other->id], '--apply' => true, '--date' => '2027-04-01'])->assertSuccessful();

    expect(JournalEntry::query()->where('source_document_type', SplitPaymentReceivableAdjuster::Source)->count())->toBe(0);
});
