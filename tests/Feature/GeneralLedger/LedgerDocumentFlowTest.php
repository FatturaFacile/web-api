<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Sales\RecordPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

uses(DatabaseTransactions::class);

/**
 * @return array{tenant: Tenant, user: User, customer: Contact, supplier: Contact}
 */
function ledgerCompany(string $country): array
{
    $tenant = Tenant::create([
        'name' => "Ledger {$country} ".Str::random(6),
        'legal_name' => "Ledger {$country} SA",
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
        'vat_number' => '0081172058'.random_int(0, 9),
    ]);
    $user = User::create(['name' => 'Accountant', 'email' => Str::random(12).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
    app(OpenFiscalYearAction::class)->execute($tenant->id, (int) now()->format('Y'));

    return [
        'tenant' => $tenant,
        'user' => $user,
        'customer' => Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente SA', 'country' => $country]),
        'supplier' => Contact::create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore SA', 'country' => $country, 'iban' => 'CH9300762011623852957']),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ledgerInvoicePayload(array $company, array $overrides = []): array
{
    $country = $company['tenant']->country;

    return [
        'contact_id' => $company['customer']->id,
        'invoice_type' => 'standard',
        'date' => now()->toDateString(),
        'currency' => $country === 'CH' ? 'CHF' : 'EUR',
        'jurisdiction' => $country,
        'items' => [['description' => 'Consulting', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => $country === 'CH' ? 8.1 : 22]],
        ...$overrides,
    ];
}

function ledgerAccountBalance(string $tenantId, string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_code', $code)->value('id');
    $sum = JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) as balance')->value('balance');

    return number_format((float) $sum, 2, '.', '');
}

function ledgerEntries(string $type, string $id): int
{
    return JournalEntry::withoutGlobalScopes()->where('source_document_type', $type)->where('source_document_id', $id)->count();
}

function closeLedgerPeriodOf(string $tenantId, string $date, string $actorId): FiscalPeriod
{
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    return app(PeriodClosingServiceInterface::class)->closePeriod((string) $period->id, $actorId);
}

dataset('countries', [
    'Swiss tenant' => ['CH', '324.30', '24.30'],
    'Italian tenant' => ['IT', '366.00', '66.00'],
]);

test('invoice, entry, collection: receivables end at zero', function (string $country, string $gross, string $vat): void {
    $company = ledgerCompany($country);
    $tenantId = $company['tenant']->id;

    $created = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['invoice_number' => 'CLIENT-99']))->assertCreated();
    $invoiceId = $created->json('data.id');

    expect($created->json('data.invoice_number'))->toBe('INV-'.now()->format('Y').'-0001')
        ->and($created->json('data.status'))->toBe('posted')
        ->and(ledgerEntries('sales_invoice', $invoiceId))->toBe(1)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe($gross)
        ->and(ledgerAccountBalance($tenantId, '2200'))->toBe('-'.$vat);

    $this->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => (float) $gross, 'paid_at' => now()->toDateString()])->assertSuccessful();

    $payment = Payment::query()->where('invoice_id', $invoiceId)->sole();

    expect(ledgerEntries('customer_payment', $payment->id))->toBe(1)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('0.00')
        ->and(ledgerAccountBalance($tenantId, '1020'))->toBe($gross)
        ->and((float) CustomerLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(0.0)
        ->and(Invoice::findOrFail($invoiceId)->payment_status)->toBe('paid');
})->with('countries');

test('a fee withheld by the bank goes to the bank charges and closes the receivable', function (): void {
    $company = ledgerCompany('CH');
    $tenantId = $company['tenant']->id;
    $invoice = Invoice::findOrFail($this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.id'));

    app(RecordPayment::class)->execute($invoice, ['amount' => '322.80', 'paid_at' => now()->toDateString()]);
    app(RecordPayment::class)->execute($invoice, ['amount' => '1.50', 'paid_at' => now()->toDateString(), 'type' => 'bank_fee']);

    expect(ledgerAccountBalance($tenantId, '1100'))->toBe('0.00')
        ->and(ledgerAccountBalance($tenantId, '6800'))->toBe('1.50')
        ->and(ledgerAccountBalance($tenantId, '1020'))->toBe('322.80');
});

test('a credit note reverses the invoice in the ledger and closes it', function (string $country, string $gross): void {
    $company = ledgerCompany($country);
    $tenantId = $company['tenant']->id;
    $invoiceId = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->assertCreated()->json('data.id');

    $note = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['invoice_type' => 'credit_note', 'related_invoice_id' => $invoiceId]))->assertCreated();

    expect($note->json('data.invoice_number'))->toBe('CN-'.now()->format('Y').'-0001')
        ->and(ledgerEntries('credit_note', $note->json('data.id')))->toBe(1)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('0.00')
        ->and(ledgerAccountBalance($tenantId, '2200'))->toBe('0.00')
        ->and((float) CustomerLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(0.0)
        ->and(Invoice::findOrFail($invoiceId)->payment_status)->toBe('paid');
})->with('countries');

test('a credit note does not vanish when a later payment recalculates the invoice', function (): void {
    $company = ledgerCompany('IT');
    $invoice = Invoice::findOrFail($this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.id'));
    $partial = ['items' => [['description' => 'Refund', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]];

    $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, ...$partial]))->assertCreated();
    app(RecordPayment::class)->execute($invoice->refresh(), ['amount' => '244.00', 'paid_at' => now()->toDateString()]);

    expect((float) $invoice->refresh()->total_paid)->toEqual(366.0)
        ->and($invoice->payment_status)->toBe('paid')
        ->and(ledgerAccountBalance($company['tenant']->id, '1100'))->toBe('0.00');
});

test('posting twice changes nothing', function (): void {
    $company = ledgerCompany('IT');
    $service = app(InvoicingServiceInterface::class);
    $invoice = $service->createInvoice(
        ['tenant_id' => $company['tenant']->id, 'contact_id' => $company['customer']->id, 'date' => now()->toDateString()],
        [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]
    );

    expect($invoice->invoice_number)->toStartWith('DRAFT-');

    $first = $service->postInvoice($invoice, (string) $company['user']->id);
    $number = $first->invoice_number;
    $second = $service->postInvoice(SalesInvoice::findOrFail($invoice->id), (string) $company['user']->id);

    expect($number)->toBe('INV-'.now()->format('Y').'-0001')
        ->and($second->invoice_number)->toBe($number)
        ->and(ledgerEntries('sales_invoice', $invoice->id))->toBe(1)
        ->and(CustomerLedgerEntry::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(CustomerLedger::withoutGlobalScopes()->where('tenant_id', $company['tenant']->id)->count())->toBe(1);
});

test('a posting already in the ledger cannot be duplicated even bypassing the service check', function (): void {
    $company = ledgerCompany('IT');
    $invoiceId = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.id');
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoiceId)->sole();

    expect(fn () => DB::transaction(fn () => JournalEntry::create([
        'tenant_id' => $company['tenant']->id,
        'fiscal_period_id' => $entry->fiscal_period_id,
        'entry_number' => 'DUP-1',
        'posting_date' => now()->toDateString(),
        'document_date' => now()->toDateString(),
        'description' => 'Duplicate',
        'source_document_type' => 'sales_invoice',
        'source_document_id' => $invoiceId,
        'status' => 'posted',
    ])))->toThrow(QueryException::class);
});

test('a closed period stops the emission and leaves no document and no gap', function (string $country): void {
    $company = ledgerCompany($country);
    $tenantId = $company['tenant']->id;

    $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->assertCreated();
    closeLedgerPeriodOf($tenantId, now()->toDateString(), (string) $company['user']->id);

    $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))
        ->assertStatus(422)
        ->assertJsonPath('errors.fiscal_period.0', fn (string $message): bool => str_contains($message, 'is closed'));

    expect(Invoice::query()->count())->toBe(1);

    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->firstOrFail();
    app(PeriodClosingServiceInterface::class)->reopenPeriod((string) $period->id, (string) $company['user']->id);

    expect($this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->assertCreated()->json('data.invoice_number'))
        ->toBe('INV-'.now()->format('Y').'-0002');
})->with(['CH', 'IT']);

test('a date in no fiscal period stops the emission', function (): void {
    $company = ledgerCompany('IT');

    $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['date' => '2019-05-10']))
        ->assertStatus(422)
        ->assertJsonPath('errors.fiscal_period.0', fn (string $message): bool => str_contains($message, '2019-05-10'));

    expect(Invoice::query()->count())->toBe(0);
});

test('the entry goes to the period of the document date, not to the first open one', function (): void {
    $company = ledgerCompany('CH');
    $date = now()->startOfYear()->addMonths(2)->addDays(9)->toDateString();
    $invoiceId = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['date' => $date]))->assertCreated()->json('data.id');
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoiceId)->sole();

    expect((int) FiscalPeriod::withoutGlobalScopes()->findOrFail($entry->fiscal_period_id)->period_number)->toBe(3)
        ->and($entry->posting_date->toDateString())->toBe($date);
});

test('emissions of a year and series follow one another without gaps', function (): void {
    $company = ledgerCompany('CH');
    $year = now()->format('Y');
    $numbers = [];

    foreach (range(1, 3) as $ignored) {
        $numbers[] = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.invoice_number');
    }

    $proforma = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company, ['invoice_type' => 'proforma']))->assertCreated();

    expect($numbers)->toBe(["INV-{$year}-0001", "INV-{$year}-0002", "INV-{$year}-0003"])
        ->and($proforma->json('data.invoice_number'))->toBe("PF-{$year}-0001")
        ->and($proforma->json('data.status'))->toBe('draft')
        ->and(ledgerEntries('sales_invoice', $proforma->json('data.id')))->toBe(0);
});

test('a supplier invoice creates the debt with deductible VAT and its payment clears it', function (string $country, string $gross, string $vat): void {
    $company = ledgerCompany($country);
    $tenantId = $company['tenant']->id;
    $invoice = PassiveInvoice::create([
        'tenant_id' => $tenantId,
        'supplier_id' => $company['supplier']->id,
        'invoice_number' => 'SUP-1',
        'date' => now()->toDateString(),
        'total_amount' => $gross,
        'total_net' => '300.00',
        'total_tax' => $vat,
        'currency' => $company['tenant']->default_currency,
        'payment_status' => 'unpaid',
    ]);

    app(PostPassiveInvoiceAction::class)->execute($invoice);
    app(PostPassiveInvoiceAction::class)->execute($invoice->refresh());

    expect(ledgerEntries('vendor_invoice', $invoice->id))->toBe(1)
        ->and(ledgerAccountBalance($tenantId, '2000'))->toBe('-'.$gross)
        ->and(ledgerAccountBalance($tenantId, '1400'))->toBe($vat)
        ->and(ledgerAccountBalance($tenantId, '6000'))->toBe('300.00')
        ->and((float) SupplierLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual((float) $gross);

    app(RecordSupplierPayment::class)->execute($invoice->refresh(), ['amount' => $gross, 'paid_at' => now()->toDateString()]);

    expect(ledgerAccountBalance($tenantId, '2000'))->toBe('0.00')
        ->and(ledgerAccountBalance($tenantId, '1020'))->toBe('-'.$gross)
        ->and((float) SupplierLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(0.0);
})->with('countries');

test('a supplier credit note posts the opposite entry', function (): void {
    $company = ledgerCompany('IT');
    $invoice = PassiveInvoice::create([
        'tenant_id' => $company['tenant']->id, 'supplier_id' => $company['supplier']->id, 'invoice_number' => 'NC-1', 'date' => now()->toDateString(),
        'total_amount' => '122.00', 'total_net' => '100.00', 'total_tax' => '22.00', 'currency' => 'EUR', 'document_type' => 'TD04', 'payment_status' => 'unpaid',
    ]);

    app(PostPassiveInvoiceAction::class)->execute($invoice);

    expect(ledgerAccountBalance($company['tenant']->id, '2000'))->toBe('122.00')
        ->and(ledgerAccountBalance($company['tenant']->id, '1400'))->toBe('-22.00');
});

test('a foreign currency supplier invoice without a rate is refused', function (): void {
    $company = ledgerCompany('CH');
    $invoice = PassiveInvoice::create([
        'tenant_id' => $company['tenant']->id, 'supplier_id' => $company['supplier']->id, 'invoice_number' => 'EUR-1', 'date' => now()->toDateString(),
        'total_amount' => '100.00', 'currency' => 'EUR', 'payment_status' => 'unpaid',
    ]);

    expect(fn () => app(PostPassiveInvoiceAction::class)->execute($invoice))->toThrow(InvalidArgumentException::class, 'exchange rate');
});

test('a payment made before its invoice reached the ledger is posted right after it', function (): void {
    $company = ledgerCompany('IT');
    $tenantId = $company['tenant']->id;
    $invoice = Invoice::create([
        'tenant_id' => $tenantId, 'contact_id' => $company['customer']->id, 'invoice_type' => 'standard', 'invoice_number' => 'OLD-1', 'date' => now()->toDateString(),
        'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'payment_status' => 'unpaid', 'jurisdiction' => 'IT',
    ]);
    $invoice->items()->create(['description' => 'Old', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]);

    $payment = app(RecordPayment::class)->execute($invoice, ['amount' => '122.00', 'paid_at' => now()->toDateString()]);

    expect(ledgerEntries('customer_payment', $payment->id))->toBe(0);

    $this->artisan('general-ledger:post-pending', ['--tenant' => [$tenantId]])->assertSuccessful();

    expect($invoice->refresh()->status)->toBe('posted')
        ->and($invoice->invoice_number)->toBe('OLD-1')
        ->and(ledgerEntries('customer_payment', $payment->id))->toBe(1)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('0.00');
});

test('the balance is compared in decimals and a closing entry cannot be created from outside', function (): void {
    $company = ledgerCompany('IT');
    $tenantId = $company['tenant']->id;
    $posting = app(DoubleEntryPostingServiceInterface::class);
    $cash = ChartOfAccount::create(['tenant_id' => $tenantId, 'account_code' => '1000', 'name' => 'Cash', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => 'EUR']);
    $sales = ChartOfAccount::create(['tenant_id' => $tenantId, 'account_code' => '4000', 'name' => 'Sales', 'account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'currency' => 'EUR']);

    expect($posting->validateBalance([['debit_amount' => 0.1], ['debit_amount' => 0.2], ['credit_amount' => 0.3]]))->toBeTrue()
        ->and($posting->validateBalance([['debit_amount' => '1000000000.0001'], ['credit_amount' => '1000000000.0002']]))->toBeFalse()
        ->and($posting->validateBalance([['debit_amount' => -5], ['credit_amount' => -5]]))->toBeFalse();

    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('period_number', 1)->firstOrFail();
    $payload = [
        'fiscal_period_id' => $period->id,
        'posting_date' => $period->start_date->toDateString(),
        'description' => 'Manual',
        'journal_type' => 'closing',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 10, 'credit_amount' => 0],
            ['account_id' => $sales->id, 'debit_amount' => 0, 'credit_amount' => 10],
        ],
    ];

    $this->postJson('/api/v1/general-ledger/journals', $payload)->assertStatus(422)->assertJsonValidationErrors('journal_type');
    $this->postJson('/api/v1/general-ledger/journals', ['journal_type' => 'standard', 'source_document_type' => 'sales_invoice'] + $payload)->assertStatus(422);

    $data = ['tenant_id' => $tenantId, 'fiscal_period_id' => $period->id, 'posting_date' => $period->start_date->toDateString(), 'lines' => $payload['lines']];

    expect(fn () => $posting->createAndPost(['journal_type' => 'closing'] + $data))->toThrow(InvalidArgumentException::class, 'fiscal year closing')
        ->and(fn () => $posting->createAndPost(['posting_date' => $period->end_date->addDay()->toDateString()] + $data))->toThrow(FiscalPeriodException::class, 'outside fiscal period');
});

test('an empty company reaches the database as null', function (): void {
    $company = ledgerCompany('IT');
    $tenantId = $company['tenant']->id;
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('period_number', 1)->firstOrFail();
    $debit = ChartOfAccount::create(['tenant_id' => $tenantId, 'account_code' => '1000', 'name' => 'Cash', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => 'EUR']);
    $credit = ChartOfAccount::create(['tenant_id' => $tenantId, 'account_code' => '4000', 'name' => 'Sales', 'account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'currency' => 'EUR']);

    $entry = app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenantId,
        'company_id' => '',
        'fiscal_period_id' => $period->id,
        'posting_date' => $period->start_date->toDateString(),
        'lines' => [['account_id' => $debit->id, 'debit_amount' => 5], ['account_id' => $credit->id, 'credit_amount' => 5]],
    ]);

    expect($entry->company_id)->toBeNull();
});

test('reversing a journal inverts the customer and supplier subledgers', function (): void {
    $company = ledgerCompany('IT');
    $tenantId = $company['tenant']->id;
    $posting = app(DoubleEntryPostingServiceInterface::class);
    $invoice = Invoice::findOrFail($this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.id'));
    $payment = app(RecordPayment::class)->execute($invoice, ['amount' => '100.00', 'paid_at' => now()->toDateString()]);
    $paymentEntry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $payment->id)->sole();

    $posting->reverseJournal((string) $paymentEntry->id, now()->toDateString(), 'Bounced');

    expect((float) CustomerLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(366.0)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('366.00');

    $posting->reverseJournal((string) $invoice->refresh()->gl_journal_entry_id, now()->toDateString(), 'Wrong');

    expect((float) CustomerLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(0.0)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('0.00');

    $supplierInvoice = PassiveInvoice::create([
        'tenant_id' => $tenantId, 'supplier_id' => $company['supplier']->id, 'invoice_number' => 'SUP-9', 'date' => now()->toDateString(),
        'total_amount' => '122.00', 'total_net' => '100.00', 'total_tax' => '22.00', 'currency' => 'EUR', 'payment_status' => 'unpaid',
    ]);
    app(PostPassiveInvoiceAction::class)->execute($supplierInvoice);

    $posting->reverseJournal((string) $supplierInvoice->refresh()->gl_journal_entry_id, now()->toDateString(), 'Wrong');

    expect((float) SupplierLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->sum('open_amount'))->toEqual(0.0);
});

test('the database refuses changes to issued invoices and posted entries', function (): void {
    $company = ledgerCompany('IT');
    $invoiceId = $this->postJson('/api/v1/sales/invoices', ledgerInvoicePayload($company))->json('data.id');
    $entryId = Invoice::findOrFail($invoiceId)->gl_journal_entry_id;
    $refused = fn (callable $statement) => expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);

    $refused(fn () => DB::table('invoices')->where('id', $invoiceId)->update(['total_gross' => 1]));
    $refused(fn () => DB::table('invoices')->where('id', $invoiceId)->update(['deleted_at' => now()]));
    $refused(fn () => DB::table('invoices')->where('id', $invoiceId)->delete());
    $refused(fn () => DB::table('invoice_items')->where('invoice_id', $invoiceId)->update(['unit_price' => 1]));
    $refused(fn () => DB::table('journal_entries')->where('id', $entryId)->update(['description' => 'Changed']));
    $refused(fn () => DB::table('journal_entries')->where('id', $entryId)->delete());
    $refused(fn () => DB::table('journal_lines')->where('journal_entry_id', $entryId)->update(['debit_amount' => 1]));
    $refused(fn () => DB::table('journal_lines')->where('journal_entry_id', $entryId)->delete());

    DB::table('invoices')->where('id', $invoiceId)->update(['notes' => 'Still free']);

    $this->deleteJson("/api/v1/sales/invoices/{$invoiceId}")->assertForbidden();
});

test('the QR reference is the element after its type', function (): void {
    $company = ledgerCompany('CH');
    $lines = array_fill(0, 32, '');
    $lines[0] = 'SPC';
    $lines[1] = '0200';
    $lines[2] = '1';
    $lines[3] = 'CH4431999123000889012';
    $lines[5] = 'Swiss Telecom AG';
    $lines[18] = '162.15';
    $lines[19] = 'CHF';
    $lines[27] = 'QRR';
    $lines[28] = '210000000003139471430009017';
    $lines[30] = 'EPD';

    $response = $this->postJson('/api/v1/purchases/passive-invoices/scan-qr-bill', ['qr_payload' => implode("\r\n", $lines), 'total_tax' => 12.15])->assertCreated();

    $invoice = PassiveInvoice::findOrFail($response->json('data.id'));

    expect($response->json('data.scor_reference'))->toBe('210000000003139471430009017')
        ->and($response->json('data.reference_type'))->toBe('QRR')
        ->and($invoice->invoice_number)->toBe('QR-210000000003139471430009017')
        ->and(ledgerAccountBalance($company['tenant']->id, '1400'))->toBe('12.15')
        ->and(ledgerAccountBalance($company['tenant']->id, '2000'))->toBe('-162.15');
});

/**
 * @return array{batch: PaymentBatch, account: BankAccount, invoice: PassiveInvoice}
 */
function ledgerExportedBatch(array $company): array
{
    $tenantId = $company['tenant']->id;
    $glBank = ChartOfAccount::create(['tenant_id' => $tenantId, 'account_code' => '1021', 'name' => 'Conto corrente', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => $company['tenant']->default_currency]);
    $account = BankAccount::create([
        'tenant_id' => $tenantId, 'company_id' => $tenantId, 'bank_name' => 'PostFinance', 'account_number' => 'A-'.Str::random(6), 'account_type' => 'OPERATING',
        'iban' => 'CH9300762011623852957', 'currency' => $company['tenant']->default_currency, 'opening_balance' => 1000, 'current_balance' => 1000, 'available_balance' => 1000, 'is_active' => true, 'gl_account_id' => $glBank->id,
    ]);
    $invoice = PassiveInvoice::create([
        'tenant_id' => $tenantId, 'supplier_id' => $company['supplier']->id, 'invoice_number' => 'SUP-1', 'date' => now()->toDateString(),
        'total_amount' => '122.00', 'total_net' => '100.00', 'total_tax' => '22.00', 'currency' => $company['tenant']->default_currency, 'payment_status' => 'unpaid',
    ]);
    app(PostPassiveInvoiceAction::class)->execute($invoice);

    $response = test()->postJson('/api/v1/purchases/export-pain001', ['invoice_ids' => [$invoice->id], 'format' => 'iso_pain_001_ch'])->assertOk();

    expect($response->json('data.status'))->toBe('exported')
        ->and($response->json('data.xml_preview'))->toContain('<InstdAmt Ccy="'.$company['tenant']->default_currency.'">122.00</InstdAmt>')
        ->and($response->json('data.xml_preview'))->toContain('Ledger '.$company['tenant']->country.' SA');

    return ['batch' => PaymentBatch::findOrFail($response->json('data.batch_id')), 'account' => $account, 'invoice' => $invoice];
}

test('an exported file pays nothing until the bank confirms it', function (): void {
    $company = ledgerCompany('CH');
    $tenantId = $company['tenant']->id;
    ['batch' => $batch, 'account' => $account, 'invoice' => $invoice] = ledgerExportedBatch($company);

    expect((float) $account->refresh()->current_balance)->toEqual(1000.0)
        ->and(PassiveInvoice::findOrFail($invoice->id)->payment_status)->toBe('unpaid')
        ->and(ledgerAccountBalance($tenantId, '2000'))->toBe('-122.00');

    $this->getJson("/api/v1/treasury/payment-batches/{$batch->id}/download-xml")->assertOk();
    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/confirm", ['bank_reference' => 'BANK-77'])->assertOk()->assertJsonPath('status', 'executed');

    expect((float) $account->refresh()->current_balance)->toEqual(878.0)
        ->and(PassiveInvoice::findOrFail($invoice->id)->payment_status)->toBe('paid')
        ->and(ledgerAccountBalance($tenantId, '2000'))->toBe('0.00')
        ->and(ledgerAccountBalance($tenantId, '1021'))->toBe('-122.00');

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/confirm", ['bank_reference' => 'BANK-77'])->assertStatus(422);

    expect((float) $account->refresh()->current_balance)->toEqual(878.0);
});

test('a pain.002 settles what the bank accepted and leaves the rejected line open', function (): void {
    $company = ledgerCompany('CH');
    $tenantId = $company['tenant']->id;
    ['batch' => $batch, 'account' => $account, 'invoice' => $invoice] = ledgerExportedBatch($company);
    $line = PaymentLine::where('payment_batch_id', $batch->id)->sole();
    $report = fn (string $messageId, string $group, string $tx): string => <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.10"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>PAIN2-1</MsgId></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>{$messageId}</OrgnlMsgId><GrpSts>{$group}</GrpSts></OrgnlGrpInfAndSts>
        <OrgnlPmtInfAndSts><TxInfAndSts><OrgnlEndToEndId>{$line->end_to_end_id}</OrgnlEndToEndId><TxSts>{$tx}</TxSts></TxInfAndSts></OrgnlPmtInfAndSts>
        </CstmrPmtStsRpt></Document>
        XML;

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/pain002", ['xml' => $report('OTHER', 'ACCP', 'ACCP')])->assertStatus(422);
    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/pain002", ['xml' => $report($batch->pain001_message_id, 'ACTC', 'ACTC')])->assertOk()->assertJsonPath('status', 'exported');

    expect((float) $account->refresh()->current_balance)->toEqual(1000.0);

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/pain002", ['xml' => $report($batch->pain001_message_id, 'RJCT', 'RJCT')])->assertOk()->assertJsonPath('status', 'rejected');

    expect((float) $account->refresh()->current_balance)->toEqual(1000.0)
        ->and($line->refresh()->status)->toBe('rejected')
        ->and(ledgerAccountBalance($tenantId, '2000'))->toBe('-122.00')
        ->and(PassiveInvoice::findOrFail($invoice->id)->payment_status)->toBe('unpaid');
});

test('a batch is approved by a real signatory, not by its submission or its creator', function (): void {
    $company = ledgerCompany('CH');
    $tenantId = $company['tenant']->id;
    $factory = app(PaymentFactoryServiceInterface::class);
    $account = BankAccount::create(['tenant_id' => $tenantId, 'bank_name' => 'UBS', 'account_number' => 'U-1', 'account_type' => 'OPERATING', 'iban' => 'CH9300762011623852957', 'currency' => 'CHF', 'is_active' => true]);
    $creator = (string) Str::uuid7();
    $batch = $factory->createBatch(['tenant_id' => $tenantId, 'bank_account_id' => $account->id], [['recipient_id' => $company['supplier']->id, 'recipient_name' => 'Fornitore', 'recipient_iban' => 'CH9300762011623852957', 'amount' => 10]], $creator);

    expect($factory->submitForApproval($batch->id, $creator)->status)->toBe('pending_approval')
        ->and(fn () => $factory->exportBatch($batch->id, 'sepa_pain001', $creator))->toThrow(InvalidArgumentException::class, 'must be approved')
        ->and(fn () => $factory->approveBatch($batch->id, $creator, '10.0.0.1'))->toThrow(InvalidArgumentException::class, 'other than its creator');

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/approve")->assertOk()->assertJsonPath('status', 'approved');

    expect($batch->refresh()->approvals()->sole()->ip_address)->toBe('127.0.0.1')
        ->and($batch->approvals()->sole()->approver_user_id)->toBe($company['user']->id);

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/export")->assertOk()->assertJsonPath('status', 'exported');
});

test('missing bank details refuse the payment file', function (): void {
    $company = ledgerCompany('CH');
    $supplier = Contact::create(['tenant_id' => $company['tenant']->id, 'type' => 'supplier', 'company_name' => 'Senza IBAN', 'country' => 'CH']);
    $invoice = PassiveInvoice::create([
        'tenant_id' => $company['tenant']->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'S-2', 'date' => now()->toDateString(), 'total_amount' => '10.00', 'currency' => 'CHF', 'payment_status' => 'unpaid',
    ]);

    $this->postJson('/api/v1/purchases/export-pain001', ['invoice_ids' => [$invoice->id], 'format' => 'iso_pain_001_ch'])->assertStatus(422);
});

test('a goods receipt without a cost is refused', function (): void {
    $company = ledgerCompany('IT');

    expect(fn () => event(new GoodsReceivedEvent(
        receiptId: (string) Str::uuid7(),
        receiptNumber: 'GR-1',
        purchaseOrderId: (string) Str::uuid7(),
        supplierDeliveryNoteRef: 'DDT',
        receivedLines: [['purchase_order_line_id' => (string) Str::uuid7(), 'quantity_received' => 3.0, 'quantity_accepted' => 3.0]],
        tenantId: $company['tenant']->id,
        companyId: $company['tenant']->id,
    )))->toThrow(InvalidArgumentException::class, 'no unit cost');
});

test('a Swiss cash rounding reaches its own account and the entry balances', function (): void {
    $company = ledgerCompany('CH');
    $tenantId = $company['tenant']->id;
    $payload = ledgerInvoicePayload($company, ['items' => [['description' => 'Rounded', 'quantity' => 1, 'unit_price' => 100.03, 'tax_rate' => 8.1]]]);

    $response = $this->postJson('/api/v1/sales/invoices', $payload)->assertCreated();

    expect((float) $response->json('data.total_gross'))->toEqual(108.15)
        ->and(ledgerAccountBalance($tenantId, '1100'))->toBe('108.15')
        ->and(ledgerAccountBalance($tenantId, '6960'))->toBe('-0.02');
});
