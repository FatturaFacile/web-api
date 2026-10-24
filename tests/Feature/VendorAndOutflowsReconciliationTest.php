<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Beta Consulting SRL', 'email' => 'beta@example.com', 'type' => 'customer', 'country' => 'IT']);
    $this->supplier = Contact::create([
        'tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore SPA', 'email' => 'fornitore@example.com', 'type' => 'supplier', 'country' => 'IT',
        'iban' => 'IT02 L123 4512 3451 2345 6789 012', 'vat_number' => 'IT01234567890',
    ]);
    $this->otherSupplier = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Other Supplier', 'email' => 'os@example.com', 'type' => 'supplier', 'country' => 'IT']);
});

// ---------------------------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------------------------

function voApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

function voImport(string $content, string $name = 'statement.xml', string $query = '', ?string $tenantId = null)
{
    $file = UploadedFile::fake()->createWithContent($name, $content);

    return voApi($tenantId)->post('/api/v1/banking/statements/import'.$query, ['file' => $file], ['Accept' => 'application/json']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function voPassive(string $number, float $amount, array $overrides = []): PassiveInvoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return PassiveInvoice::create(array_merge([
        'tenant_id' => $tenantId, 'supplier_id' => $tenantId === test()->tenant->id ? test()->supplier->id : test()->otherSupplier->id,
        'invoice_number' => $number, 'date' => '2026-09-30', 'due_date' => '2026-10-30', 'currency' => 'EUR',
        'total_amount' => $amount, 'total_net' => round($amount / 1.22, 2), 'total_tax' => round($amount - $amount / 1.22, 2),
        'payment_status' => 'unpaid', 'document_type' => 'TD01',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function voInvoice(string $number, float $gross, array $overrides = []): Invoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return Invoice::create(array_merge([
        'tenant_id' => $tenantId, 'contact_id' => test()->customer->id, 'invoice_number' => $number, 'date' => '2026-09-30', 'due_date' => '2026-10-30',
        'currency' => 'EUR', 'total_net' => round($gross / 1.22, 2), 'total_tax' => round($gross - $gross / 1.22, 2), 'total_gross' => $gross,
        'payment_status' => 'unpaid', 'invoice_type' => 'standard',
    ], $overrides));
}

/**
 * @return array<int, array{type: string, amount: float}>
 */
function voSupplierPayments(PassiveInvoice $invoice): array
{
    return SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->orderBy('created_at')->orderBy('amount', 'desc')->get()
        ->map(fn (SupplierPayment $payment): array => ['type' => $payment->type, 'amount' => (float) $payment->amount])->all();
}

/**
 * A CBI file (records of 120 characters) with the given movements: sign C/D, cents, abi, iban, fee in cents,
 * description and notes.
 *
 * @param  array<int, array<string, mixed>>  $movements
 */
function voCbi(array $movements): string
{
    $records = ['RH123456789012345IT60X0542811101000000123456EUR2610232610230000000500000C'];

    foreach ($movements as $index => $movement) {
        $sequence = str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
        $records[] = '61'.$sequence.'261023261023'.($movement['sign'] ?? 'D').str_pad((string) $movement['cents'], 12, '0', STR_PAD_LEFT)
            .str_pad((string) ($movement['abi'] ?? '048'), 3, '0', STR_PAD_LEFT).str_pad((string) ($movement['iban'] ?? ''), 27).str_pad((string) ($movement['fee'] ?? 0), 12, '0', STR_PAD_LEFT);
        $records[] = '62'.$sequence.($movement['description'] ?? 'PAYMENT');

        if (isset($movement['notes'])) {
            $records[] = '63'.$sequence.$movement['notes'];
        }
    }

    $records[] = 'EF123456789012345'.'000000065025'.'C'.str_pad((string) count($records), 20, '0', STR_PAD_LEFT);

    return implode("\n", array_map(fn (string $record): string => str_pad($record, 120), $records))."\n";
}

/**
 * A CAMT.053 statement. Entry keys: amount, fee, direction (CRDT/DBIT), description, iban, name, purpose.
 *
 * @param  array<int, array<string, mixed>>  $entries
 */
function voCamt053(array $entries, string $currency = 'EUR'): string
{
    $xml = '';

    foreach ($entries as $entry) {
        $debit = ($entry['direction'] ?? 'CRDT') === 'DBIT';
        $party = $debit ? 'Cdtr' : 'Dbtr';
        $account = $debit ? 'CdtrAcct' : 'DbtrAcct';
        $fee = isset($entry['fee']) ? "<Chrgs><Amt Ccy=\"{$currency}\">{$entry['fee']}</Amt><CdtDbtInd>DBIT</CdtDbtInd></Chrgs>" : '';
        $iban = isset($entry['iban']) ? "<{$account}><Id><IBAN>{$entry['iban']}</IBAN></Id></{$account}>" : '';
        $purpose = isset($entry['purpose']) ? "<Purp><Cd>{$entry['purpose']}</Cd></Purp>" : '';
        $name = $entry['name'] ?? 'Some Party';
        $description = $entry['description'] ?? 'Payment';
        $direction = $debit ? 'DBIT' : 'CRDT';

        $xml .= "<Ntry><Amt Ccy=\"{$currency}\">{$entry['amount']}</Amt><CdtDbtInd>{$direction}</CdtDbtInd><Sts>BOOK</Sts>"
            ."<BookgDt><Dt>2026-10-23</Dt></BookgDt><ValDt><Dt>2026-10-23</Dt></ValDt>{$fee}"
            .'<NtryDtls><TxDtls><Refs><EndToEndId>E2E-'.Str::random(6)."</EndToEndId></Refs><RltdPties><{$party}><Nm>{$name}</Nm></{$party}>{$iban}</RltdPties>{$purpose}"
            ."<RmtInf><Ustrd>{$description}</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>S-'.Str::random(6).'</Id>'
        ."<Acct><Id><IBAN>IT60X0542811101000000123456</IBAN></Id></Acct>{$xml}</Stmt></BkToCstmrStmt></Document>";
}

// ---------------------------------------------------------------------------------------------------------------
// Supplier invoices
// ---------------------------------------------------------------------------------------------------------------

test('a CBI debit settles the supplier invoice named in its description', function (): void {
    $invoice = voPassive('SUP-2026-77', 500.00);
    voPassive('SUP-2026-7', 500.00);

    $response = voImport(voCbi([['cents' => 50000, 'description' => 'PAGAMENTO FATTURA SUP-2026-77 FORNITORE SPA', 'notes' => 'CRO 111222333 SPESE 00000000']]), 'flow.cbi', '?auto_reconcile=1')->assertCreated();

    $line = $response->json('data.statement.lines.0');
    expect($line['direction'])->toBe('debit')
        ->and($line['reconciliation_type'])->toBe('exact')
        ->and($line['reconciliation_category'])->toBe('invoice')
        ->and($line['matched_document_id'])->toBe($invoice->id)
        ->and($response->json('data.statement.status'))->toBe('reconciled');

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(500.0)
        ->and($invoice->is_reconciled)->toBeTrue()
        ->and(voSupplierPayments($invoice))->toBe([['type' => 'payment', 'amount' => 500.0]]);
});

test('a CAMT.053 debit is matched through the IBAN of the supplier', function (): void {
    $invoice = voPassive('SUP-1', 320.50);
    voPassive('SUP-2', 99.00);

    $response = voImport(voCamt053([['amount' => '320.50', 'direction' => 'DBIT', 'description' => 'Bonifico', 'iban' => 'IT02L123451234512345678 9012']]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.statement.lines.0.reconciliation_type'))->toBe('exact')
        ->and($invoice->refresh()->payment_status)->toBe('paid');

    // The VAT number in the description works as well, and an ambiguous balance is refused.
    $byVat = voPassive('SUP-3', 45.00);
    voImport(voCamt053([['amount' => '45.00', 'direction' => 'DBIT', 'description' => 'Pagamento P.IVA IT01234567890']]), 'vat.xml', '?auto_reconcile=1')->assertCreated();
    expect($byVat->refresh()->payment_status)->toBe('paid');

    $first = voPassive('SUP-4', 10.00);
    $second = voPassive('SUP-5', 10.00);
    $ambiguous = voImport(voCamt053([['amount' => '10.00', 'direction' => 'DBIT', 'description' => 'Generic', 'iban' => 'IT02L1234512345123456789012']]), 'ambiguous.xml', '?auto_reconcile=1')->assertCreated();
    expect($ambiguous->json('data.reconciliation.unmatched'))->toBe(1)
        ->and($first->refresh()->payment_status)->toBe('unpaid')
        ->and($second->refresh()->payment_status)->toBe('unpaid');
});

test('the fee of a supplier transfer is booked separately and a cent difference as an adjustment', function (): void {
    $withFee = voPassive('SUP-10', 150.00);
    $rounded = voPassive('SUP-11', 150.00);

    $response = voImport(voCamt053([
        ['amount' => '150.00', 'fee' => '2.50', 'direction' => 'DBIT', 'description' => 'Pay SUP-10'],
        ['amount' => '149.97', 'direction' => 'DBIT', 'description' => 'Pay SUP-11'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect(collect($response->json('data.statement.lines'))->pluck('reconciliation_type')->sort()->values()->all())->toBe(['exact', 'fuzzy_tolerance'])
        ->and($response->json('data.statement.status'))->toBe('reconciled')
        ->and($withFee->refresh()->payment_status)->toBe('paid')
        ->and((float) $withFee->total_paid)->toBe(150.0)
        ->and(voSupplierPayments($withFee))->toBe([['type' => 'payment', 'amount' => 150.0], ['type' => 'bank_fee', 'amount' => 2.5]])
        ->and($rounded->refresh()->payment_status)->toBe('paid')
        ->and(voSupplierPayments($rounded))->toBe([['type' => 'payment', 'amount' => 149.97], ['type' => 'adjustment', 'amount' => 0.03]]);
});

test('a partial supplier payment leaves the remaining debt open until it is paid', function (): void {
    $invoice = voPassive('SUP-20', 250.00);

    $first = voImport(voCamt053([['amount' => '100.00', 'direction' => 'DBIT', 'description' => 'Acconto SUP-20']]), 'first.xml', '?auto_reconcile=1')->assertCreated();

    expect($first->json('data.statement.lines.0.reconciliation_type'))->toBe('partial');
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('partially_paid')
        ->and((float) $invoice->total_paid)->toBe(100.0)
        ->and($invoice->is_reconciled)->toBeFalse();

    voImport(voCamt053([['amount' => '150.00', 'direction' => 'DBIT', 'description' => 'Saldo SUP-20']]), 'second.xml', '?auto_reconcile=1')->assertCreated();
    expect($invoice->refresh()->payment_status)->toBe('paid')
        ->and(voSupplierPayments($invoice))->toBe([['type' => 'payment', 'amount' => 150.0], ['type' => 'payment', 'amount' => 100.0]]);
});

test('overpaid supplier invoices, credit notes and settled invoices are not matched', function (): void {
    $small = voPassive('SUP-30', 50.00);
    $creditNote = voPassive('SUP-31', 80.00, ['document_type' => 'TD04']);
    $paid = voPassive('SUP-32', 80.00, ['payment_status' => 'paid']);

    $response = voImport(voCamt053([
        ['amount' => '90.00', 'direction' => 'DBIT', 'description' => 'SUP-30'],
        ['amount' => '80.00', 'direction' => 'DBIT', 'description' => 'SUP-31'],
        ['amount' => '80.00', 'direction' => 'DBIT', 'description' => 'SUP-32'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 3])
        ->and($small->refresh()->payment_status)->toBe('unpaid')
        ->and($creditNote->refresh()->payment_status)->toBe('unpaid')
        ->and($paid->refresh()->total_paid)->toEqual(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Outflows without an invoice
// ---------------------------------------------------------------------------------------------------------------

dataset('standard outflows', [
    'F24 by ABI cause' => [fn () => voCbi([['cents' => 120000, 'abi' => '7', 'description' => 'VERSAMENTO']]), 'tax_payment', '6800'],
    'F24 by delega text' => [fn () => voCamt053([['amount' => '1200.00', 'direction' => 'DBIT', 'description' => 'Delega F24 imposte ottobre']]), 'tax_payment', '6800'],
    'F24 by bare code' => [fn () => voCamt053([['amount' => '1200.00', 'direction' => 'DBIT', 'description' => 'PAGAMENTO F24 2026']]), 'tax_payment', '6800'],
    'tax by erario' => [fn () => voCamt053([['amount' => '77.00', 'direction' => 'DBIT', 'description' => 'versamento erario']]), 'tax_payment', '6800'],
    'payroll by ABI cause' => [fn () => voCbi([['cents' => 250000, 'abi' => '27', 'description' => 'BONIFICO']]), 'payroll', '6000'],
    'payroll by purpose code' => [fn () => voCamt053([['amount' => '2500.00', 'direction' => 'DBIT', 'description' => 'Rossi Mario', 'purpose' => 'SALA']]), 'payroll', '6000'],
    'payroll by emolumenti' => [fn () => voCamt053([['amount' => '2500.00', 'direction' => 'DBIT', 'description' => 'EMOLUMENTI OTTOBRE']]), 'payroll', '6000'],
    'payroll by stipendi' => [fn () => voCamt053([['amount' => '2500.00', 'direction' => 'DBIT', 'description' => 'Stipendi mese di ottobre']]), 'payroll', '6000'],
    'payroll by retribuzione' => [fn () => voCamt053([['amount' => '2500.00', 'direction' => 'DBIT', 'description' => 'Retribuzione Bianchi']]), 'payroll', '6000'],
    'bank charge ABI 16' => [fn () => voCbi([['cents' => 1500, 'abi' => '16', 'description' => 'ADDEBITO']]), 'bank_expense', '6220'],
    'bank charge ABI 17' => [fn () => voCbi([['cents' => 1500, 'abi' => '17', 'description' => 'ADDEBITO']]), 'bank_expense', '6220'],
    'bank charge ABI 34' => [fn () => voCbi([['cents' => 1500, 'abi' => '34', 'description' => 'ADDEBITO']]), 'bank_expense', '6220'],
    'stamp duty' => [fn () => voCamt053([['amount' => '34.20', 'direction' => 'DBIT', 'description' => 'IMPOSTA BOLLO CONTO CORRENTE']]), 'bank_expense', '6220'],
    'account keeping' => [fn () => voCamt053([['amount' => '12.00', 'direction' => 'DBIT', 'description' => 'SPESE TENUTA CONTO 4 TRIM']]), 'bank_expense', '6220'],
    'interest and fees' => [fn () => voCamt053([['amount' => '8.00', 'direction' => 'DBIT', 'description' => 'Competenze di chiusura']]), 'bank_expense', '6220'],
]);

test('standard outflows are recognised and booked on the default ledger account', function (Closure $content, string $category, string $account): void {
    $format = str_contains($content(), '<Document') ? 'statement.xml' : 'flow.cbi';

    $response = voImport($content(), $format, '?auto_reconcile=1')->assertCreated();

    $line = $response->json('data.statement.lines.0');
    expect($line['reconciled'])->toBeTrue()
        ->and($line['reconciliation_category'])->toBe($category)
        ->and($line['ledger_account_code'])->toBe($account)
        ->and($line['reconciliation_type'])->toBe('auto_categorization')
        ->and($line['matched_document_id'])->toBeNull()
        ->and($response->json('data.statement.status'))->toBe('reconciled');
    expect(Payment::withoutGlobalScopes()->count())->toBe(0)->and(SupplierPayment::withoutGlobalScopes()->count())->toBe(0);
})->with('standard outflows');

test('unrecognised outflows stay open and incoming lines are never categorised', function (): void {
    $response = voImport(voCamt053([
        ['amount' => '55.00', 'direction' => 'DBIT', 'description' => 'Cena aziendale'],
        ['amount' => '99.00', 'description' => 'Delega F24 rimborso'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 2])
        ->and($response->json('data.statement.status'))->toBe('draft')
        ->and(collect($response->json('data.statement.lines'))->pluck('reconciliation_category')->filter()->all())->toBe([]);
});

test('ledger accounts and automatic categorisation follow the tenant preferences', function (): void {
    expect(voApi()->getJson('/api/v1/settings/preferences')->json('data.banking'))->toBe([]);

    voApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['tax_account_code' => 'bad code!']])->assertUnprocessable();
    voApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['auto_categorize_standard_codes' => 'maybe']])->assertUnprocessable();

    voApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['tax_account_code' => '7100', 'payroll_account_code' => '7200', 'bank_charges_account_code' => '7300']])->assertOk();
    voApi()->putJson('/api/v1/settings/preferences/banking', ['reconciliation' => ['auto_match_tolerance_amount' => 0.1]])->assertOk();

    $stored = voApi()->getJson('/api/v1/settings/preferences')->json('data.banking');
    expect($stored['ledger_defaults'])->toBe(['tax_account_code' => '7100', 'payroll_account_code' => '7200', 'bank_charges_account_code' => '7300'])
        ->and($stored['reconciliation'])->toEqual(['auto_match_tolerance_amount' => 0.1]);

    $response = voImport(voCamt053([
        ['amount' => '1.00', 'direction' => 'DBIT', 'description' => 'F24'],
        ['amount' => '2.00', 'direction' => 'DBIT', 'description' => 'STIPENDI'],
        ['amount' => '3.00', 'direction' => 'DBIT', 'description' => 'IMPOSTA BOLLO'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect(collect($response->json('data.statement.lines'))->pluck('ledger_account_code', 'reconciliation_category')->all())->toBe(['tax_payment' => '7100', 'payroll' => '7200', 'bank_expense' => '7300']);

    voApi()->putJson('/api/v1/settings/preferences/banking', ['ledger_defaults' => ['auto_categorize_standard_codes' => false]])->assertOk();

    $off = voImport(voCamt053([['amount' => '4.00', 'direction' => 'DBIT', 'description' => 'F24']]), 'off.xml', '?auto_reconcile=1')->assertCreated();
    expect($off->json('data.reconciliation.unmatched'))->toBe(1)
        ->and($off->json('data.statement.lines.0.reconciled'))->toBeFalse();

    // The accounts are kept when only the switch changes.
    expect(voApi()->getJson('/api/v1/settings/preferences')->json('data.banking.ledger_defaults.tax_account_code'))->toBe('7100');
});

test('lines are categorised manually and the categorisation can be undone', function (): void {
    $statement = voImport(voCamt053([
        ['amount' => '500.00', 'direction' => 'DBIT', 'description' => 'Versamento imposte'],
        ['amount' => '30.00', 'direction' => 'DBIT', 'description' => 'Cancelleria'],
        ['amount' => '900.00', 'direction' => 'DBIT', 'description' => 'Giroconto'],
        ['amount' => '900.00', 'description' => 'Giroconto'],
    ]))->assertCreated()->json('data.statement');
    [$tax, $stationery, $transfer, $incoming] = array_column($statement['lines'], 'id');

    $defaulted = voApi()->postJson("/api/v1/banking/statements/lines/{$tax}/categorize", ['category' => 'tax_payment'])->assertOk();
    expect($defaulted->json('data.reconciled'))->toBeTrue()
        ->and($defaulted->json('data.reconciliation_type'))->toBe('manual_categorization')
        ->and($defaulted->json('data.reconciliation_category'))->toBe('tax_payment')
        ->and($defaulted->json('data.ledger_account_code'))->toBe('6800');

    $custom = voApi()->postJson("/api/v1/banking/statements/lines/{$stationery}/categorize", ['category' => 'generic_expense', 'ledger_account_code' => '6100', 'notes' => 'Office supplies'])->assertOk();
    expect($custom->json('data.ledger_account_code'))->toBe('6100')->and($custom->json('data.reconciliation_notes'))->toBe('Office supplies');

    expect(voApi()->postJson("/api/v1/banking/statements/lines/{$transfer}/categorize", ['category' => 'internal_transfer'])->assertOk()->json('data.ledger_account_code'))->toBeNull();
    expect(BankStatement::find($statement['id'])->status)->toBe('partially_reconciled');

    // Incoming lines can only be internal transfers.
    voApi()->postJson("/api/v1/banking/statements/lines/{$incoming}/categorize", ['category' => 'payroll'])->assertUnprocessable()->assertJsonValidationErrors(['category']);
    voApi()->postJson("/api/v1/banking/statements/lines/{$incoming}/categorize", ['category' => 'internal_transfer'])->assertOk();
    expect(BankStatement::find($statement['id'])->status)->toBe('reconciled');

    // Invalid input and lines that are already reconciled.
    voApi()->postJson("/api/v1/banking/statements/lines/{$tax}/categorize", ['category' => 'invoice'])->assertUnprocessable();
    voApi()->postJson("/api/v1/banking/statements/lines/{$tax}/categorize", [])->assertUnprocessable();
    voApi()->postJson("/api/v1/banking/statements/lines/{$tax}/categorize", ['category' => 'payroll'])->assertUnprocessable()->assertJsonValidationErrors(['line']);
    voApi()->postJson("/api/v1/banking/statements/lines/{$stationery}/categorize", ['category' => 'generic_expense', 'ledger_account_code' => 'no spaces allowed'])->assertUnprocessable();
    voApi()->postJson('/api/v1/banking/statements/lines/'.Str::uuid().'/categorize', ['category' => 'payroll'])->assertNotFound();

    $undone = voApi()->postJson("/api/v1/banking/statements/lines/{$stationery}/unmatch")->assertOk();
    expect($undone->json('data.reconciled'))->toBeFalse()
        ->and($undone->json('data.reconciliation_category'))->toBeNull()
        ->and($undone->json('data.ledger_account_code'))->toBeNull()
        ->and($undone->json('data.reconciliation_notes'))->toBeNull()
        ->and($undone->json('data.reconciliation_type'))->toBeNull()
        ->and(BankStatement::find($statement['id'])->status)->toBe('partially_reconciled');

    voApi()->postJson("/api/v1/banking/statements/lines/{$stationery}/unmatch")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Manual matching of supplier invoices
// ---------------------------------------------------------------------------------------------------------------

test('an outgoing line is matched manually with a supplier invoice and unmatching restores the debt', function (): void {
    $statement = voImport(voCamt053([
        ['amount' => '180.00', 'direction' => 'DBIT', 'description' => 'unknown beneficiary'],
        ['amount' => '60.00', 'direction' => 'DBIT', 'description' => 'unknown beneficiary two'],
    ]))->assertCreated()->json('data.statement');
    [$one, $two] = array_column($statement['lines'], 'id');
    $invoice = voPassive('SUP-40', 200.00);

    voApi()->postJson("/api/v1/banking/statements/lines/{$one}/manual-match", ['invoice_id' => $invoice->id])->assertUnprocessable()->assertJsonValidationErrors(['difference_handling']);

    $written = voApi()->postJson("/api/v1/banking/statements/lines/{$one}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => 'write_off'])->assertOk();
    expect($written->json('data.reconciliation_type'))->toBe('manual')
        ->and($written->json('data.reconciliation_category'))->toBe('invoice')
        ->and($written->json('data.matched_document_id'))->toBe($invoice->id);
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and(voSupplierPayments($invoice))->toBe([['type' => 'payment', 'amount' => 180.0], ['type' => 'adjustment', 'amount' => 20.0]]);

    $partial = voPassive('SUP-41', 200.00);
    voApi()->postJson("/api/v1/banking/statements/lines/{$two}/manual-match", ['invoice_id' => $partial->id, 'difference_handling' => 'partial'])->assertOk();
    expect($partial->refresh()->payment_status)->toBe('partially_paid')->and((float) $partial->total_paid)->toBe(60.0);

    voApi()->postJson("/api/v1/banking/statements/lines/{$one}/unmatch")->assertOk()->assertJsonPath('data.matched_document_id', null);
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('unpaid')
        ->and((float) $invoice->total_paid)->toBe(0.0)
        ->and($invoice->is_reconciled)->toBeFalse()
        ->and(voSupplierPayments($invoice))->toBe([]);

    voApi()->postJson("/api/v1/banking/statements/lines/{$two}/unmatch")->assertOk();
    expect($partial->refresh()->payment_status)->toBe('unpaid')->and(BankStatement::find($statement['id'])->status)->toBe('draft');
});

test('the direction of a line decides which invoices it can be matched with', function (): void {
    $statement = voImport(voCamt053([
        ['amount' => '100.00', 'direction' => 'DBIT', 'description' => 'unknown'],
        ['amount' => '100.00', 'description' => 'unknown'],
    ]))->assertCreated()->json('data.statement');
    $debit = collect($statement['lines'])->firstWhere('direction', 'debit')['id'];
    $credit = collect($statement['lines'])->firstWhere('direction', 'credit')['id'];
    $customerInvoice = voInvoice('INV-1', 100.00);
    $supplierInvoice = voPassive('SUP-50', 100.00);

    voApi()->postJson("/api/v1/banking/statements/lines/{$debit}/manual-match", ['invoice_id' => $customerInvoice->id])->assertUnprocessable()->assertJsonValidationErrors(['invoice_id']);
    voApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $supplierInvoice->id])->assertUnprocessable()->assertJsonValidationErrors(['invoice_id']);
    voApi()->postJson("/api/v1/banking/statements/lines/{$debit}/manual-match", ['invoice_id' => (string) Str::uuid()])->assertUnprocessable();

    voApi()->postJson("/api/v1/banking/statements/lines/{$debit}/manual-match", ['invoice_id' => $supplierInvoice->id])->assertOk();
    voApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $customerInvoice->id])->assertOk();

    expect($supplierInvoice->refresh()->payment_status)->toBe('paid')
        ->and($customerInvoice->refresh()->payment_status)->toBe('paid')
        ->and(BankStatementLine::where('reconciled', true)->count())->toBe(2);
});

// ---------------------------------------------------------------------------------------------------------------
// Full reconciliation and isolation
// ---------------------------------------------------------------------------------------------------------------

test('a statement mixing receipts, supplier payments, F24 and salaries is reconciled completely', function (): void {
    $receivable = voInvoice('INV-C-1', 150.00);
    $payable = voPassive('SUP-9', 300.00);

    $response = voImport(voCamt053([
        ['amount' => '150.00', 'description' => 'Saldo INV-C-1', 'name' => 'Beta Consulting SRL'],
        ['amount' => '300.00', 'direction' => 'DBIT', 'description' => 'Pagamento SUP-9', 'name' => 'Fornitore SPA'],
        ['amount' => '1200.00', 'direction' => 'DBIT', 'description' => 'DELEGA F24 ottobre'],
        ['amount' => '2500.00', 'direction' => 'DBIT', 'description' => 'Rossi', 'purpose' => 'SALA'],
        ['amount' => '12.50', 'direction' => 'DBIT', 'description' => 'COMPETENZE TRIMESTRALI'],
    ]), 'statement.xml')->assertCreated();

    expect($response->json('data.statement.status'))->toBe('draft');
    $statementId = $response->json('data.statement.id');

    $reconciled = voApi()->postJson("/api/v1/banking/statements/{$statementId}/reconcile")->assertOk();

    expect($reconciled->json('data.reconciliation'))->toBe(['reconciled' => 5, 'unmatched' => 0])
        ->and($reconciled->json('data.statement.status'))->toBe('reconciled')
        ->and(collect($reconciled->json('data.statement.lines'))->pluck('reconciliation_category')->all())->toBe(['invoice', 'invoice', 'tax_payment', 'payroll', 'bank_expense'])
        ->and($receivable->refresh()->payment_status)->toBe('paid')
        ->and($payable->refresh()->payment_status)->toBe('paid');

    // Running it again changes nothing.
    expect(voApi()->postJson("/api/v1/banking/statements/{$statementId}/reconcile")->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 0]);

    // Undoing one line reopens the statement and its invoice.
    $payrollLine = collect($reconciled->json('data.statement.lines'))->firstWhere('reconciliation_category', 'payroll')['id'];
    voApi()->postJson("/api/v1/banking/statements/lines/{$payrollLine}/unmatch")->assertOk();
    expect(BankStatement::find($statementId)->status)->toBe('partially_reconciled');
});

test('supplier invoices, categorisations and statements of other tenants are out of reach', function (): void {
    $foreignInvoice = voPassive('SUP-9', 300.00, ['tenant_id' => $this->otherTenant->id]);
    $file = voCamt053([
        ['amount' => '300.00', 'direction' => 'DBIT', 'description' => 'Pagamento SUP-9'],
        ['amount' => '1200.00', 'direction' => 'DBIT', 'description' => 'DELEGA F24'],
    ]);

    // Tenant A has no such supplier invoice: only the F24 line is booked and the foreign invoice is untouched.
    $own = voImport($file, 'statement.xml', '?auto_reconcile=1')->assertCreated();
    expect($own->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 1])
        ->and($foreignInvoice->refresh()->payment_status)->toBe('unpaid');

    $theirs = voImport($file, 'statement.xml', '?auto_reconcile=1', $this->otherTenant->id)->assertCreated();
    expect($theirs->json('data.reconciliation'))->toBe(['reconciled' => 2, 'unmatched' => 0])
        ->and($theirs->json('data.statement.status'))->toBe('reconciled')
        ->and($foreignInvoice->refresh()->payment_status)->toBe('paid');

    $ownDebit = collect($own->json('data.statement.lines'))->firstWhere('reconciled', false)['id'];
    $theirTax = collect($theirs->json('data.statement.lines'))->firstWhere('reconciliation_category', 'tax_payment')['id'];
    $theirId = $theirs->json('data.statement.id');

    voApi()->postJson("/api/v1/banking/statements/{$theirId}/reconcile")->assertNotFound();
    voApi()->postJson("/api/v1/banking/statements/lines/{$theirTax}/unmatch")->assertNotFound();
    voApi()->postJson("/api/v1/banking/statements/lines/{$theirTax}/categorize", ['category' => 'payroll'])->assertNotFound();

    // A line of tenant A cannot be matched with a supplier invoice of tenant B, nor does categorising touch B.
    voApi()->postJson("/api/v1/banking/statements/lines/{$ownDebit}/manual-match", ['invoice_id' => $foreignInvoice->id])->assertUnprocessable();
    voApi()->postJson("/api/v1/banking/statements/lines/{$ownDebit}/categorize", ['category' => 'generic_expense'])->assertOk();

    expect($foreignInvoice->refresh()->payment_status)->toBe('paid')
        ->and(BankStatementLine::withoutGlobalScopes()->find($theirTax)->reconciliation_category)->toBe('tax_payment')
        ->and(BankStatement::withoutGlobalScopes()->find($own->json('data.statement.id'))->status)->toBe('reconciled');

    // Each tenant's own supplier payments stay separate.
    expect(SupplierPayment::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(SupplierPayment::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(1);
});
