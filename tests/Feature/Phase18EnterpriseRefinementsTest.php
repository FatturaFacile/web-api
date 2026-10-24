<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordSupplierPayment;
use App\Exceptions\InvalidBackupArchiveException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const P18_IBAN_IT = 'IT60X0542811101000000123456';
const P18_IBAN_CH = 'CH4431999123000889012';
const P18_IBAN_UNKNOWN = 'DE89370400440532013000';

beforeEach(function (): void {
    Storage::fake('private');
    Storage::fake('local');
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore SPA', 'email' => 'f@example.com', 'type' => 'supplier', 'country' => 'IT']);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi SRL', 'email' => 'r@example.com', 'type' => 'customer', 'country' => 'IT']);
});

function p18Api(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function p18Passive(string $number, float $amount, array $overrides = []): PassiveInvoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return PassiveInvoice::create(array_merge([
        'tenant_id' => $tenantId, 'supplier_id' => $tenantId === test()->tenant->id ? test()->supplier->id : Contact::create(['tenant_id' => $tenantId, 'company_name' => 'Foreign supplier', 'email' => 'x@example.com', 'type' => 'supplier', 'country' => 'IT'])->id,
        'invoice_number' => $number, 'date' => '2026-01-10', 'due_date' => '2026-02-10', 'currency' => 'EUR', 'total_amount' => $amount, 'total_net' => $amount, 'total_tax' => 0,
        'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ], $overrides));
}

function p18Statement(string $iban, int $lines = 1): BankStatement
{
    $statement = BankStatement::create(['tenant_id' => test()->tenant->id, 'source_format' => 'camt053', 'account_iban' => $iban, 'statement_date' => '2026-03-10', 'currency' => 'EUR', 'total_entries' => $lines, 'status' => 'draft']);

    foreach (range(1, $lines) as $number) {
        BankStatementLine::create(['tenant_id' => $statement->tenant_id, 'bank_statement_id' => $statement->id, 'line_number' => $number, 'booking_date' => '2026-03-10', 'value_date' => '2026-03-10', 'amount' => 10, 'direction' => 'debit', 'reconciled' => true, 'reconciliation_type' => 'exact']);
    }

    return $statement;
}

/**
 * @return array<int, array<string, mixed>>
 */
function p18Ledger(string $query = ''): array
{
    return p18Api()->getJson('/api/v1/accounting/general-ledger?from_date=2026-03-01&to_date=2026-03-31&per_page=500'.$query)->assertOk()->json('data.entries');
}

/**
 * @param  array<string, mixed>  $entry
 * @return array<int, array{string, float, float}>
 */
function p18Lines(array $entry): array
{
    return array_map(fn (array $line): array => [$line['ledger_account_code'], (float) $line['debit'], (float) $line['credit']], $entry['lines']);
}

// ---------------------------------------------------------------------------------------------------------------
// Supplier fee
// ---------------------------------------------------------------------------------------------------------------

test('a supplier bank fee does not reduce the invoice debt and balances the general ledger', function (): void {
    $invoice = p18Passive('SUP-100', 100.00);
    $recorder = app(RecordSupplierPayment::class);
    $line = (string) Str::uuid();
    $common = ['paid_at' => '2026-03-10', 'bank_statement_line_id' => $line];

    $recorder->execute($invoice, $common + ['amount' => 100.00, 'type' => 'payment']);
    $recorder->execute($invoice, $common + ['amount' => 3.00, 'type' => 'bank_fee']);

    $invoice->refresh();
    expect((float) $invoice->total_paid)->toBe(100.0)
        ->and($invoice->payment_status)->toBe('paid')
        ->and($invoice->is_reconciled)->toBeTrue()
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->count())->toBe(2);

    // The fee is accepted even though the invoice has nothing left to pay, but a real payment is not.
    expect(fn () => $recorder->execute($invoice, $common + ['amount' => 1.00, 'type' => 'payment']))->toThrow(ValidationException::class);

    $entry = collect(p18Ledger('&source=payments'))->firstWhere('document_type', 'supplier_payment');
    expect(p18Lines($entry))->toBe([['2000', 100.0, 0.0], ['6220', 3.0, 0.0], ['1000', 0.0, 103.0]])
        ->and((float) $entry['total_debit'])->toBe(103.0)
        ->and((float) $entry['total_credit'])->toBe(103.0);

    // Removing the payment and the fee together restores the debt (the fee never counted).
    SupplierPayment::withoutGlobalScopes()->where('bank_statement_line_id', $line)->where('type', 'payment')->delete();
    expect((float) $recorder->recalculate($invoice)->total_paid)->toBe(0.0);
});

test('a debit line with a fee settles the supplier invoice by its net amount alone', function (): void {
    $invoice = p18Passive('SUP-200', 150.00);
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>S1</Id><Acct><Id><IBAN>'.P18_IBAN_IT.'</IBAN></Id></Acct>'
        .'<Ntry><Amt Ccy="EUR">150.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-03-10</Dt></BookgDt><ValDt><Dt>2026-03-10</Dt></ValDt><Chrgs><Amt Ccy="EUR">2.50</Amt></Chrgs>'
        .'<NtryDtls><TxDtls><Refs><EndToEndId>E2E-1</EndToEndId></Refs><RmtInf><Ustrd>Pay SUP-200</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry></Stmt></BkToCstmrStmt></Document>';

    $response = p18Api()->post('/api/v1/banking/statements/import?auto_reconcile=1', ['file' => UploadedFile::fake()->createWithContent('s.xml', $xml)], ['Accept' => 'application/json'])->assertCreated();

    expect($response->json('data.statement.status'))->toBe('reconciled')
        ->and($response->json('data.statement.lines.0.reconciliation_type'))->toBe('exact');

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(150.0)
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->where('type', 'bank_fee')->value('amount'))->toEqual(2.5);
});

// ---------------------------------------------------------------------------------------------------------------
// Multi-bank ledger mapping
// ---------------------------------------------------------------------------------------------------------------

test('bank lines use the ledger account mapped to the IBAN of their statement', function (): void {
    p18Api()->putJson('/api/v1/settings/preferences/accounting', ['bank_accounts' => [P18_IBAN_IT => '1001', 'ch44 3199 9123 0008 8901 2' => '1002']])->assertOk();
    p18Api()->putJson('/api/v1/settings/preferences/accounting', ['bank_accounts' => ['not an iban' => '1003']])->assertUnprocessable();
    p18Api()->putJson('/api/v1/settings/preferences/accounting', ['bank_accounts' => [P18_IBAN_UNKNOWN => 'bad code!']])->assertUnprocessable();

    expect(p18Api()->getJson('/api/v1/settings/preferences')->json('data.accounting.bank_accounts'))->toBe([P18_IBAN_IT => '1001', P18_IBAN_CH => '1002']);

    $italy = p18Statement(P18_IBAN_IT);
    $swiss = p18Statement(P18_IBAN_CH);
    $unknown = p18Statement(P18_IBAN_UNKNOWN);
    $lineOf = fn (BankStatement $statement): string => (string) BankStatementLine::withoutGlobalScopes()->where('bank_statement_id', $statement->id)->value('id');

    $sale = Invoice::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'invoice_number' => 'INV-1', 'date' => '2026-03-01', 'currency' => 'EUR', 'total_net' => 300, 'total_tax' => 0, 'total_gross' => 300, 'invoice_type' => 'standard']);
    foreach ([['INV-A', $italy], ['INV-B', $swiss], ['INV-C', $unknown]] as [$number, $statement]) {
        Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $sale->id, 'amount' => 100, 'paid_at' => '2026-03-10', 'reference' => $number, 'bank_statement_line_id' => $lineOf($statement)]);
    }
    // A payment without a bank line keeps the default bank account.
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $sale->id, 'amount' => 20, 'paid_at' => '2026-03-11']);

    $payable = p18Passive('SUP-1', 80.00);
    SupplierPayment::create(['tenant_id' => $this->tenant->id, 'passive_invoice_id' => $payable->id, 'amount' => 80, 'paid_at' => '2026-03-12', 'type' => 'payment', 'bank_statement_line_id' => $lineOf($swiss)]);

    BankStatementLine::withoutGlobalScopes()->where('bank_statement_id', $swiss->id)->update(['reconciliation_type' => 'auto_categorization', 'reconciliation_category' => 'payroll', 'booking_date' => '2026-03-13']);

    $entries = p18Ledger();
    $bankOf = fn (array $entry): array => collect(p18Lines($entry))->pluck(0)->intersect(['1000', '1001', '1002'])->values()->all();

    $receipts = collect($entries)->where('document_type', 'payment')->values();
    expect($receipts->map($bankOf)->all())->toBe([['1001'], ['1002'], ['1000'], ['1000']]);

    expect($bankOf(collect($entries)->firstWhere('document_type', 'supplier_payment')))->toBe(['1002'])
        ->and($bankOf(collect($entries)->firstWhere('document_type', 'payroll')))->toBe(['1002']);

    $names = collect($entries)->pluck('lines')->flatten(1)->pluck('ledger_account_name', 'ledger_account_code');
    expect($names['1001'])->toBe('Bank '.P18_IBAN_IT)->and($names['1000'])->toBe('Bank');

    // Unmapping an IBAN sends its movements back to the default account.
    p18Api()->putJson('/api/v1/settings/preferences/accounting', ['bank_accounts' => [P18_IBAN_IT => null]])->assertOk();
    expect(collect(p18Ledger())->where('document_type', 'payment')->values()->map($bankOf)->all())->toBe([['1000'], ['1002'], ['1000'], ['1000']]);
});

// ---------------------------------------------------------------------------------------------------------------
// Safety snapshot
// ---------------------------------------------------------------------------------------------------------------

test('every restore takes a safety snapshot first and keeps only the latest three apart from regular backups', function (): void {
    $service = app(TenantBackupService::class);
    $restore = app(TenantRestoreService::class);
    Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Original contact', 'email' => 'original@example.com', 'type' => 'customer', 'country' => 'IT']);

    $regular = [];
    foreach (range(1, 7) as $i) {
        $this->travelTo(now()->addMinutes(5));
        $regular[] = $service->generate($service->create($this->tenant, $this->user))->refresh();
    }

    $snapshots = [];

    foreach (range(1, 4) as $i) {
        $this->travelTo(now()->addMinutes(5));
        Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => "Added before restore {$i}", 'email' => "added{$i}@example.com", 'type' => 'customer', 'country' => 'IT']);

        $summary = $restore->restoreBackup($regular[0], $this->tenant);
        $snapshot = TenantBackup::withoutGlobalScopes()->find($summary['safety_backup_id']);
        $snapshots[] = $snapshot;

        expect($snapshot->filename)->toStartWith('pre-restore-safety-')->and($snapshot->filename)->toEndWith('.zip')
            ->and($snapshot->status)->toBe('completed')
            ->and($snapshot->disk)->toBe('private')
            ->and($snapshot->completed_at)->not->toBeNull();
        Storage::disk('private')->assertExists($snapshot->storagePath());

        // The snapshot holds the state from before the restore, including what the restore then removed.
        $zip = new ZipArchive;
        $zip->open(Storage::disk('private')->path($snapshot->storagePath()));
        expect((string) $zip->getFromName('data.json'))->toContain("Added before restore {$i}");
        $zip->close();

        expect(Contact::withoutGlobalScopes()->where('email', "added{$i}@example.com")->exists())->toBeFalse();
    }

    $kept = TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('filename', 'like', 'pre-restore-safety-%')->orderBy('completed_at')->pluck('id')->all();

    // Only the latest three snapshots survive, files included; the first one is gone.
    expect($kept)->toBe(array_map(fn (TenantBackup $snapshot): string => $snapshot->id, array_slice($snapshots, 1)));
    Storage::disk('private')->assertMissing($snapshots[0]->storagePath());

    // Regular backups are untouched: still the seven of the ordinary limit.
    $ordinary = TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('filename', 'not like', 'pre-restore-safety-%')->pluck('id')->sort()->values()->all();
    expect($ordinary)->toBe(collect($regular)->pluck('id')->sort()->values()->all());

    // A new regular backup applies the ordinary limit without touching the snapshots.
    $this->travelTo(now()->addMinutes(5));
    $service->generate($service->create($this->tenant, $this->user));
    expect(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('filename', 'not like', 'pre-restore-safety-%')->count())->toBe(7)
        ->and(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('filename', 'like', 'pre-restore-safety-%')->count())->toBe(3);
});

test('a restore that is refused takes no snapshot', function (): void {
    $service = app(TenantBackupService::class);
    $foreign = $service->generate($service->create($this->otherTenant, $this->user))->refresh();

    expect(fn () => app(TenantRestoreService::class)->restoreBackup($foreign, $this->tenant))->toThrow(InvalidBackupArchiveException::class);

    expect(TenantBackup::withoutGlobalScopes()->where('filename', 'like', 'pre-restore-safety-%')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Vendor aging
// ---------------------------------------------------------------------------------------------------------------

test('vendor aging keeps every currency apart and never sums them', function (): void {
    p18Passive('EUR-OLD', 100.00, ['due_date' => '2026-03-05']);
    p18Passive('EUR-SOON', 40.00, ['due_date' => '2026-03-25']);
    p18Passive('CHF-OLD', 200.00, ['currency' => 'CHF', 'due_date' => '2026-02-13']);
    p18Passive('CHF-PART', 80.00, ['currency' => 'CHF', 'due_date' => '2026-04-30', 'total_paid' => 30, 'payment_status' => 'partially_paid']);
    p18Passive('CHF-LATE', 7.00, ['currency' => 'CHF', 'due_date' => '2026-09-01']);
    p18Passive('EUR-PAID', 999.00, ['payment_status' => 'paid', 'total_paid' => 999]);
    p18Passive('EUR-CN', 55.00, ['document_type' => 'TD04']);
    p18Passive('SECRET', 5000.00, ['tenant_id' => $this->otherTenant->id, 'due_date' => '2026-01-01']);

    $data = p18Api()->getJson('/api/v1/accounting/vendor-aging?as_of_date=2026-03-15')->assertOk()->json('data');

    expect($data['as_of_date'])->toBe('2026-03-15')
        ->and(array_column($data['summary'], 'currency'))->toBe(['CHF', 'EUR'])
        ->and(array_column($data['suppliers'], 'currency'))->toEqualCanonicalizing(['CHF', 'EUR']);

    $chf = collect($data['summary'])->firstWhere('currency', 'CHF');
    $eur = collect($data['summary'])->firstWhere('currency', 'EUR');

    expect($chf['overdue'])->toEqual(200)
        ->and($chf['days_31_60'])->toEqual(50)
        ->and($chf['days_over_90'])->toEqual(7)
        ->and($chf['total'])->toEqual(257)
        ->and($chf['average_days_overdue'])->toEqual(30)
        ->and($chf['projected_outflow'])->toEqual(['overdue' => 200, 'within_30_days' => 200, 'within_60_days' => 250, 'within_90_days' => 250, 'total' => 257])
        ->and($eur['overdue'])->toEqual(100)
        ->and($eur['days_0_30'])->toEqual(40)
        ->and($eur['total'])->toEqual(140)
        ->and($eur['average_days_overdue'])->toEqual(10)
        ->and($eur['projected_outflow'])->toEqual(['overdue' => 100, 'within_30_days' => 140, 'within_60_days' => 140, 'within_90_days' => 140, 'total' => 140]);

    // One supplier, one row per currency, each in its own currency.
    $rows = collect($data['suppliers'])->keyBy('currency');
    expect($rows['CHF']['total'])->toEqual(257)->and($rows['EUR']['total'])->toEqual(140)->and($rows['EUR']['supplier_id'])->toBe($rows['CHF']['supplier_id']);
    expect(collect($rows['EUR']['invoices'])->pluck('invoice_number')->all())->toBe(['EUR-OLD', 'EUR-SOON']);

    // Settled invoices, credit notes and other tenants are excluded.
    expect(json_encode($data))->not->toContain('EUR-PAID')->and(json_encode($data))->not->toContain('EUR-CN')->and(json_encode($data))->not->toContain('SECRET');
});

test('vendor aging filters by supplier and refuses suppliers of other tenants', function (): void {
    p18Passive('SUP-1', 100.00);
    $other = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Second supplier', 'email' => 's2@example.com', 'type' => 'supplier', 'country' => 'IT']);
    p18Passive('SUP-2', 60.00, ['supplier_id' => $other->id]);
    $foreignSupplier = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Foreign', 'email' => 'f2@example.com', 'type' => 'supplier', 'country' => 'IT']);

    $filtered = p18Api()->getJson("/api/v1/accounting/vendor-aging?as_of_date=2026-03-15&supplier_id={$other->id}")->assertOk()->json('data');
    expect($filtered['suppliers'])->toHaveCount(1)->and($filtered['suppliers'][0]['supplier_name'])->toBe('Second supplier')->and($filtered['summary'][0]['total'])->toEqual(60);

    p18Api()->getJson("/api/v1/accounting/vendor-aging?supplier_id={$foreignSupplier->id}")->assertUnprocessable();
    p18Api()->getJson('/api/v1/accounting/vendor-aging?as_of_date=15/03/2026')->assertUnprocessable();
    expect(p18Api()->getJson('/api/v1/accounting/vendor-aging')->assertOk()->json('data.as_of_date'))->toBe('2026-03-15');
    expect(p18Api($this->otherTenant->id)->getJson('/api/v1/accounting/vendor-aging')->json('data.suppliers'))->toBe([]);
});
