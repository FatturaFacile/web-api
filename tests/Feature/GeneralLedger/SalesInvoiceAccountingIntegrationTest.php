<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralLedger;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Listeners\CreditNoteAccountingListener;
use Modules\GeneralLedger\Listeners\InvoiceAccountingListener;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Sales Accounting Test Tenant', 'legal_name' => 'Sales Accounting Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Client Accounting Partner Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    // Setup active fiscal year and open period
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

test('consumes InvoicePostedEvent, generates balanced journal entry, updates invoice and writes outbox', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;
    $invoiceId = (string) Str::uuid7();

    $invoice = SalesInvoice::create([
        'id' => $invoiceId,
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-2027-001',
        'type' => 'standard',
        'date' => '2027-01-15',
        'due_date' => '2027-02-15',
        'currency' => 'EUR',
        'exchange_rate' => 1.0,
        'subtotal' => 10000.00,
        'discount_amount' => 0.0,
        'total_net' => 10000.00,
        'total_tax' => 2200.00,
        'total_gross' => 12200.00,
        'status' => 'posted',
        'payment_status' => 'unpaid',
    ]);

    $revenueLines = [
        ['account_id' => null, 'amount' => 6000.00, 'description' => 'Consulting Services', 'cost_center_id' => null, 'profit_center_id' => null],
        ['account_id' => null, 'amount' => 4000.00, 'description' => 'Software Licenses', 'cost_center_id' => null, 'profit_center_id' => null],
    ];

    $taxLines = [
        ['account_id' => null, 'tax_rate' => 22.0, 'amount' => 2200.00],
    ];

    $actualInvoiceId = (string) $invoice->id;

    $event = new InvoicePostedEvent(
        invoiceId: $actualInvoiceId,
        invoiceNumber: 'INV-2027-001',
        customerId: $customerId,
        date: '2027-01-15',
        dueDate: '2027-02-15',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 10000.00,
        totalTax: 2200.00,
        totalGross: 12200.00,
        revenueLines: $revenueLines,
        taxLines: $taxLines,
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(InvoiceAccountingListener::class);
    $listener->handle($event);

    // Assert Journal Entry created
    $journalEntry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'sales_invoice')
        ->where('source_document_id', $actualInvoiceId)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe('posted');
    expect((float) $journalEntry->total_amount)->toBe(12200.00);

    // Assert Journal Lines balance
    $lines = JournalLine::where('journal_entry_id', $journalEntry->id)->get();
    expect($lines->count())->toBe(4); // 1 AR line + 2 Revenue lines + 1 VAT line

    $debitTotal = $lines->sum('debit_amount');
    $creditTotal = $lines->sum('credit_amount');
    expect((float) $debitTotal)->toBe(12200.00);
    expect((float) $creditTotal)->toBe(12200.00);

    // Verify Accounts Receivable Line
    $arLine = $lines->firstWhere('partner_type', 'customer');
    expect($arLine)->not->toBeNull();
    expect((float) $arLine->debit_amount)->toBe(12200.00);
    expect($arLine->partner_id)->toBe($customerId);

    // Verify Sales Invoice was linked to GL journal
    expect($invoice->fresh()->gl_journal_entry_id)->toBe((string) $journalEntry->id);

    // Verify JournalPostedEvent in Outbox
    $outboxEntry = TransactionalOutbox::where('aggregate_type', 'journal_entry')
        ->where('aggregate_id', (string) $journalEntry->id)
        ->first();
    expect($outboxEntry)->not->toBeNull();
    expect($outboxEntry->event_type)->toBe('general_ledger.journal.posted');
});

test('consumes CreditNoteIssuedEvent and generates balanced reversal journal entry', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;
    $creditNoteId = (string) Str::uuid7();

    $creditNote = SalesInvoice::create([
        'id' => $creditNoteId,
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'CN-2027-001',
        'type' => 'credit_note',
        'date' => '2027-01-20',
        'currency' => 'EUR',
        'subtotal' => 1000.00,
        'total_net' => 1000.00,
        'total_tax' => 220.00,
        'total_gross' => 1220.00,
        'status' => 'posted',
    ]);

    $actualCreditNoteId = (string) $creditNote->id;

    $adjustmentLines = [
        ['description' => 'Damaged Goods Return', 'quantity' => 1.0, 'unit_price' => 1000.00, 'tax_rate' => 22.0],
    ];

    $event = new CreditNoteIssuedEvent(
        creditNoteId: $actualCreditNoteId,
        creditNoteNumber: 'CN-2027-001',
        originalInvoiceId: (string) Str::uuid7(),
        customerId: $customerId,
        creditedAmount: 1220.00,
        adjustmentLines: $adjustmentLines,
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(CreditNoteAccountingListener::class);
    $listener->handle($event);

    // Assert Journal Entry created
    $journalEntry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'credit_note')
        ->where('source_document_id', $actualCreditNoteId)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe('posted');

    // Assert Reversal Lines balance
    $lines = JournalLine::where('journal_entry_id', $journalEntry->id)->get();
    expect($lines->count())->toBe(3); // 1 Revenue Reversal + 1 VAT Reversal + 1 AR Credit

    $debitTotal = $lines->sum('debit_amount');
    $creditTotal = $lines->sum('credit_amount');
    expect((float) $debitTotal)->toBe(1220.00);
    expect((float) $creditTotal)->toBe(1220.00);

    // Verify Accounts Receivable Credit Line
    $arLine = $lines->firstWhere('partner_type', 'customer');
    expect($arLine)->not->toBeNull();
    expect((float) $arLine->credit_amount)->toBe(1220.00);
});
