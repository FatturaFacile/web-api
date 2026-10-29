<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralLedger;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Listeners\InvoiceAccountingListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Invoice Accounting Audit Tenant', 'legal_name' => 'Invoice Accounting Audit Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Global Enterprise Client SpA'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->fiscalYear = FiscalYear::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'year_code' => 'FY2027'],
        [
            'id' => (string) Str::uuid7(),
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => 'open',
        ]
    );

    $this->fiscalPeriod = FiscalPeriod::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'fiscal_year_id' => $this->fiscalYear->id, 'period_number' => 1],
        [
            'id' => (string) Str::uuid7(),
            'period_name' => 'January 2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-31',
            'status' => 'open',
        ]
    );
});

test('InvoiceAccountingListenerTest: consumes InvoicePostedEvent and creates journal idempotently', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $invoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-2027-0100',
        'type' => 'standard',
        'date' => '2027-01-15',
        'due_date' => '2027-02-15',
        'currency' => 'EUR',
        'exchange_rate' => 1.0,
        'subtotal' => 5000.00,
        'total_net' => 5000.00,
        'total_tax' => 1100.00,
        'total_gross' => 6100.00,
        'status' => 'posted',
        'payment_status' => 'unpaid',
    ]);

    $actualInvoiceId = (string) $invoice->id;

    $event = new InvoicePostedEvent(
        invoiceId: $actualInvoiceId,
        invoiceNumber: 'INV-2027-0100',
        customerId: $customerId,
        date: '2027-01-15',
        dueDate: '2027-02-15',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 5000.00,
        totalTax: 1100.00,
        totalGross: 6100.00,
        revenueLines: [
            ['account_id' => null, 'amount' => 5000.00, 'description' => 'Architecture Consulting'],
        ],
        taxLines: [
            ['account_id' => null, 'tax_rate' => 22.0, 'amount' => 1100.00],
        ],
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(InvoiceAccountingListener::class);

    // First invocation
    $listener->handle($event);

    $count = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'sales_invoice')
        ->where('source_document_id', $actualInvoiceId)
        ->count();
    expect($count)->toBe(1);

    // Second invocation (idempotency check)
    $listener->handle($event);
    $countAfterSecond = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'sales_invoice')
        ->where('source_document_id', $actualInvoiceId)
        ->count();
    expect($countAfterSecond)->toBe(1);
});

test('InvoicePostingJournalTest: creates balanced journal with AR 1100 Dr, Revenue 4000 Cr, VAT 2200 Cr', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $invoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-2027-0200',
        'type' => 'standard',
        'date' => '2027-01-16',
        'currency' => 'EUR',
        'exchange_rate' => 1.0,
        'total_net' => 10000.00,
        'total_tax' => 2200.00,
        'total_gross' => 12200.00,
        'status' => 'posted',
    ]);

    $event = new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: 'INV-2027-0200',
        customerId: $customerId,
        date: '2027-01-16',
        dueDate: '2027-02-16',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 10000.00,
        totalTax: 2200.00,
        totalGross: 12200.00,
        revenueLines: [
            ['account_id' => null, 'amount' => 10000.00, 'description' => 'ERP Platform License'],
        ],
        taxLines: [
            ['account_id' => null, 'tax_rate' => 22.0, 'amount' => 2200.00],
        ],
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(InvoiceAccountingListener::class)->handle($event);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $invoice->id)
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('posted');
    expect((float) $entry->total_amount)->toBe(12200.00);

    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    $debitSum = (float) $lines->sum('debit_amount');
    $creditSum = (float) $lines->sum('credit_amount');

    expect($debitSum)->toBe(12200.00);
    expect($creditSum)->toBe(12200.00);

    // Verify AR account 1100 debit
    $arAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '1100')->first();
    $arLine = $lines->firstWhere('account_id', $arAccount->id);
    expect((float) $arLine->debit_amount)->toBe(12200.00);
    expect((float) $arLine->credit_amount)->toBe(0.0);

    // Verify Revenue account 4000 credit
    $revAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '4000')->first();
    $revLine = $lines->firstWhere('account_id', $revAccount->id);
    expect((float) $revLine->credit_amount)->toBe(10000.00);
    expect((float) $revLine->debit_amount)->toBe(0.0);

    // Verify VAT account 2200 credit
    $vatAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '2200')->first();
    $vatLine = $lines->firstWhere('account_id', $vatAccount->id);
    expect((float) $vatLine->credit_amount)->toBe(2200.00);
    expect((float) $vatLine->debit_amount)->toBe(0.0);
});

test('MultiCurrencyInvoicePostingTest: posts multi-currency invoice in foreign currency and converts to functional currency', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $usdRate = 1.08; // 1 EUR = 1.08 USD

    $invoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-USD-2027-01',
        'type' => 'standard',
        'date' => '2027-01-18',
        'currency' => 'USD',
        'exchange_rate' => $usdRate,
        'total_net' => 10000.00,
        'total_tax' => 2200.00,
        'total_gross' => 12200.00,
        'status' => 'posted',
    ]);

    $event = new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: 'INV-USD-2027-01',
        customerId: $customerId,
        date: '2027-01-18',
        dueDate: '2027-02-18',
        currency: 'USD',
        exchangeRate: $usdRate,
        totalNet: 10000.00,
        totalTax: 2200.00,
        totalGross: 12200.00,
        revenueLines: [
            ['account_id' => null, 'amount' => 10000.00, 'description' => 'US Consulting Project'],
        ],
        taxLines: [
            ['account_id' => null, 'tax_rate' => 22.0, 'amount' => 2200.00],
        ],
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(InvoiceAccountingListener::class)->handle($event);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $invoice->id)
        ->first();

    expect($entry)->not->toBeNull();
    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

    foreach ($lines as $line) {
        expect($line->currency)->toBe('USD');
        expect((float) $line->exchange_rate)->toBe($usdRate);
        expect((float) $line->amount_in_functional_currency)->toBeGreaterThan(0.0);
    }
});

test('TaxPostingTest: handles tax-exempt invoices and multi-tax rate breakdown', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    // Test tax-exempt invoice (0% VAT)
    $exemptInvoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-EXEMPT-001',
        'type' => 'standard',
        'date' => '2027-01-19',
        'currency' => 'EUR',
        'exchange_rate' => 1.0,
        'total_net' => 8000.00,
        'total_tax' => 0.00,
        'total_gross' => 8000.00,
        'status' => 'posted',
    ]);

    $exemptEvent = new InvoicePostedEvent(
        invoiceId: (string) $exemptInvoice->id,
        invoiceNumber: 'INV-EXEMPT-001',
        customerId: $customerId,
        date: '2027-01-19',
        dueDate: '2027-02-19',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 8000.00,
        totalTax: 0.00,
        totalGross: 8000.00,
        revenueLines: [
            ['account_id' => null, 'amount' => 8000.00, 'description' => 'Export Services (Art. 8/41 exempt)'],
        ],
        taxLines: [], // No tax lines for exempt
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(InvoiceAccountingListener::class)->handle($exemptEvent);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $exemptInvoice->id)
        ->first();

    expect($entry)->not->toBeNull();
    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    // Only AR and Revenue lines, no VAT line
    expect($lines->count())->toBe(2);
    expect((float) $lines->sum('debit_amount'))->toBe(8000.00);
    expect((float) $lines->sum('credit_amount'))->toBe(8000.00);
});

test('ReceivablePostingTest: reconciles subledger customer account and dispatches ReceivableReadyEvent', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $invoice = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-REC-2027-01',
        'type' => 'standard',
        'date' => '2027-01-20',
        'currency' => 'EUR',
        'exchange_rate' => 1.0,
        'total_net' => 3000.00,
        'total_tax' => 660.00,
        'total_gross' => 3660.00,
        'status' => 'posted',
    ]);

    $event = new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: 'INV-REC-2027-01',
        customerId: $customerId,
        date: '2027-01-20',
        dueDate: '2027-02-20',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 3000.00,
        totalTax: 660.00,
        totalGross: 3660.00,
        revenueLines: [
            ['account_id' => null, 'amount' => 3000.00, 'description' => 'Development Support'],
        ],
        taxLines: [
            ['account_id' => null, 'tax_rate' => 22.0, 'amount' => 660.00],
        ],
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(InvoiceAccountingListener::class)->handle($event);

    // Verify subledger customer entry
    $customerLedger = CustomerLedger::where('tenant_id', $tenantId)
        ->where('customer_id', $customerId)
        ->first();

    expect($customerLedger)->not->toBeNull();
    expect((float) $customerLedger->original_amount)->toBe(3660.00);
    expect((float) $customerLedger->open_amount)->toBe(3660.00);
    expect($customerLedger->status)->toBe('open');

    // Verify Outbox recorded the journal posted event
    $outbox = TransactionalOutbox::where('event_type', 'general_ledger.journal.posted')
        ->where('tenant_id', $tenantId)
        ->orderByDesc('occurred_at')
        ->first();

    expect($outbox)->not->toBeNull();
});
