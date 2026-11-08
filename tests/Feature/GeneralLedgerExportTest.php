<?php

declare(strict_types=1);

/*
 * The general ledger export (prima nota) reads the single ledger: the posted entries of `journal_entries`.
 *
 * Until April 2027 it rebuilt the postings from the documents with its own default accounts (receivables 1200, bank
 * 1000, revenue 4000, output VAT 2600...), which differed from the accounts of the posting services (1100, 1020, 2200)
 * and could differ in the figures too. The fixtures therefore post entries through the double-entry posting service
 * instead of creating raw documents, and the expected accounts are the ones of the ledger. What did not change, and is
 * still asserted as before: the CSV format (BOM, header, `;`, decimal commas, quoting and formula neutralisation, ISO
 * or European dates), the JSON shape, the numbering over the whole period that is stable across pages, months, filters
 * and the export, the totals and balance check, the validation, the one-year limit and the tenant isolation. The
 * `accounting.accounts.*` preferences no longer rename accounts in the export: the accounts are the ones posted.
 */

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    fiscalCalendar($this->tenant, 2026);
    fiscalCalendar($this->otherTenant, 2026);

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
    return test()->withHeader('X-Tenant-Id', $tenantId ?? test()->tenant->id);
}

/**
 * An account of the chart of a tenant: the control accounts of the posting services, or a plain one by code.
 */
function glAccount(string $code, ?string $tenantId = null, ?string $name = null): ChartOfAccount
{
    $tenantId ??= test()->tenant->id;
    $resolver = app(LedgerAccountResolver::class);

    return match ($code) {
        '1100' => $resolver->receivables($tenantId),
        '2000' => $resolver->payables($tenantId),
        '2200' => $resolver->outputVat($tenantId),
        '1400' => $resolver->inputVat($tenantId),
        '1020' => $resolver->bank($tenantId),
        default => ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            ['id' => (string) Str::uuid7(), 'name' => $name ?? "Account {$code}", 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'currency' => 'EUR', 'is_active' => true],
        ),
    };
}

/**
 * Posts an entry through the double-entry posting service, as the posting flows do.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: array<string, mixed>}>  $lines  [code, debit, credit, extra]
 * @param  array<string, mixed>  $entry  attributes of the entry (status, ledger type)
 */
function glPost(string $date, string $sourceType, string $documentNumber, array $lines, ?string $tenantId = null, array $entry = []): JournalEntry
{
    $tenantId ??= test()->tenant->id;

    return app(TenantContext::class)->runWithTenant(Tenant::findOrFail($tenantId), fn (): JournalEntry => app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate($tenantId, $date)->id,
        'source_document_type' => $sourceType,
        'source_document_id' => (string) Str::uuid7(),
        'document_number' => $documentNumber,
        'posting_date' => $date,
        'document_date' => $date,
        'journal_type' => 'standard',
        'description' => "{$sourceType} {$documentNumber}",
        'bank_balance_managed' => true,
        'lines' => array_map(static fn (array $line): array => [
            'account_id' => glAccount($line[0], $tenantId)->id,
            'debit_amount' => $line[1],
            'credit_amount' => $line[2],
            'description' => $line[3]['description'] ?? "{$documentNumber} {$line[0]}",
        ] + ($line[3] ?? []), $lines),
    ] + $entry));
}

/**
 * Entries of every kind in March 2026, one in April, and entries of another tenant that must never show up.
 */
function glSeed(): void
{
    $customer = ['partner_type' => 'customer', 'partner_id' => test()->customer->id];
    $supplier = ['partner_type' => 'supplier', 'partner_id' => test()->supplier->id];

    glAccount('4000', null, 'Sales revenue');
    glPost('2026-03-05', 'sales_invoice', 'INV-1', [['1100', '122.00', '0', $customer], ['4000', '0', '100.00', ['vat_rate_percent' => 22]], ['2200', '0', '22.00', ['vat_rate_percent' => 22]]]);
    glPost('2026-03-06', 'credit_note', 'CN-1', [['4000', '10.00', '0'], ['2200', '2.20', '0'], ['1100', '0', '12.20', $customer]]);
    glPost('2026-03-07', 'vendor_invoice', 'SUP-1', [['3000', '200.00', '0'], ['1400', '44.00', '0'], ['2000', '0', '244.00', $supplier]]);
    glPost('2026-03-10', 'customer_payment', 'INV-1', [['1020', '122.00', '0'], ['1100', '0', '122.00', $customer]]);
    glPost('2026-03-11', 'supplier_payment', 'SUP-1', [['2000', '244.00', '0', $supplier], ['1020', '0', '244.00']]);
    glPost('2026-03-12', 'bank_line_categorization', 'F24-03', [['6800', '1200.00', '0', ['description' => 'tax_payment: DELEGA F24']], ['1020', '0', '1200.00']]);
    glPost('2026-03-13', 'treasury_bank_fee', 'FEE-1', [['6220', '12.50', '0', ['description' => '=SUM(A1)']], ['1020', '0', '12.50']]);
    glPost('2026-04-02', 'bank_line_categorization', 'F24-04', [['6800', '77.00', '0'], ['1020', '0', '77.00']]);

    // The other tenant: nothing of this may leak.
    glAccount('4000', test()->otherTenant->id, 'Secret revenue account');
    glPost('2026-03-05', 'sales_invoice', 'SECRET-INV', [['1100', '6100.00', '0', ['partner_type' => 'customer', 'partner_id' => test()->foreignCustomer->id]], ['4000', '0', '6100.00']], test()->otherTenant->id);
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
// Double entry, read from the journal
// ---------------------------------------------------------------------------------------------------------------

test('every entry and the whole period balance exactly', function (): void {
    glSeed();

    $response = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->assertOk();
    $entries = $response->json('data.entries');
    $totals = $response->json('data.totals');

    expect($entries)->toHaveCount(7)->and($totals['balanced'])->toBeTrue()->and($totals['unbalanced_entries'])->toBe([]);

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

test('documents and payments show the accounts and figures posted in the journal', function (): void {
    glSeed();
    $entries = glEntries();

    $invoice = glEntry($entries, 'INV-1', 'invoice');
    expect(glLines($invoice))->toBe([['1100', 122.0, 0.0], ['4000', 0.0, 100.0], ['2200', 0.0, 22.0]])
        ->and($invoice['entry_date'])->toBe('2026-03-05')
        ->and($invoice['counterpart_name'])->toBe('Rossi SRL')
        ->and($invoice['tax_id'])->toBe('IT00811720580')
        ->and($invoice['lines'][1]['vat_code'])->toBe('22')
        ->and($invoice['lines'][1]['ledger_account_name'])->toBe('Sales revenue')
        ->and($invoice['currency'])->toBe('EUR');

    expect(glLines(glEntry($entries, 'CN-1', 'credit_note')))->toBe([['4000', 10.0, 0.0], ['2200', 2.2, 0.0], ['1100', 0.0, 12.2]])
        ->and(glLines(glEntry($entries, 'SUP-1', 'supplier_invoice')))->toBe([['3000', 200.0, 0.0], ['1400', 44.0, 0.0], ['2000', 0.0, 244.0]])
        ->and(glEntry($entries, 'SUP-1', 'supplier_invoice')['tax_id'])->toBe('FRNRPD80A01H501Z')
        ->and(glLines(glEntry($entries, 'INV-1', 'payment')))->toBe([['1020', 122.0, 0.0], ['1100', 0.0, 122.0]])
        ->and(glLines(glEntry($entries, 'SUP-1', 'supplier_payment')))->toBe([['2000', 244.0, 0.0], ['1020', 0.0, 244.0]]);
});

test('the export holds exactly the figures of the journal, account by account', function (): void {
    glSeed();

    $exported = [];

    foreach (glEntries() as $entry) {
        foreach ($entry['lines'] as $line) {
            $exported[$line['ledger_account_code']] = ($exported[$line['ledger_account_code']] ?? 0) + (int) round(($line['debit'] - $line['credit']) * 100);
        }
    }

    $journal = [];

    foreach (JournalLine::withoutGlobalScopes()->with(['account' => fn ($query) => $query->withoutGlobalScopes()])->where('tenant_id', $this->tenant->id)->whereBetween('posting_date', ['2026-03-01', '2026-03-31'])->get() as $line) {
        $journal[$line->account->account_code] = ($journal[$line->account->account_code] ?? 0) + (int) bcmul(bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), '100', 0);
    }

    ksort($exported);
    ksort($journal);
    expect($exported)->toBe($journal);
});

test('drafts, reversals and other ledgers follow the journal', function (): void {
    glSeed();
    $entry = glPost('2026-03-20', 'sales_invoice', 'INV-REV', [['1100', '50.00', '0'], ['4000', '0', '50.00']]);
    app(TenantContext::class)->runWithTenant($this->tenant, fn () => app(DoubleEntryPostingServiceInterface::class)->reverseJournal((string) $entry->id, '2026-03-21', 'Wrong customer'));
    glPost('2026-03-22', 'sales_invoice', 'INV-DRAFT', [['1100', '9.00', '0'], ['4000', '0', '9.00']], null, ['status' => 'draft']);
    glPost('2026-03-23', 'sales_invoice', 'INV-IFRS', [['1100', '8.00', '0'], ['4000', '0', '8.00']], null, ['ledger_type' => 'ifrs']);

    $entries = glEntries();

    expect(glEntry($entries, 'INV-REV', 'invoice'))->not->toBeNull()
        ->and(glLines(glEntry($entries, 'INV-REV', 'reversal')))->toBe([['1100', 0.0, 50.0], ['4000', 50.0, 0.0]])
        ->and(glEntry($entries, 'INV-DRAFT'))->toBeNull()
        ->and(glEntry($entries, 'INV-IFRS'))->toBeNull();
});

test('categorised bank lines reach the journal and the export with their category and accounts', function (): void {
    glApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['tax_account_code' => '6810']])->assertOk();
    glAccount('6810', null, 'Imposte e tasse');
    $statement = BankStatement::create(['tenant_id' => $this->tenant->id, 'source_format' => 'camt053', 'account_iban' => 'IT60X0542811101000000123456', 'statement_date' => '2026-03-31', 'currency' => 'EUR', 'total_entries' => 1, 'status' => 'draft']);
    $line = BankStatementLine::create(['tenant_id' => $this->tenant->id, 'bank_statement_id' => $statement->id, 'line_number' => 1, 'booking_date' => '2026-03-16', 'value_date' => '2026-03-16', 'amount' => 1200, 'direction' => 'debit', 'description' => 'DELEGA F24', 'reconciled' => false]);

    glApi()->postJson("/api/v1/banking/statements/lines/{$line->id}/categorize", ['category' => 'tax_payment'])->assertOk();

    $entry = collect(glEntries('&source=banking'))->firstWhere('document_type', 'tax_payment');
    expect(glLines($entry))->toBe([['6810', 1200.0, 0.0], ['1020', 0.0, 1200.0]])
        ->and($entry['lines'][0]['ledger_account_name'])->toBe('Imposte e tasse');
});

test('source, account and period filters select the entries', function (): void {
    glSeed();

    $invoices = glEntries('&source=invoices');
    expect(collect($invoices)->pluck('document_type')->unique()->sort()->values()->all())->toBe(['credit_note', 'invoice', 'supplier_invoice']);

    $payments = glEntries('&source=payments');
    expect(collect($payments)->pluck('document_type')->unique()->sort()->values()->all())->toBe(['payment', 'supplier_payment']);

    $banking = glEntries('&source=banking');
    expect(collect($banking)->pluck('document_type')->sort()->values()->all())->toBe(['bank_line', 'treasury_bank_fee']);

    expect(glEntries('&source=all'))->toHaveCount(count($invoices) + count($payments) + count($banking));

    // The account filter keeps whole entries, so they still balance.
    $fees = glEntries('&account_code=6220');
    expect($fees)->toHaveCount(1)->and($fees[0]['document_type'])->toBe('treasury_bank_fee');
    expect(collect(glEntries('&account_code=2200'))->pluck('document_number')->all())->toBe(['INV-1', 'CN-1']);

    // A period without activity is empty and still balanced.
    $empty = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2025-01-01&to_date=2025-01-31')->assertOk();
    expect($empty->json('data.entries'))->toBe([])->and($empty->json('data.totals.balanced'))->toBeTrue()->and($empty->json('data.totals.total_debit'))->toEqual(0);

    // The April entry only appears in April.
    expect(collect(glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-04-01&to_date=2026-04-30')->json('data.entries'))->pluck('document_number')->all())->toBe(['F24-04']);
});

test('the preview is paginated while the totals cover the whole period', function (): void {
    glSeed();

    $all = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->json('data');
    $page = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=3&page=2')->assertOk()->json('data');

    expect($page['entries'])->toHaveCount(3)
        ->and($page['entries'][0]['entry_number'])->toBe('000004')
        ->and($page['pagination'])->toBe(['page' => 2, 'per_page' => 3, 'total_entries' => count($all['entries']), 'last_page' => (int) ceil(count($all['entries']) / 3)])
        ->and($page['totals'])->toEqual($all['totals']);
});

test('account names come from the chart of accounts of the tenant', function (): void {
    glSeed();
    ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('account_code', '4000')->update(['name' => 'Ricavi vendite']);

    $entries = glEntries();
    expect(glEntry($entries, 'INV-1', 'invoice')['lines'][1]['ledger_account_name'])->toBe('Ricavi vendite')
        ->and(glEntry($entries, 'INV-1', 'invoice')['lines'][0]['ledger_account_name'])->toBe('Accounts Receivable (Crediti v/Clienti)');
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
    $invoiceLine = collect($lines)->first(fn (string $line): bool => str_contains($line, ';INV-1;') && str_contains($line, ';1100;') && str_contains($line, ';invoice;'));
    expect(str_getcsv($invoiceLine, ';', '"', ''))->toMatchArray([1 => '2026-03-05', 2 => 'invoice', 3 => 'INV-1', 7 => '1100', 10 => '122,00', 11 => '0,00']);

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
    foreach (['SECRET', 'Stranger', 'Secret revenue account', '6100,00'] as $leak) {
        expect($csv)->not->toContain($leak);
    }

    $own = glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->json('data');

    $other = glApi($this->otherTenant->id)->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500')->assertOk()->json('data');
    $document = collect($other['entries'])->pluck('document_number')->all();

    expect($document)->toBe(['SECRET-INV'])
        ->and($other['totals']['balanced'])->toBeTrue()
        ->and($other['totals']['total_debit'])->not->toEqual($own['totals']['total_debit']);

    expect(collect($other['entries'])->firstWhere('document_number', 'SECRET-INV')['lines'][1]['ledger_account_name'])->toBe('Secret revenue account')
        ->and(glEntry($own['entries'], 'INV-1', 'invoice')['lines'][1]['ledger_account_name'])->toBe('Sales revenue');
});

// ---------------------------------------------------------------------------------------------------------------
// Long periods
// ---------------------------------------------------------------------------------------------------------------

test('entry numbers run over the whole period, month after month, and do not change between pages, filters and the export', function (): void {
    glAccount('4000', null, 'Sales revenue');
    glPost('2026-01-20', 'sales_invoice', 'JAN-2', [['1100', '10.00', '0'], ['4000', '0', '10.00']]);
    glPost('2026-01-05', 'sales_invoice', 'JAN-1', [['1100', '10.00', '0'], ['4000', '0', '10.00']]);
    glPost('2026-02-10', 'sales_invoice', 'FEB-1', [['1100', '10.00', '0'], ['4000', '0', '10.00']]);
    glPost('2026-02-28', 'customer_payment', 'MAR-1', [['1020', '99.00', '0'], ['6220', '1.00', '0'], ['1100', '0', '100.00']]);
    glPost('2026-03-03', 'sales_invoice', 'MAR-1', [['1100', '100.00', '0'], ['4000', '0', '100.00']]);

    $period = 'from_date=2026-01-01&to_date=2026-03-31';
    $paged = [];

    foreach ([1, 2, 3] as $page) {
        $response = glApi()->getJson("/api/v1/accounting/general-ledger?{$period}&per_page=2&page={$page}")->assertOk()->json('data');
        $paged = [...$paged, ...$response['entries']];

        expect($response['pagination']['total_entries'])->toBe(5)
            ->and($response['pagination']['last_page'])->toBe(3)
            ->and($response['totals']['entries'])->toBe(5)
            ->and($response['totals']['balanced'])->toBeTrue();
    }

    $exported = glApi()->getJson("/api/v1/accounting/general-ledger/export?{$period}&format=json")->assertOk()->json('data.entries');
    $paymentsOnly = glApi()->getJson("/api/v1/accounting/general-ledger?{$period}&per_page=500&source=payments")->assertOk()->json('data.entries');
    $numbers = static fn (array $entries): array => array_map(static fn (array $entry): string => $entry['entry_number'].' '.$entry['document_number'].' '.$entry['entry_date'], $entries);

    expect($numbers($paged))->toBe([
        '000001 JAN-1 2026-01-05',
        '000002 JAN-2 2026-01-20',
        '000003 FEB-1 2026-02-10',
        '000004 MAR-1 2026-02-28',
        '000005 MAR-1 2026-03-03',
    ])
        ->and($numbers($exported))->toBe($numbers($paged))
        ->and(glLines($paged[3]))->toBe([['1020', 99.0, 0.0], ['6220', 1.0, 0.0], ['1100', 0.0, 100.0]])
        ->and($numbers($paymentsOnly))->toBe(['000001 MAR-1 2026-02-28']);

    $csv = glApi()->get("/api/v1/accounting/general-ledger/export?{$period}")->assertOk()->streamedContent();
    expect(substr_count($csv, "\r\n"))->toBe(1 + collect($exported)->sum(static fn (array $entry): int => count($entry['lines'])));
});

test('the preview and the export cover at most one year', function (): void {
    glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-01-01&to_date=2026-12-31')->assertOk();
    glApi()->getJson('/api/v1/accounting/general-ledger/export?from_date=2026-01-01&to_date=2026-12-31&format=json')->assertOk();

    glApi()->getJson('/api/v1/accounting/general-ledger?from_date=2026-01-01&to_date=2027-01-01')->assertUnprocessable()->assertJsonValidationErrors('to_date');
    glApi()->getJson('/api/v1/accounting/general-ledger/export?from_date=2025-01-01&to_date=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('to_date');
});
