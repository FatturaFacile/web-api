<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi SRL', 'email' => 'rossi@example.com', 'type' => 'customer', 'country' => 'IT', 'vat_number' => 'IT00811720580']);
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore "Rapido"; SPA', 'email' => 'f@example.com', 'type' => 'supplier', 'country' => 'IT', 'fiscal_code' => 'FRNRPD80A01H501Z']);
    $this->foreignCustomer = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Stranger Ltd', 'email' => 'stranger@secret.test', 'type' => 'customer', 'country' => 'IT']);
});

// ---------------------------------------------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------------------------------------------

function glApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function glInvoice(string $number, string $date, float $net, float $tax, ?float $gross = null, array $overrides = []): Invoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return Invoice::create(array_merge([
        'tenant_id' => $tenantId, 'contact_id' => $tenantId === test()->tenant->id ? test()->customer->id : test()->foreignCustomer->id,
        'invoice_number' => $number, 'date' => $date, 'currency' => 'EUR', 'total_net' => $net, 'total_tax' => $tax,
        'total_gross' => $gross ?? round($net + $tax, 2), 'invoice_type' => 'standard',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function glPassive(string $number, string $date, float $net, float $tax, array $overrides = []): PassiveInvoice
{
    return PassiveInvoice::create(array_merge([
        'tenant_id' => test()->tenant->id, 'supplier_id' => test()->supplier->id, 'invoice_number' => $number, 'date' => $date, 'currency' => 'EUR',
        'total_net' => $net, 'total_tax' => $tax, 'total_amount' => round($net + $tax, 2), 'document_type' => 'TD01',
    ], $overrides));
}

function glBankLine(BankStatement $statement, int $number, string $category, float $amount, string $direction = 'debit', array $overrides = []): BankStatementLine
{
    return BankStatementLine::create(array_merge([
        'tenant_id' => $statement->tenant_id, 'bank_statement_id' => $statement->id, 'line_number' => $number, 'booking_date' => '2026-03-12', 'value_date' => '2026-03-12',
        'amount' => $amount, 'direction' => $direction, 'description' => "Line {$number}", 'reconciled' => true, 'reconciliation_type' => 'auto_categorization', 'reconciliation_category' => $category,
    ], $overrides));
}

/**
 * Every kind of source in March 2026, plus data of another tenant that must never show up.
 */
function glSeed(): void
{
    $tenant = test()->tenant;

    $sale = glInvoice('INV-1', '2026-03-05', 100, 22);
    InvoiceItem::create(['invoice_id' => $sale->id, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]);
    InvoiceItem::create(['invoice_id' => $sale->id, 'description' => 'Books', 'quantity' => 1, 'unit_price' => 0, 'tax_rate' => 4, 'line_total' => 0]);
    glInvoice('CN-1', '2026-03-06', 10, 2.2, null, ['invoice_type' => 'credit_note']);
    glInvoice('INV-ROUND', '2026-03-07', 100, 22, 122.05);
    glInvoice('PRO-1', '2026-03-08', 999, 0, null, ['invoice_type' => 'proforma']);
    glInvoice('INV-OLD', '2026-02-20', 50, 0);

    glPassive('SUP-1', '2026-03-07', 200, 44);
    glPassive('SUP-CN', '2026-03-09', 20, 4.4, ['document_type' => 'TD04']);

    $received = glInvoice('INV-PAID', '2026-03-01', 150, 0);
    $line = (string) Str::uuid();
    Payment::create(['tenant_id' => $tenant->id, 'invoice_id' => $received->id, 'amount' => 147.50, 'paid_at' => '2026-03-10', 'type' => 'payment', 'bank_statement_line_id' => $line]);
    Payment::create(['tenant_id' => $tenant->id, 'invoice_id' => $received->id, 'amount' => 2.50, 'paid_at' => '2026-03-10', 'type' => 'bank_fee', 'bank_statement_line_id' => $line]);
    Payment::create(['tenant_id' => $tenant->id, 'invoice_id' => $sale->id, 'amount' => 50, 'paid_at' => '2026-03-11']);

    $payable = glPassive('SUP-2', '2026-03-02', 100, 0);
    $supplierLine = (string) Str::uuid();
    SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $payable->id, 'amount' => 99.00, 'paid_at' => '2026-03-10', 'type' => 'payment', 'bank_statement_line_id' => $supplierLine]);
    SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $payable->id, 'amount' => 1.50, 'paid_at' => '2026-03-10', 'type' => 'bank_fee', 'bank_statement_line_id' => $supplierLine]);
    SupplierPayment::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $payable->id, 'amount' => 0.50, 'paid_at' => '2026-03-10', 'type' => 'adjustment', 'bank_statement_line_id' => $supplierLine]);

    $statement = BankStatement::create(['tenant_id' => $tenant->id, 'source_format' => 'camt053', 'account_iban' => 'IT60X0542811101000000123456', 'statement_date' => '2026-03-31', 'currency' => 'EUR', 'total_entries' => 8, 'status' => 'draft']);
    glBankLine($statement, 1, 'tax_payment', 1200.00, 'debit', ['description' => 'DELEGA F24']);
    glBankLine($statement, 2, 'payroll', 2500.00, 'debit', ['description' => 'STIPENDI', 'ledger_account_code' => '6010', 'reconciliation_type' => 'manual_categorization']);
    glBankLine($statement, 3, 'bank_expense', 12.50, 'debit', ['description' => 'COMPETENZE']);
    glBankLine($statement, 4, 'internal_transfer', 900.00, 'debit', ['description' => 'To savings', 'ledger_account_code' => '1010']);
    glBankLine($statement, 5, 'internal_transfer', 300.00, 'credit', ['description' => 'From savings']);
    glBankLine($statement, 6, 'generic_expense', 30.00, 'debit', ['description' => 'Office', 'counterpart_name' => '=SUM(A1)']);
    glBankLine($statement, 7, 'invoice', 99.00, 'debit', ['reconciliation_type' => 'exact']);
    glBankLine($statement, 8, 'tax_payment', 77.00, 'debit', ['booking_date' => '2026-04-02']);

    // The other tenant: nothing of this may leak.
    $foreign = glInvoice('SECRET-INV', '2026-03-05', 5000, 1100, null, ['tenant_id' => test()->otherTenant->id]);
    Payment::create(['tenant_id' => test()->otherTenant->id, 'invoice_id' => $foreign->id, 'amount' => 6100, 'paid_at' => '2026-03-10']);
    $foreignStatement = BankStatement::create(['tenant_id' => test()->otherTenant->id, 'source_format' => 'cbi', 'account_iban' => 'CH4431999123000889012', 'statement_date' => '2026-03-31', 'currency' => 'EUR', 'total_entries' => 1, 'status' => 'draft']);
    glBankLine($foreignStatement, 1, 'payroll', 4000.00, 'debit', ['description' => 'SECRET PAYROLL']);
    DB::table('chart_of_accounts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => test()->otherTenant->id, 'code' => '4000', 'name' => 'Secret revenue account', 'type' => 'revenue', 'standard' => 'it_general_ledger', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function glEntries(string $query = ''): array
{
    return glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500'.$query)->assertOk()->json('data.entries');
}

function glEntry(array $entries, string $documentNumber, ?string $type = null): ?array
{
    return collect($entries)->first(fn (array $entry): bool => $entry['document_number'] === $documentNumber && ($type === null || $entry['document_type'] === $type));
}

/**
 * @return array<int, array{string, float, float}>
 */
function glLines(array $entry): array
{
    return array_map(fn (array $line): array => [$line['ledger_account_code'], (float) $line['debit'], (float) $line['credit']], $entry['lines']);
}

// ---------------------------------------------------------------------------------------------------------------
// Double entry
// ---------------------------------------------------------------------------------------------------------------

test('every entry and the whole period balance exactly', function (): void {
    glSeed();

    $response = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->assertOk();
    $entries = $response->json('data.entries');
    $totals = $response->json('data.totals');

    expect($entries)->not->toBeEmpty()->and($totals['balanced'])->toBeTrue()->and($totals['unbalanced_entries'])->toBe([]);

    $debit = 0;
    $credit = 0;

    foreach ($entries as $entry) {
        $entryDebit = (int) round(array_sum(array_column($entry['lines'], 'debit')) * 100);
        $entryCredit = (int) round(array_sum(array_column($entry['lines'], 'credit')) * 100);

        expect($entryDebit)->toBe($entryCredit, "entry {$entry['entry_number']} ({$entry['document_type']} {$entry['document_number']}) does not balance");
        $debit += $entryDebit;
        $credit += $entryCredit;
    }

    expect($debit)->toBe($credit)
        ->and($totals['total_debit'])->toEqual($debit / 100)
        ->and($totals['total_credit'])->toEqual($credit / 100)
        ->and($totals['entries'])->toBe(count($entries))
        ->and(array_column($entries, 'entry_number'))->toBe(array_map(fn (int $i): string => sprintf('%06d', $i), range(1, count($entries))));
});

test('sales invoices and credit notes post receivables, revenue and output VAT', function (): void {
    glSeed();
    $entries = glEntries();

    $invoice = glEntry($entries, 'INV-1', 'invoice');
    expect(glLines($invoice))->toBe([['1200', 122.0, 0.0], ['4000', 0.0, 100.0], ['2600', 0.0, 22.0]])
        ->and($invoice['entry_date'])->toBe('2026-03-05')
        ->and($invoice['counterpart_name'])->toBe('Rossi SRL')
        ->and($invoice['tax_id'])->toBe('IT00811720580')
        ->and($invoice['lines'][1]['vat_code'])->toBe('22/4')
        ->and($invoice['lines'][0]['ledger_account_name'])->toBe('Trade receivables');

    $creditNote = glEntry($entries, 'CN-1', 'credit_note');
    expect(glLines($creditNote))->toBe([['1200', 0.0, 12.2], ['4000', 10.0, 0.0], ['2600', 2.2, 0.0]]);

    // A total that differs from net plus tax goes to the rounding account.
    expect(glLines(glEntry($entries, 'INV-ROUND')))->toBe([['1200', 122.05, 0.0], ['4000', 0.0, 100.0], ['2600', 0.0, 22.0], ['7900', 0.0, 0.05]]);

    // Proformas and invoices outside the period are not posted.
    expect(glEntry($entries, 'PRO-1'))->toBeNull()->and(glEntry($entries, 'INV-OLD'))->toBeNull();
});

test('supplier invoices post costs and input VAT against payables', function (): void {
    glSeed();
    $entries = glEntries();

    $invoice = glEntry($entries, 'SUP-1', 'supplier_invoice');
    expect(glLines($invoice))->toBe([['3000', 200.0, 0.0], ['1600', 44.0, 0.0], ['2000', 0.0, 244.0]])
        ->and($invoice['tax_id'])->toBe('FRNRPD80A01H501Z');

    expect(glLines(glEntry($entries, 'SUP-CN', 'supplier_credit_note')))->toBe([['3000', 0.0, 20.0], ['1600', 0.0, 4.4], ['2000', 24.4, 0.0]]);
});

test('customer payments post bank, fees and write-offs against receivables', function (): void {
    glSeed();
    $entries = glEntries();

    expect(glLines(glEntry($entries, 'INV-PAID', 'payment')))->toBe([['1000', 147.5, 0.0], ['6220', 2.5, 0.0], ['1200', 0.0, 150.0]]);

    $simple = glEntry($entries, 'INV-1', 'payment');
    expect(glLines($simple))->toBe([['1000', 50.0, 0.0], ['1200', 0.0, 50.0]])
        ->and($simple['entry_date'])->toBe('2026-03-11');
});

test('supplier payments post payables, bank charges, bank and rounding income', function (): void {
    glSeed();
    $entry = glEntry(glEntries(), 'SUP-2', 'supplier_payment');

    expect(glLines($entry))->toBe([['2000', 99.5, 0.0], ['6220', 1.5, 0.0], ['1000', 0.0, 100.5], ['7900', 0.0, 0.5]]);
});

test('categorised bank lines post taxes, payroll, bank expenses, transfers and other expenses', function (): void {
    glSeed();
    $entries = collect(glEntries('&source=banking'));

    expect($entries->pluck('document_type')->all())->toBe(['tax_payment', 'payroll', 'bank_expense', 'internal_transfer', 'internal_transfer', 'generic_expense']);

    $lines = $entries->map(fn (array $entry): array => glLines($entry))->all();
    expect($lines[0])->toBe([['6800', 1200.0, 0.0], ['1000', 0.0, 1200.0]])
        ->and($lines[1])->toBe([['6010', 2500.0, 0.0], ['1000', 0.0, 2500.0]])
        ->and($lines[2])->toBe([['6220', 12.5, 0.0], ['1000', 0.0, 12.5]])
        ->and($lines[3])->toBe([['1010', 900.0, 0.0], ['1000', 0.0, 900.0]])
        ->and($lines[4])->toBe([['1000', 300.0, 0.0], ['1090', 0.0, 300.0]])
        ->and($lines[5])->toBe([['6900', 30.0, 0.0], ['1000', 0.0, 30.0]]);

    // Lines matched with an invoice are not repeated and lines outside the period are not posted.
    expect($entries->pluck('description', 'document_type')->all())->not->toBeEmpty();
    expect(collect(glEntries())->where('document_number', '')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Filters, preview and preferences
// ---------------------------------------------------------------------------------------------------------------

test('source, account and period filters select the entries', function (): void {
    glSeed();

    $invoices = glEntries('&source=invoices');
    expect(collect($invoices)->pluck('document_type')->unique()->sort()->values()->all())->toBe(['credit_note', 'invoice', 'supplier_credit_note', 'supplier_invoice']);

    $payments = glEntries('&source=payments');
    expect(collect($payments)->pluck('document_type')->unique()->sort()->values()->all())->toBe(['payment', 'supplier_payment']);

    expect(glEntries('&source=all'))->toHaveCount(count($invoices) + count($payments) + 6);

    // The account filter keeps whole entries, so they still balance.
    $payroll = glEntries('&account_code=6010');
    expect($payroll)->toHaveCount(1)->and($payroll[0]['document_type'])->toBe('payroll');
    expect(collect(glEntries('&account_code=2600'))->pluck('document_number')->all())->toBe(['INV-1', 'CN-1', 'INV-ROUND']);

    // A period without activity is empty and still balanced.
    $empty = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2025-01-01&to_date=2025-01-31')->assertOk();
    expect($empty->json('data.entries'))->toBe([])->and($empty->json('data.totals.balanced'))->toBeTrue()->and($empty->json('data.totals.total_debit'))->toEqual(0);

    // The April bank line only appears in April.
    expect(collect(glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-04-01&to_date=2026-04-30')->json('data.entries'))->pluck('document_type')->all())->toBe(['tax_payment']);
});

test('the preview is paginated while the totals cover the whole period', function (): void {
    glSeed();

    $all = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->json('data');
    $page = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=4&page=2')->assertOk()->json('data');

    expect($page['entries'])->toHaveCount(4)
        ->and($page['entries'][0]['entry_number'])->toBe('000005')
        ->and($page['pagination'])->toBe(['page' => 2, 'per_page' => 4, 'total_entries' => count($all['entries']), 'last_page' => (int) ceil(count($all['entries']) / 4)])
        ->and($page['totals'])->toEqual($all['totals']);
});

test('ledger accounts follow the tenant preferences and the chart of accounts', function (): void {
    glSeed();
    DB::table('chart_of_accounts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'code' => '4000', 'name' => 'Ricavi vendite', 'type' => 'revenue', 'standard' => 'it_general_ledger', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    expect(glEntry(glEntries(), 'INV-1', 'invoice')['lines'][1]['ledger_account_name'])->toBe('Ricavi vendite');

    glApi()->putJson('/api/v1/settings/preferences/accounting', ['accounts' => ['revenue_account_code' => 'bad code']])->assertUnprocessable();
    glApi()->putJson('/api/v1/settings/preferences/accounting', ['accounts' => ['revenue_account_code' => '5100', 'receivables_account_code' => '1250', 'bank_account_code' => '1010.1']])->assertOk();
    glApi()->putJson('/api/v1/settings/preferences/accounting', ['accounts' => ['output_vat_account_code' => '2650']])->assertOk();
    glApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['tax_account_code' => '6810']])->assertOk();

    expect(glApi()->getJson('/api/v1/settings/preferences')->json('data.accounting.accounts'))->toHaveCount(4);

    $entries = glEntries();
    expect(glLines(glEntry($entries, 'INV-1', 'invoice')))->toBe([['1250', 122.0, 0.0], ['5100', 0.0, 100.0], ['2650', 0.0, 22.0]])
        ->and(glLines(glEntry($entries, 'INV-PAID', 'payment'))[0][0])->toBe('1010.1')
        ->and(glLines(collect($entries)->firstWhere('document_type', 'tax_payment'))[0][0])->toBe('6810');
});

// ---------------------------------------------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------------------------------------------

test('the CSV uses a BOM, semicolons, decimal commas and the standard header', function (): void {
    glSeed();

    $response = glApi()->get('/api/v1/accounting/general-ledger/export?from_date=2026-03-01&to_date=2026-03-31')->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('general-ledger-2026-03-01-2026-03-31.csv')
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();

    $lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));
    expect($lines[0])->toBe('entry_number;entry_date;document_type;document_number;document_date;counterpart_name;tax_id;ledger_account_code;ledger_account_name;description;debit;credit;currency;vat_code');

    $first = str_getcsv($lines[1], ';', '"', '');
    expect($first)->toHaveCount(14)
        ->and($first[0])->toBe('000001')
        ->and($first[1])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($first[10])->toMatch('/^\d+,\d{2}$/')
        ->and($first[11])->toMatch('/^\d+,\d{2}$/')
        ->and($first[12])->toBe('EUR');

    // The invoice line: receivables 122,00 on the debit side.
    $invoiceLine = collect($lines)->first(fn (string $line): bool => str_contains($line, ';INV-1;') && str_contains($line, ';1200;'));
    expect(str_getcsv($invoiceLine, ';', '"', ''))->toMatchArray([1 => '2026-03-05', 2 => 'invoice', 3 => 'INV-1', 7 => '1200', 10 => '122,00', 11 => '0,00']);

    // Every entry balances in the file as well, reading the decimal commas back.
    $sums = [];

    foreach (array_slice($lines, 1) as $line) {
        $cells = str_getcsv($line, ';', '"', '');
        expect($cells)->toHaveCount(14);
        $sums[$cells[0]] = ($sums[$cells[0]] ?? 0) + (int) round((float) str_replace(',', '.', $cells[10]) * 100) - (int) round((float) str_replace(',', '.', $cells[11]) * 100);
    }

    expect(array_filter($sums))->toBe([])->and(count($sums))->toBe(glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->json('data.totals.entries'));
});

test('CSV cells are quoted and neutralised and dates can be written the European way', function (): void {
    glSeed();

    $csv = glApi()->get('/api/v1/accounting/general-ledger/export?from_date=2026-03-01&to_date=2026-03-31&date_format=DD/MM/YYYY&source=invoices')->assertOk()->streamedContent();

    expect($csv)->toContain(';05/03/2026;invoice;INV-1;05/03/2026;')
        ->and($csv)->toContain('"Fornitore ""Rapido""; SPA"');

    $banking = glApi()->get('/api/v1/accounting/general-ledger/export?from_date=2026-03-01&to_date=2026-03-31&source=banking')->assertOk()->streamedContent();
    expect($banking)->toContain(";'=SUM(A1);")->and($banking)->not->toContain(';=SUM(A1);');
});

test('the export can return JSON and validates its parameters', function (): void {
    glSeed();

    $json = glApi()->getJson('/api/v1/accounting/general-ledger/export?from_date=2026-03-01&to_date=2026-03-31&format=json')->assertOk();
    expect($json->json('data.totals.balanced'))->toBeTrue()
        ->and($json->json('data.entries'))->toHaveCount(count(glEntries()));

    foreach (['', '?from_date=2026-03-01', '?to_date=2026-03-31', '?from_date=2026-03-31&to_date=2026-03-01', '?from_date=31/03/2026&to_date=2026-04-01',
        '?from_date=2026-03-01&to_date=2026-03-31&source=everything', '?from_date=2026-03-01&to_date=2026-03-31&date_format=MM/DD/YYYY',
        '?from_date=2026-03-01&to_date=2026-03-31&format=xml', '?from_date=2026-03-01&to_date=2026-03-31&account_code=bad%20code',
        '?from_date=2026-03-01&to_date=2026-03-31&per_page=9999'] as $query) {
        glApi()->getJson("/api/v1/accounting/general-ledger/export{$query}")->assertUnprocessable();
        glApi()->getJson("/api/v1/accounting/general-ledger{$query}")->assertUnprocessable();
    }
});

// ---------------------------------------------------------------------------------------------------------------
// Isolation
// ---------------------------------------------------------------------------------------------------------------

test('data, accounts and movements of other tenants never appear', function (): void {
    glSeed();

    $csv = glApi()->get('/api/v1/accounting/general-ledger/export?from_date=2026-03-01&to_date=2026-03-31')->assertOk()->streamedContent();
    foreach (['SECRET', 'Stranger', 'Secret revenue account', '6100,00', '4000,00'] as $leak) {
        expect($csv)->not->toContain($leak);
    }

    $own = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->json('data');

    $other = glApi($this->otherTenant->id)->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->assertOk()->json('data');
    $document = collect($other['entries'])->pluck('document_number')->all();

    expect($document)->toContain('SECRET-INV')
        ->and($document)->not->toContain('INV-1')
        ->and($other['totals']['balanced'])->toBeTrue()
        ->and($other['totals']['total_debit'])->not->toEqual($own['totals']['total_debit']);

    $revenue = collect($other['entries'])->firstWhere('document_number', 'SECRET-INV')['lines'][1];
    expect($revenue['ledger_account_name'])->toBe('Secret revenue account');

    // The first tenant keeps its own standard account name.
    expect(glEntry($own['entries'], 'INV-1', 'invoice')['lines'][1]['ledger_account_name'])->toBe('Sales revenue');
});
