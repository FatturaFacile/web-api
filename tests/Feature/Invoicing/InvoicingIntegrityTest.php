<?php

declare(strict_types=1);

use App\Actions\Sales\RecordPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Listeners\TreasuryCollectionEventListener;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Models\ReceivableCollection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2027-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->user = User::create(['name' => 'Ada', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']);
    $this->customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PRD-INTEGRITY', 'name' => 'Gateway', 'sale_price' => 500.00, 'cost_price' => 200.00, 'tax_rate' => 22.0]);
    $this->otherCustomer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Verdi Srl', 'country' => 'IT', 'vat_number' => '07643520567', 'sdi_code' => 'XYZ9876']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);

    $this->invoicing = app(InvoicingServiceInterface::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $data
 * @param  list<array<string, mixed>>|null  $items
 */
function integrityInvoice(array $data = [], ?array $items = null, bool $post = true): SalesInvoice
{
    $invoice = app(InvoicingServiceInterface::class)->createInvoice([
        'tenant_id' => (string) test()->tenant->id,
        'contact_id' => (string) test()->customer->id,
        'jurisdiction' => 'IT',
        ...$data,
    ], $items ?? [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]]);

    return $post ? app(InvoicingServiceInterface::class)->postInvoice($invoice, (string) test()->user->id) : $invoice;
}

/**
 * @param  array<string, mixed>  $data
 */
function integrityCreditNote(SalesInvoice $original, float $unitPrice, array $data = []): SalesInvoice
{
    return app(InvoicingServiceInterface::class)->issueCreditNote($original, $data, [
        ['description' => 'Return', 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 22],
    ]);
}

function integrityApi(): mixed
{
    Sanctum::actingAs(test()->user);

    return test()->withHeaders(['X-Tenant-ID' => test()->tenant->id]);
}

// ------------------------------------------------------------ 1. Credit note application

test('applying a credit note validates the amount over HTTP', function (): void {
    $invoice = integrityInvoice();
    $creditNote = integrityCreditNote($invoice, 100);

    foreach ([0, -10, 'abc', 10.001] as $amount) {
        integrityApi()->postJson("/api/v1/invoicing/credit-notes/{$creditNote->id}/apply", ['target_invoice_id' => $invoice->id, 'amount' => $amount])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    integrityApi()->postJson("/api/v1/invoicing/credit-notes/{$creditNote->id}/apply", ['target_invoice_id' => $invoice->id, 'amount' => '50.50'])
        ->assertOk()
        ->assertJsonPath('target_invoice_status', 'partially_paid');

    expect((string) $invoice->fresh()->total_paid)->toBe('50.50');
});

test('a credit note cannot be applied beyond what it holds, across several applications', function (): void {
    $invoice = integrityInvoice();
    $other = integrityInvoice();
    $creditNote = integrityCreditNote($invoice, 100);

    expect((string) $creditNote->total_gross)->toBe('122.00');

    $this->invoicing->applyCreditNote($creditNote, $invoice, '100.00', 'system');

    expect(fn () => $this->invoicing->applyCreditNote($creditNote, $other, '22.01', 'system'))->toThrow(ValidationException::class);

    $this->invoicing->applyCreditNote($creditNote, $other, '22.00', 'system');

    expect(round((float) DB::table('invoice_credit_applications')->where('credit_note_id', $creditNote->id)->sum('applied_amount'), 2))->toBe(122.0)
        ->and((string) $other->fresh()->total_paid)->toBe('22.00');
});

test('a credit note cannot be applied beyond the outstanding amount of the invoice', function (): void {
    $invoice = integrityInvoice([], [['description' => 'Small', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 22]]);
    $big = integrityInvoice();
    $creditNote = integrityCreditNote($big, 100);

    expect(fn () => $this->invoicing->applyCreditNote($creditNote, $invoice, '61.01', 'system'))->toThrow(ValidationException::class)
        ->and((string) $invoice->fresh()->total_paid)->toBe('0.00');
});

test('only an issued credit note of the same customer can be applied, to an issued invoice', function (): void {
    $invoice = integrityInvoice();
    $creditNote = integrityCreditNote($invoice, 100);
    $otherCustomerInvoice = integrityInvoice(['contact_id' => (string) $this->otherCustomer->id]);
    $draft = integrityInvoice([], null, post: false);

    expect(fn () => $this->invoicing->applyCreditNote($creditNote, $otherCustomerInvoice, '10.00', 'system'))->toThrow(ValidationException::class)
        ->and(fn () => $this->invoicing->applyCreditNote($creditNote, $draft, '10.00', 'system'))->toThrow(ValidationException::class)
        ->and(fn () => $this->invoicing->applyCreditNote($invoice, $draft, '10.00', 'system'))->toThrow(ValidationException::class)
        ->and(DB::table('invoice_credit_applications')->count())->toBe(0);
});

// ------------------------------------------------------------ 2. Numbering cannot be bypassed

test('a client cannot choose the number of an invoice or of a credit note', function (): void {
    $draft = integrityApi()->postJson('/api/v1/invoicing/invoices', [
        'contact_id' => $this->customer->id, 'invoice_number' => 'HAND-PICKED-1', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertCreated();

    expect($draft->json('invoice_number'))->toStartWith('DRAFT-');

    integrityApi()->postJson('/api/v1/invoicing/invoices/'.$draft->json('id').'/post')->assertOk();
    $invoice = SalesInvoice::findOrFail($draft->json('id'));

    expect($invoice->invoice_number)->toBe('INV-2027-0001')->and($invoice->sequential_number)->toBe(1);

    $credit = integrityApi()->postJson('/api/v1/invoicing/credit-notes', [
        'original_invoice_id' => $invoice->id, 'invoice_number' => 'CN-HAND-PICKED',
        'items' => [['description' => 'Return', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]],
    ])->assertCreated();

    $creditNote = SalesInvoice::findOrFail($credit->json('id'));

    expect($creditNote->invoice_number)->toBe('CN-2027-0001')
        ->and($creditNote->sequential_number)->toBe(1)
        ->and($creditNote->fiscal_year)->toBe(2027);
});

test('the service ignores a stated number for a fiscal document', function (): void {
    $invoice = integrityInvoice(['invoice_number' => 'INV-FAKE-9']);

    expect($invoice->invoice_number)->toBe('INV-2027-0001')->and($invoice->sequential_number)->toBe(1);
});

test('generating an invoice from a delivery note accepts only the allowed overrides', function (): void {
    $note = integrityDeliveryNote();

    $response = integrityApi()->postJson("/api/v1/invoicing/invoices/from-delivery/{$note->id}", [
        'invoice_number' => 'INV-INJECTED', 'contact_id' => (string) $this->otherCustomer->id, 'tenant_id' => (string) Str::uuid7(),
        'total_gross' => 1, 'notes' => 'Delivered in March',
    ])->assertCreated();

    $invoice = SalesInvoice::findOrFail($response->json('id'));

    expect($invoice->invoice_number)->toStartWith('DRAFT-')
        ->and((string) $invoice->contact_id)->toBe((string) $this->customer->id)
        ->and((string) $invoice->tenant_id)->toBe((string) $this->tenant->id)
        ->and($invoice->notes)->toBe('Delivered in March')
        ->and((string) $invoice->total_gross)->toBe('1220.00');
});

// ------------------------------------------------------------ 3. Credit note emission

test('a credit note needs an issued, non proforma original', function (): void {
    $draft = integrityInvoice([], null, post: false);
    $proforma = integrityInvoice(['invoice_type' => 'proforma'], null, post: false);

    expect(fn () => integrityCreditNote($draft, 10))->toThrow(ValidationException::class)
        ->and(fn () => integrityCreditNote($proforma, 10))->toThrow(ValidationException::class)
        ->and(SalesInvoice::where('invoice_type', 'credit_note')->count())->toBe(0);
});

test('the credit notes of an invoice cannot exceed its total, and a refused one leaves no gap', function (): void {
    $invoice = integrityInvoice();

    integrityCreditNote($invoice, 600);

    expect(fn () => integrityCreditNote($invoice, 400.01))->toThrow(ValidationException::class);

    $last = integrityCreditNote($invoice, 400);

    expect($last->invoice_number)->toBe('CN-2027-0002')
        ->and((string) SalesInvoice::where('related_invoice_id', $invoice->id)->sum('total_gross'))->toBe('1220.00');
});

// ------------------------------------------------------------ 4. Customer ledger

test('the running balance of a customer follows the order of its entries within the same second', function (): void {
    $ledger = app(CustomerLedgerServiceInterface::class);
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    foreach ([100.0, 200.0, 300.0] as $amount) {
        $invoice = integrityInvoice([], [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0, 'vat_nature' => 'N2.2']], post: false);
        $ledger->recordInvoiceEntry($invoice, 'system');
    }

    $ledger->recordPaymentEntry($customerId, 50.0, 'PAY-1', $tenantId);

    $balances = CustomerLedgerEntry::where('customer_id', $customerId)->orderBy('created_at')->orderBy('id')->pluck('balance_after')->map(fn ($value): string => (string) $value)->all();

    expect(CustomerLedgerEntry::where('customer_id', $customerId)->distinct()->count('created_at'))->toBe(1)
        ->and($balances)->toBe(['100.00', '300.00', '600.00', '550.00'])
        ->and(CustomerLedgerEntry::whereNotNull('created_by')->count())->toBe(0);
});

// ------------------------------------------------------------ 5. Treasury collections

test('a treasury collection becomes one payment, even when delivered twice, and survives a recalculation', function (): void {
    $invoice = integrityInvoice();
    $event = new CollectionReceivedEvent(
        collectionId: (string) Str::uuid7(),
        customerId: (string) $this->customer->id,
        invoiceId: (string) $invoice->id,
        collectedAmount: 500.0,
        collectionDate: '2027-03-15',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        eventId: (string) Str::uuid7()
    );

    app(TreasuryCollectionEventListener::class)->handle($event);
    app(TreasuryCollectionEventListener::class)->handle($event);

    expect(Payment::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and((string) $invoice->fresh()->total_paid)->toBe('500.00')
        ->and($invoice->fresh()->payment_status)->toBe('partially_paid');

    app(RecordPayment::class)->recalculate(Invoice::findOrFail($invoice->id));

    expect((string) $invoice->fresh()->total_paid)->toBe('500.00');
});

test('recording a collection settles its invoice once in the ledger and refuses an over-collection', function (): void {
    $invoice = integrityInvoice();
    $collections = app(ReceivableCollectionServiceInterface::class);
    $collection = $collections->scheduleCollection([
        'tenant_id' => (string) $this->tenant->id,
        'customer_id' => (string) $this->customer->id,
        'invoice_id' => (string) $invoice->id,
        'expected_amount' => 1220.00,
        'due_date' => '2027-04-15',
    ]);

    $collections->recordCollectionReceived((string) $collection->id, 1000.00, '2027-03-15');

    expect(fn () => $collections->recordCollectionReceived((string) $collection->id, 220.01, '2027-03-16'))->toThrow(InvalidArgumentException::class);

    $collections->recordCollectionReceived((string) $collection->id, 220.00, '2027-03-16');

    $fresh = $invoice->fresh();

    expect(Payment::where('invoice_id', $invoice->id)->count())->toBe(2)
        ->and((string) $fresh->total_paid)->toBe('1220.00')
        ->and($fresh->payment_status)->toBe('paid')
        ->and(DB::table('journal_entries')->where('source_document_type', 'treasury_collection')->count())->toBe(0)
        ->and(DB::table('journal_entries')->where('source_document_type', 'customer_payment')->count())->toBe(2)
        ->and((string) ReceivableCollection::findOrFail($collection->id)->collected_amount)->toBe('1220.00');
});

test('a collection above what the invoice still owes is refused and rolled back', function (): void {
    $invoice = integrityInvoice();
    $collections = app(ReceivableCollectionServiceInterface::class);
    $collection = $collections->scheduleCollection([
        'tenant_id' => (string) $this->tenant->id,
        'customer_id' => (string) $this->customer->id,
        'invoice_id' => (string) $invoice->id,
        'expected_amount' => 5000.00,
    ]);

    expect(fn () => $collections->recordCollectionReceived((string) $collection->id, 1300.00, '2027-03-15'))->toThrow(ValidationException::class)
        ->and((string) ReceivableCollection::findOrFail($collection->id)->collected_amount)->toBe('0.00')
        ->and(Payment::where('invoice_id', $invoice->id)->count())->toBe(0);
});

// ------------------------------------------------------------ 6. Orders and delivery notes

function integrityOrder(): mixed
{
    return app(SalesOrderServiceInterface::class)->createOrder([
        'tenant_id' => (string) test()->tenant->id,
        'contact_id' => (string) test()->customer->id,
        'order_number' => 'SO-INTEGRITY-'.Str::random(4),
    ], [
        ['product_id' => (string) test()->product->id, 'description' => 'Gateway', 'quantity' => 2.0, 'unit_price' => 500.00, 'tax_rate' => 22.0],
    ]);
}

function integrityDeliveryNote(): mixed
{
    $order = integrityOrder();

    return app(DeliveryFulfillmentServiceInterface::class)->createDeliveryNote($order, ['ddt_number' => 'DDT-'.Str::random(6)], [
        ['sales_order_item_id' => (string) $order->items->first()->id, 'quantity' => 2.0],
    ]);
}

test('an order is invoiced once', function (): void {
    $order = integrityOrder();

    $this->invoicing->createInvoiceFromOrder($order);

    expect(fn () => $this->invoicing->createInvoiceFromOrder($order->fresh()))->toThrow(ValidationException::class)
        ->and(SalesInvoice::where('sales_order_id', $order->id)->count())->toBe(1);
});

test('an order with nothing left to invoice is refused', function (): void {
    $order = integrityOrder();
    $order->items()->update(['quantity_invoiced' => 2.0]);

    expect(fn () => $this->invoicing->createInvoiceFromOrder($order->fresh()))->toThrow(ValidationException::class)
        ->and(SalesInvoice::where('sales_order_id', $order->id)->count())->toBe(0);
});

test('a delivery note is invoiced once', function (): void {
    $note = integrityDeliveryNote();

    $this->invoicing->createInvoiceFromDeliveryNote($note);

    expect(fn () => $this->invoicing->createInvoiceFromDeliveryNote($note))->toThrow(ValidationException::class)
        ->and(SalesInvoice::where('delivery_note_id', $note->id)->count())->toBe(1);
});

// ------------------------------------------------------------ 7. VAT rounding

test('the VAT is computed per rate on the summed net, the same in the totals, the ledger and the XML', function (): void {
    $lines = array_fill(0, 3, ['description' => 'Tiny', 'quantity' => 1, 'unit_price' => 0.10, 'tax_rate' => 22]);

    expect(app(DocumentTotalsCalculator::class)->calculate($lines)['total_tax'])->toBe(0.07);

    $invoice = integrityInvoice([], $lines);

    expect((string) $invoice->total_tax)->toBe('0.07')
        ->and((string) $invoice->total_gross)->toBe('0.37');

    $vatInLedger = DB::table('journal_lines')->where('journal_entry_id', $invoice->gl_journal_entry_id)->sum('vat_tax_amount');

    expect(round((float) $vatInLedger, 2))->toBe(0.07);

    $issued = app(InvoiceService::class)->createInvoice([
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => '2027-03-15', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'items' => $lines,
    ]);

    expect((string) $issued->total_tax)->toBe('0.07');

    foreach ([Invoice::findOrFail($invoice->id), $issued] as $document) {
        $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($document->load(['tenant', 'contact', 'items', 'declarationOfIntent']), '0000C');

        expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
            ->and($xml)->toContain('<ImponibileImporto>0.30</ImponibileImporto>')
            ->and($xml)->toContain('<Imposta>0.07</Imposta>')
            ->and($xml)->toContain('<ImportoTotaleDocumento>0.37</ImportoTotaleDocumento>');
    }
});

// ------------------------------------------------------------ 8. Business day of the company

test('document dates and the default numbering year follow the clock of the company', function (): void {
    fiscalCalendar($this->tenant, 2026);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));

    $draft = integrityInvoice([], null, post: false);

    expect($draft->date->toDateString())->toBe('2027-01-01')
        ->and(app(DocumentNumberingService::class)->next('quote', null, (string) $this->tenant->id)['fiscal_year'])->toBe(2027);
});

// ------------------------------------------------------------ 9. Actors

test('no actor id is invented when the action has no user', function (): void {
    $invoice = integrityInvoice();
    $creditNote = integrityCreditNote($invoice, 10, ['created_by' => 'system']);
    $application = $this->invoicing->applyCreditNote($creditNote, $invoice, '12.20', 'system');

    expect($creditNote->posted_by)->toBeNull()
        ->and($creditNote->created_by)->toBeNull()
        ->and($application->applied_by)->toBeNull();
});

// ------------------------------------------------------------ Indexes

test('the invoice foreign keys of the ledger and of the credit applications are indexed', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog check.');
    }

    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename IN ('customer_ledger_entries', 'invoice_credit_applications')"))->pluck('indexname')->all();

    expect($indexes)->toContain('customer_ledger_entries_invoice_id_index')
        ->and($indexes)->toContain('invoice_credit_applications_original_invoice_id_index');
});
