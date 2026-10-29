<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralLedger;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Listeners\CreditNoteAccountingListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Models\SalesInvoice;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Credit Note Audit Tenant', 'legal_name' => 'Credit Note Audit Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Enterprise Client SpA'],
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

test('CreditNoteAccountingListenerTest: consumes CreditNoteIssuedEvent and creates reversal journal', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $creditNote = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'CN-2027-0010',
        'type' => 'credit_note',
        'date' => '2027-01-22',
        'currency' => 'EUR',
        'subtotal' => 2000.00,
        'total_net' => 2000.00,
        'total_tax' => 440.00,
        'total_gross' => 2440.00,
        'status' => 'posted',
    ]);

    $actualCreditNoteId = (string) $creditNote->id;

    $event = new CreditNoteIssuedEvent(
        creditNoteId: $actualCreditNoteId,
        creditNoteNumber: 'CN-2027-0010',
        originalInvoiceId: (string) Str::uuid7(),
        customerId: $customerId,
        creditedAmount: 2440.00,
        adjustmentLines: [
            ['description' => 'Goods Return', 'quantity' => 2.0, 'unit_price' => 1000.00, 'tax_rate' => 22.0],
        ],
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(CreditNoteAccountingListener::class);
    $listener->handle($event);

    $journalEntry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'credit_note')
        ->where('source_document_id', $actualCreditNoteId)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe('posted');

    // Idempotency: handle second time
    $listener->handle($event);
    $count = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_type', 'credit_note')
        ->where('source_document_id', $actualCreditNoteId)
        ->count();
    expect($count)->toBe(1);
});

test('CreditNoteJournalPostingTest: posts balanced reversal with Revenue Reversal Dr, VAT Reversal Dr, AR Cr', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $creditNote = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'CN-2027-0020',
        'type' => 'credit_note',
        'date' => '2027-01-23',
        'currency' => 'EUR',
        'total_net' => 5000.00,
        'total_tax' => 1100.00,
        'total_gross' => 6100.00,
        'status' => 'posted',
    ]);

    $event = new CreditNoteIssuedEvent(
        creditNoteId: (string) $creditNote->id,
        creditNoteNumber: 'CN-2027-0020',
        originalInvoiceId: (string) Str::uuid7(),
        customerId: $customerId,
        creditedAmount: 6100.00,
        adjustmentLines: [
            ['description' => 'Contract Renegotiation Discount', 'quantity' => 1.0, 'unit_price' => 5000.00, 'tax_rate' => 22.0],
        ],
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(CreditNoteAccountingListener::class)->handle($event);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $creditNote->id)
        ->first();

    expect($entry)->not->toBeNull();
    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

    $debitTotal = (float) $lines->sum('debit_amount');
    $creditTotal = (float) $lines->sum('credit_amount');
    expect($debitTotal)->toBe(6100.00);
    expect($creditTotal)->toBe(6100.00);

    // Revenue Reversal (4090) Debit
    $revAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '4090')->first();
    $revLine = $lines->firstWhere('account_id', $revAccount->id);
    expect((float) $revLine->debit_amount)->toBe(5000.00);

    // VAT Reversal (2200) Debit
    $vatAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '2200')->first();
    $vatLine = $lines->firstWhere('account_id', $vatAccount->id);
    expect((float) $vatLine->debit_amount)->toBe(1100.00);

    // AR Account (1100) Credit
    $arAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '1100')->first();
    $arLine = $lines->firstWhere('account_id', $arAccount->id);
    expect((float) $arLine->credit_amount)->toBe(6100.00);
});

test('PartialCreditTest: handles partial credit notes against invoice', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    // Partial credit: original was 12,200 EUR, credit note is 1,220 EUR
    $partialCredit = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'CN-PARTIAL-01',
        'type' => 'credit_note',
        'date' => '2027-01-24',
        'currency' => 'EUR',
        'total_gross' => 1220.00,
        'status' => 'posted',
    ]);

    $event = new CreditNoteIssuedEvent(
        creditNoteId: (string) $partialCredit->id,
        creditNoteNumber: 'CN-PARTIAL-01',
        originalInvoiceId: (string) Str::uuid7(),
        customerId: $customerId,
        creditedAmount: 1220.00,
        adjustmentLines: [
            ['description' => 'Partial Allowance', 'quantity' => 1.0, 'unit_price' => 1000.00, 'tax_rate' => 22.0],
        ],
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(CreditNoteAccountingListener::class)->handle($event);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $partialCredit->id)
        ->first();

    expect($entry)->not->toBeNull();
    expect((float) $entry->total_amount)->toBe(1220.00);
});

test('VatReversalTest: correctly reverses output VAT liability', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $creditNote = SalesInvoice::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'CN-VAT-REV-01',
        'type' => 'credit_note',
        'date' => '2027-01-25',
        'currency' => 'EUR',
        'total_gross' => 3660.00,
        'status' => 'posted',
    ]);

    $event = new CreditNoteIssuedEvent(
        creditNoteId: (string) $creditNote->id,
        creditNoteNumber: 'CN-VAT-REV-01',
        originalInvoiceId: (string) Str::uuid7(),
        customerId: $customerId,
        creditedAmount: 3660.00,
        adjustmentLines: [
            ['description' => 'Defective Hardware Batch', 'quantity' => 3.0, 'unit_price' => 1000.00, 'tax_rate' => 22.0],
        ],
        tenantId: $tenantId,
        companyId: $tenantId
    );

    app(CreditNoteAccountingListener::class)->handle($event);

    $entry = JournalEntry::where('tenant_id', $tenantId)
        ->where('source_document_id', (string) $creditNote->id)
        ->first();

    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    $vatAccount = ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '2200')->first();
    $vatLine = $lines->firstWhere('account_id', $vatAccount->id);

    expect($vatLine)->not->toBeNull();
    expect((float) $vatLine->debit_amount)->toBe(660.00);
    expect((float) $vatLine->credit_amount)->toBe(0.0);
});
