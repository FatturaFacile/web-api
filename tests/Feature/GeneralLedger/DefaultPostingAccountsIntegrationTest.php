<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Listeners\InvoiceAccountingListener;
use Modules\GeneralLedger\Listeners\ProcurementAccountingListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;

uses(RefreshDatabase::class);

/**
 * The posting listeners read the default posting accounts of the tenant (General Ledger settings) before the codes they
 * always used, and build their entries from decimal strings only.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Posting Accounts Srl', 'legal_name' => 'Posting Accounts Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->customer = Contact::create(['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Cliente Spa', 'type' => 'client', 'country' => 'IT']);

    $year = FiscalYear::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'year_code' => 'FY2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'open']);
    FiscalPeriod::create(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $this->tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_name' => 'January 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31', 'status' => 'open']);
});

function dpaAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id, 'company_id' => null, 'account_code' => $code, 'account_type' => $type, 'account_class' => $type,
        'normal_balance' => $normal, 'is_active' => true,
    ]);
}

function dpaConfigure(Tenant $tenant, PostingAccountRole $role, ChartOfAccount $account): void
{
    DefaultPostingAccount::create(['tenant_id' => $tenant->id, 'role' => $role->value, 'account_id' => $account->id]);
}

function dpaPostInvoice(Tenant $tenant, Contact $customer, string $number): JournalEntry
{
    $invoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'contact_id' => (string) $customer->id, 'invoice_number' => $number,
        'type' => 'standard', 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'exchange_rate' => '1',
        'subtotal' => '100.10', 'discount_amount' => '0', 'total_net' => '100.10', 'total_tax' => '22.02', 'total_gross' => '122.12',
        'status' => 'posted', 'payment_status' => 'unpaid',
    ]);

    app(InvoiceAccountingListener::class)->handle(new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: $number,
        customerId: (string) $customer->id,
        date: '2027-01-15',
        dueDate: '2027-02-15',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 100.10,
        totalTax: 22.02,
        totalGross: 122.12,
        revenueLines: [['line_id' => 'L1', 'account_id' => null, 'amount' => '100.10', 'description' => 'Consulting']],
        taxLines: [['line_id' => 'L1', 'account_id' => null, 'tax_rate' => '22.00', 'amount' => '22.02']],
        glJournalEntryId: null,
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
    ));

    return JournalEntry::query()->where('source_document_type', 'sales_invoice')->where('source_document_id', $invoice->id)->firstOrFail();
}

test('an invoice posts to the receivables, revenue and output VAT accounts configured in the ledger settings', function (): void {
    $receivables = dpaAccount($this->tenant, '1105', 'asset', 'debit');
    $revenue = dpaAccount($this->tenant, '4010', 'revenue', 'credit');
    $outputVat = dpaAccount($this->tenant, '2210', 'liability', 'credit');
    dpaConfigure($this->tenant, PostingAccountRole::Receivables, $receivables);
    dpaConfigure($this->tenant, PostingAccountRole::SalesRevenue, $revenue);
    dpaConfigure($this->tenant, PostingAccountRole::OutputVat, $outputVat);

    $entry = dpaPostInvoice($this->tenant, $this->customer, 'INV-CFG-1');
    $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get()->keyBy('account_id');

    expect($lines->keys()->sort()->values()->all())->toBe(collect([$receivables->id, $revenue->id, $outputVat->id])->sort()->values()->all())
        ->and((string) $lines[$receivables->id]->debit_amount)->toBe('122.1200')
        ->and($lines[$receivables->id]->partner_id)->toBe((string) $this->customer->id)
        ->and((string) $lines[$revenue->id]->credit_amount)->toBe('100.1000')
        ->and((string) $lines[$outputVat->id]->credit_amount)->toBe('22.0200')
        ->and((string) $lines[$outputVat->id]->vat_rate_percent)->toBe('22.00')
        ->and($receivables->fresh()->reconciliation_type)->toBe('customer')
        ->and(ChartOfAccount::query()->whereIn('account_code', ['1100', '4000', '2200'])->exists())->toBeFalse()
        // The entry total reaches the API as an exact decimal string
        ->and((new JournalEntryResource($entry))->toArray(Request::create('/'))['total_amount'])->toBe('122.1200');
});

test('without configured accounts an invoice keeps posting to the historical codes', function (): void {
    $entry = dpaPostInvoice($this->tenant, $this->customer, 'INV-DEF-1');
    $codes = JournalLine::query()->where('journal_entry_id', $entry->id)->get()
        ->map(static fn (JournalLine $line): string => (string) ChartOfAccount::query()->whereKey($line->account_id)->value('account_code'))
        ->sort()->values()->all();

    expect($codes)->toBe(['1100', '2200', '4000']);
});

test('a supplier invoice without order posts to the configured purchase expense, input VAT and payables accounts', function (): void {
    $expense = dpaAccount($this->tenant, '6010', 'expense', 'debit');
    $inputVat = dpaAccount($this->tenant, '1410', 'asset', 'debit');
    $payables = dpaAccount($this->tenant, '2005', 'liability', 'credit');
    dpaConfigure($this->tenant, PostingAccountRole::PurchaseExpense, $expense);
    dpaConfigure($this->tenant, PostingAccountRole::InputVat, $inputVat);
    dpaConfigure($this->tenant, PostingAccountRole::Payables, $payables);
    $supplier = Contact::create(['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Fornitore Srl', 'type' => 'supplier', 'country' => 'IT']);
    $matchLogId = (string) Str::uuid7();

    app(ProcurementAccountingListener::class)->handlePurchaseLiabilityReady(new PurchaseLiabilityReadyEvent(
        matchLogId: $matchLogId,
        purchaseOrderId: '',
        supplierId: (string) $supplier->id,
        netAmount: 200.10,
        taxAmount: 44.02,
        grossAmount: 244.12,
        currency: 'EUR',
        vendorInvoiceNumber: 'F-9',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        documentDate: '2027-01-20',
    ));

    $lines = JournalLine::query()->whereIn('journal_entry_id', JournalEntry::query()->where('source_document_id', $matchLogId)->pluck('id'))->get()->keyBy('account_id');

    expect((string) $lines[$expense->id]->debit_amount)->toBe('200.1000')
        ->and((string) $lines[$inputVat->id]->debit_amount)->toBe('44.0200')
        ->and((string) $lines[$payables->id]->credit_amount)->toBe('244.1200')
        ->and($payables->fresh()->reconciliation_type)->toBe('supplier')
        ->and(ChartOfAccount::query()->whereIn('account_code', ['6000', '1400', '2000'])->exists())->toBeFalse();
});
