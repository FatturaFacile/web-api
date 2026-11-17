<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Banking\Iso20022\Pain001SchemaValidator;
use App\Services\Banking\Iso20022\Pain001Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Treasury\Models\BankAccount;

uses(RefreshDatabase::class);

const ISO_QR_IBAN = 'CH4431999123000889012';
const ISO_IBAN = 'CH9300762011623852957';
const ISO_QRR = '210000000003139471430009017';
const ISO_SCOR = 'RF18539007547034';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Switzerland AG', 'legal_name' => 'FatturaFacile Switzerland AG', 'country' => 'CH', 'default_currency' => 'CHF', 'iban' => ISO_IBAN, 'qr_iban' => ISO_QR_IBAN]);
    $this->other = Tenant::create(['name' => 'Other AG', 'legal_name' => 'Other AG', 'country' => 'CH', 'default_currency' => 'CHF']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Café Müller Zürich AG', 'type' => 'customer', 'country' => 'CH', 'email' => 'customer@example.com']);
    $this->otherCustomer = Contact::create(['tenant_id' => $this->other->id, 'company_name' => 'Other Customer AG', 'type' => 'customer', 'country' => 'CH']);
    $this->api = $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * One credit entry of a camt.054 notification or camt.053 statement.
 *
 * @param  array{ref: string, amount: string, qrr?: ?string, scor?: ?string, ccy?: string, debtor?: string, ustrd?: ?string, fee?: ?string, type?: string}  $entry
 */
function isoEntry(array $entry): string
{
    $ccy = $entry['ccy'] ?? 'CHF';
    $structured = '';

    if (! empty($entry['qrr'])) {
        $structured = "<Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>{$entry['qrr']}</Ref></CdtrRefInf></Strd>";
    } elseif (! empty($entry['scor'])) {
        $structured = "<Strd><CdtrRefInf><Tp><CdOrPrtry><Cd>SCOR</Cd></CdOrPrtry></Tp><Ref>{$entry['scor']}</Ref></CdtrRefInf></Strd>";
    }

    $ustrd = ! empty($entry['ustrd']) ? "<Ustrd>{$entry['ustrd']}</Ustrd>" : '';
    $fee = ! empty($entry['fee']) ? "<Chrgs><TtlChrgsAndTaxAmt Ccy=\"{$ccy}\">{$entry['fee']}</TtlChrgsAndTaxAmt></Chrgs>" : '';
    $indicator = $entry['type'] ?? 'CRDT';
    $party = $indicator === 'CRDT' ? 'Dbtr' : 'Cdtr';

    return <<<XML
<Ntry>
  <NtryRef>{$entry['ref']}</NtryRef>
  <Amt Ccy="{$ccy}">{$entry['amount']}</Amt>
  <CdtDbtInd>{$indicator}</CdtDbtInd>
  <Sts>BOOK</Sts>
  <BookgDt><Dt>2026-03-14</Dt></BookgDt>
  <ValDt><Dt>2026-03-14</Dt></ValDt>
  <AcctSvcrRef>{$entry['ref']}</AcctSvcrRef>
  {$fee}
  <NtryDtls><TxDtls>
    <Refs><AcctSvcrRef>{$entry['ref']}</AcctSvcrRef></Refs>
    <RltdPties><{$party}><Nm>{$entry['debtor']}</Nm></{$party}></RltdPties>
    <RmtInf>{$ustrd}{$structured}</RmtInf>
  </TxDtls></NtryDtls>
</Ntry>
XML;
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function isoCamt054(string $version, string $messageId, array $entries): string
{
    $body = implode("\n", array_map(fn (array $entry): string => isoEntry($entry + ['debtor' => 'Café Müller Zürich AG']), $entries));

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.{$version}">
  <BkToCstmrDbtCdtNtfctn>
    <GrpHdr><MsgId>{$messageId}</MsgId><CreDtTm>2026-03-15T08:00:00</CreDtTm></GrpHdr>
    <Ntfctn>
      <Id>NTF-{$messageId}</Id>
      <Acct><Id><IBAN>CH4431999123000889012</IBAN></Id></Acct>
      {$body}
    </Ntfctn>
  </BkToCstmrDbtCdtNtfctn>
</Document>
XML;
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function isoCamt053(string $version, string $messageId, array $entries, string $opening = '1000.00', string $closing = '1500.00'): string
{
    $body = implode("\n", array_map(fn (array $entry): string => isoEntry($entry + ['debtor' => 'Counterparty AG']), $entries));

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.{$version}">
  <BkToCstmrStmt>
    <GrpHdr><MsgId>{$messageId}</MsgId><CreDtTm>2026-03-15T08:00:00</CreDtTm></GrpHdr>
    <Stmt>
      <Id>STMT-{$messageId}</Id>
      <Acct><Id><IBAN>CH9300762011623852957</IBAN></Id><Ccy>CHF</Ccy></Acct>
      <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="CHF">{$opening}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-13</Dt></Dt></Bal>
      <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="CHF">{$closing}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-14</Dt></Dt></Bal>
      {$body}
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
}

function isoImport(string $xml, string $name = 'statement.xml', bool $autoReconcile = true): TestResponse
{
    return test()->api->post('/api/v1/banking/iso20022/import', [
        'file' => UploadedFile::fake()->createWithContent($name, $xml),
        'auto_reconcile' => $autoReconcile ? '1' : '0',
    ]);
}

function isoInvoice(string $number, float $gross, array $overrides = []): Invoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return Invoice::create(array_merge([
        'tenant_id' => $tenantId, 'contact_id' => $tenantId === test()->tenant->id ? test()->customer->id : test()->otherCustomer->id,
        'invoice_number' => $number, 'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'CHF', 'jurisdiction' => 'CH', 'invoice_type' => 'ch_qr_bill',
        'total_net' => round($gross / 1.081, 2), 'total_tax' => round($gross - $gross / 1.081, 2), 'total_gross' => $gross, 'payment_status' => 'unpaid',
    ], $overrides));
}

test('a camt.054.001.04 credit with a QRR reference pays the invoice in full', function (): void {
    $invoice = isoInvoice('INV-2026-0001', 1949.75, ['qr_reference' => ISO_QRR]);

    $response = isoImport(isoCamt054('04', 'MSG-04', [['ref' => 'BANK-REF-1', 'amount' => '1949.75', 'qrr' => ISO_QRR]]))->assertCreated();

    expect($response->json('data'))->toMatchArray(['format' => 'camt054', 'entries' => 1, 'stored' => 1, 'duplicates' => 0, 'reconciled' => 1, 'unmatched' => 0, 'auto_reconciled' => true])
        ->and($invoice->refresh()->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(1949.75)
        ->and(Payment::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(BankStatementLine::query()->first())->reconciliation_type->toBe('qr_reference')->entry_reference->toBe('BANK-REF-1');
});

test('a camt.054.001.08 SCOR credit that is smaller than the invoice is booked as a partial payment', function (): void {
    $invoice = isoInvoice('INV-2026-0002', 1000.00, ['qr_reference' => ISO_SCOR]);

    $response = isoImport(isoCamt054('08', 'MSG-08', [['ref' => 'BANK-REF-2', 'amount' => '400.00', 'scor' => ISO_SCOR]]))->assertCreated();

    expect($response->json('data.reconciled'))->toBe(1)
        ->and($invoice->refresh()->payment_status)->toBe('partially_paid')
        ->and((float) $invoice->total_paid)->toBe(400.0)
        ->and(BankStatementLine::query()->first()->reconciliation_type)->toBe('partial');
});

test('an overpayment is queued for manual matching and can then be linked', function (): void {
    $invoice = isoInvoice('INV-2026-0003', 500.00, ['qr_reference' => ISO_QRR]);

    $response = isoImport(isoCamt054('04', 'MSG-OVER', [['ref' => 'BANK-REF-3', 'amount' => '650.00', 'qrr' => ISO_QRR]]))->assertCreated();

    expect($response->json('data.unmatched'))->toBe(1)
        ->and($invoice->refresh()->payment_status)->toBe('unpaid');

    $queue = $this->api->getJson('/api/v1/banking/reconciliation/transactions?status=unmatched')->assertOk();
    expect($queue->json('data'))->toHaveCount(1)
        ->and($queue->json('data.0.qr_reference'))->toBe(ISO_QRR)
        ->and($queue->json('meta.total'))->toBe(1);

    // An excess is never booked silently: the manual match is refused and the invoice stays open.
    $overpaid = $queue->json('data.0.id');
    $this->api->postJson("/api/v1/banking/reconciliation/transactions/{$overpaid}/match", ['invoice_id' => $invoice->id, 'difference_handling' => 'write_off'])->assertUnprocessable();
    expect($invoice->refresh()->payment_status)->toBe('unpaid');

    // A payment without any reference is linked by hand in the workbench.
    isoImport(isoCamt054('08', 'MSG-NOREF', [['ref' => 'BANK-REF-6', 'amount' => '500.00', 'ustrd' => 'Payment']]))->assertCreated()->assertJsonPath('data.unmatched', 1);
    $lineId = $this->api->getJson('/api/v1/banking/reconciliation/transactions?status=unmatched&search=payment')->json('data.0.id');

    $this->api->postJson("/api/v1/banking/reconciliation/transactions/{$lineId}/match", ['invoice_id' => $invoice->id, 'difference_handling' => 'none'])->assertOk()
        ->assertJsonPath('data.reconciled', true);

    expect($invoice->refresh()->payment_status)->toBe('paid')
        ->and($this->api->getJson('/api/v1/banking/reconciliation/transactions?status=matched')->json('meta.total'))->toBe(1)
        ->and($this->api->getJson('/api/v1/banking/reconciliation/transactions?status=unmatched')->json('meta.total'))->toBe(1);
});

test('importing is idempotent: a repeated file is refused and a repeated booking is stored once', function (): void {
    $invoice = isoInvoice('INV-2026-0004', 300.00, ['qr_reference' => ISO_QRR]);
    $entry = ['ref' => 'BANK-REF-4', 'amount' => '300.00', 'qrr' => ISO_QRR];

    isoImport(isoCamt054('04', 'MSG-A', [$entry]))->assertCreated();
    isoImport(isoCamt054('04', 'MSG-A', [$entry]))->assertUnprocessable();

    // The bank repeats the booking in a later file (different message, same AcctSvcrRef): nothing is stored or paid twice.
    $second = isoImport(isoCamt054('04', 'MSG-B', [$entry, ['ref' => 'BANK-REF-5', 'amount' => '12.00', 'ustrd' => 'Unrelated']]))->assertCreated();

    expect($second->json('data'))->toMatchArray(['entries' => 2, 'stored' => 1, 'duplicates' => 1])
        ->and(BankStatementLine::query()->count())->toBe(2)
        ->and(BankStatement::query()->count())->toBe(2)
        ->and(Payment::withoutGlobalScopes()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and((float) $invoice->refresh()->total_paid)->toBe(300.0);
});

test('a camt.053.001.08 statement is parsed with balances, credits, debits and fees', function (): void {
    isoInvoice('INV-2026-0005', 200.00, ['qr_reference' => ISO_QRR]);

    $xml = isoCamt053('08', 'STMT-08', [
        ['ref' => 'S-1', 'amount' => '200.00', 'qrr' => ISO_QRR],
        ['ref' => 'S-2', 'amount' => '35.50', 'type' => 'DBIT', 'ustrd' => 'Office supplies', 'debtor' => 'Papeterie SA'],
        ['ref' => 'S-3', 'amount' => '320.00', 'ustrd' => 'Donation', 'fee' => '1.50', 'debtor' => 'Friend'],
    ], '1000.00', '1483.00');

    $response = isoImport($xml)->assertCreated();
    $statement = BankStatement::query()->firstOrFail();

    expect($response->json('data'))->toMatchArray(['format' => 'camt053', 'account_iban' => ISO_IBAN, 'opening_balance' => 1000.0, 'closing_balance' => 1483.0, 'entries' => 3, 'stored' => 3, 'reconciled' => 1, 'unmatched' => 2])
        ->and($statement->lines()->where('direction', 'debit')->count())->toBe(1)
        ->and((float) $statement->lines()->where('description', 'Donation')->value('fee_amount'))->toBe(1.5);

    $unmatched = $this->api->getJson('/api/v1/banking/reconciliation/transactions?status=unmatched&direction=credit&search=donation')->assertOk();
    expect($unmatched->json('data'))->toHaveCount(1);
});

test('a camt.053 with several transaction details splits the entry into lines', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04">
  <BkToCstmrStmt><Stmt>
    <Id>STMT-NESTED</Id>
    <Acct><Id><IBAN>CH9300762011623852957</IBAN></Id></Acct>
    <Ntry>
      <NtryRef>BATCH-1</NtryRef><Amt Ccy="CHF">150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
      <BookgDt><Dt>2026-03-14</Dt></BookgDt><ValDt><Dt>2026-03-14</Dt></ValDt>
      <NtryDtls>
        <TxDtls><Refs><AcctSvcrRef>TX-A</AcctSvcrRef></Refs><AmtDtls><TxAmt><Amt Ccy="CHF">100.00</Amt></TxAmt></AmtDtls><RltdPties><Dbtr><Nm>First AG</Nm></Dbtr></RltdPties><RmtInf><Ustrd>First</Ustrd></RmtInf></TxDtls>
        <TxDtls><Refs><AcctSvcrRef>TX-B</AcctSvcrRef></Refs><AmtDtls><TxAmt><Amt Ccy="CHF">50.00</Amt></TxAmt></AmtDtls><RltdPties><Dbtr><Nm>Second AG</Nm></Dbtr></RltdPties><RmtInf><Ustrd>Second</Ustrd></RmtInf></TxDtls>
      </NtryDtls>
    </Ntry>
  </Stmt></BkToCstmrStmt>
</Document>
XML;

    $response = isoImport($xml, 'nested.xml', false)->assertCreated();

    expect($response->json('data'))->toMatchArray(['stored' => 2, 'auto_reconciled' => false])
        ->and(BankStatementLine::query()->orderBy('line_number')->pluck('amount')->map(fn ($amount): float => (float) $amount)->all())->toBe([100.0, 50.0])
        ->and(BankStatementLine::query()->pluck('entry_reference')->sort()->values()->all())->toBe(['TX-A', 'TX-B']);
});

test('files that are not ISO 20022 statements are refused', function (): void {
    isoImport('<?xml version="1.0"?><Other><x/></Other>', 'other.xml')->assertUnprocessable();

    $cbi = implode("\n", array_map(fn (string $record): string => str_pad($record, 120), ['RH123456789012345IT60X0542811101000000123456EUR2610232610230000005000000C', 'EF123456789012345000000065025C00000000000000000004']))."\n";
    isoImport($cbi, 'statement.txt')->assertUnprocessable();
});

test('bank transactions of another tenant are neither listed nor matchable', function (): void {
    $foreignStatement = BankStatement::withoutGlobalScopes()->create([
        'tenant_id' => $this->other->id, 'source_format' => 'camt054', 'account_iban' => ISO_QR_IBAN, 'statement_date' => '2026-03-14', 'currency' => 'CHF', 'total_entries' => 1, 'status' => 'draft',
    ]);
    $foreignLine = BankStatementLine::withoutGlobalScopes()->create([
        'tenant_id' => $this->other->id, 'bank_statement_id' => $foreignStatement->id, 'line_number' => 1, 'booking_date' => '2026-03-14', 'value_date' => '2026-03-14',
        'amount' => 80, 'fee_amount' => 0, 'currency' => 'CHF', 'direction' => 'credit', 'reconciled' => false,
    ]);
    $foreignInvoice = isoInvoice('FOREIGN-1', 80.00, ['tenant_id' => $this->other->id]);
    $ownInvoice = isoInvoice('OWN-1', 80.00);

    expect($this->api->getJson('/api/v1/banking/reconciliation/transactions?status=all')->json('data'))->toBe([]);
    $this->api->getJson('/api/v1/banking/reconciliation/transactions?statement_id='.$foreignStatement->id)->assertUnprocessable();
    // The line of another tenant does not exist for us; neither does its invoice.
    $this->api->postJson("/api/v1/banking/reconciliation/transactions/{$foreignLine->id}/match", ['invoice_id' => $ownInvoice->id])->assertNotFound();
    $this->api->postJson("/api/v1/banking/reconciliation/transactions/{$foreignLine->id}/match", ['invoice_id' => $foreignInvoice->id])->assertUnprocessable();

    // A credit quoting the reference of another tenant's invoice does not pay it.
    isoInvoice('FOREIGN-2', 90.00, ['tenant_id' => $this->other->id, 'qr_reference' => ISO_QRR]);
    $response = isoImport(isoCamt054('04', 'MSG-X', [['ref' => 'X-1', 'amount' => '90.00', 'qrr' => ISO_QRR]]))->assertCreated();

    expect($response->json('data.unmatched'))->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('invoice_number', 'FOREIGN-2')->value('payment_status'))->toBe('unpaid');
});

/**
 * @return array{qr: PassiveInvoice, standard: PassiveInvoice, account: BankAccount}
 */
function isoSupplierInvoices(): array
{
    $tenant = test()->tenant;
    $gl = ChartOfAccount::create(['tenant_id' => $tenant->id, 'account_code' => '1021', 'name' => 'Bank', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => 'CHF']);
    $account = BankAccount::create([
        'tenant_id' => $tenant->id, 'company_id' => $tenant->id, 'bank_name' => 'PostFinance', 'account_number' => 'A-'.Str::random(6), 'account_type' => 'OPERATING',
        'iban' => ISO_IBAN, 'bic_swift' => 'POFICHBEXXX', 'currency' => 'CHF', 'opening_balance' => 10000, 'current_balance' => 10000, 'available_balance' => 10000, 'is_active' => true, 'gl_account_id' => $gl->id,
    ]);

    $qrSupplier = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Robert Schneider AG', 'type' => 'supplier', 'country' => 'CH', 'qr_iban' => ISO_QR_IBAN]);
    $plainSupplier = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Café Müller & Söhne AG', 'type' => 'supplier', 'country' => 'CH', 'iban' => 'CH5604835012345678009']);

    $payload = implode("\r\n", ['SPC', '0200', '1', ISO_QR_IBAN, 'S', 'Robert Schneider AG', 'Rue du Lac', '1', '2501', 'Biel', 'CH', '', '', '', '', '', '', '', '1949.75', 'CHF', '', '', '', '', '', '', '', 'QRR', ISO_QRR, 'Invoice 77', 'EPD']);

    return [
        'qr' => PassiveInvoice::create(['tenant_id' => $tenant->id, 'supplier_id' => $qrSupplier->id, 'invoice_number' => 'QR-77', 'date' => '2026-03-01', 'total_amount' => '1949.75', 'total_net' => '1803.75', 'total_tax' => '146.00', 'currency' => 'CHF', 'payment_status' => 'unpaid', 'qr_raw_payload' => $payload]),
        'standard' => PassiveInvoice::create(['tenant_id' => $tenant->id, 'supplier_id' => $plainSupplier->id, 'invoice_number' => 'SUP-8', 'date' => '2026-03-02', 'total_amount' => '250.00', 'total_net' => '231.27', 'total_tax' => '18.73', 'currency' => 'CHF', 'payment_status' => 'unpaid']),
        'account' => $account,
    ];
}

test('the pain.001 export of supplier invoices validates against the ISO 20022 schema in both versions', function (string $version): void {
    ['qr' => $qr, 'standard' => $standard] = isoSupplierInvoices();

    $response = $this->api->postJson('/api/v1/banking/iso20022/pain001/export', [
        'invoice_ids' => [$qr->id, $standard->id], 'version' => $version, 'scheme' => 'ch', 'execution_date' => '2026-03-20',
    ])->assertCreated();

    $xml = $response->json('data.xml_preview');
    $enum = Pain001Version::from($version);

    expect($response->json('data'))->toMatchArray(['pain_version' => $version, 'status' => 'exported', 'schema_valid' => true])
        ->and(app(Pain001SchemaValidator::class)->errors($xml, $enum))->toBe([])
        ->and($xml)->toContain('pain.001.001.'.$version)
        ->toContain('<Prtry>QRR</Prtry>')
        ->toContain('<Ref>'.ISO_QRR.'</Ref>')
        ->toContain('<IBAN>'.ISO_QR_IBAN.'</IBAN>')
        ->toContain('<CtrlSum>2199.75</CtrlSum>')
        ->toContain('Café Müller &amp; Söhne AG')
        ->toContain('<Ustrd>Invoice SUP-8</Ustrd>');
})->with(['03', '09']);

test('the pain.001 export refuses other tenants invoices and unknown versions', function (): void {
    $foreignSupplier = Contact::create(['tenant_id' => $this->other->id, 'company_name' => 'Foreign Supplier AG', 'type' => 'supplier', 'country' => 'CH', 'iban' => ISO_IBAN]);
    $foreign = PassiveInvoice::create(['tenant_id' => $this->other->id, 'supplier_id' => $foreignSupplier->id, 'invoice_number' => 'F-1', 'date' => '2026-03-01', 'total_amount' => '10.00', 'total_net' => '10.00', 'total_tax' => '0.00', 'currency' => 'CHF', 'payment_status' => 'unpaid']);
    ['standard' => $standard] = isoSupplierInvoices();

    $this->api->postJson('/api/v1/banking/iso20022/pain001/export', ['invoice_ids' => [$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('invoice_ids.0');
    $this->api->postJson('/api/v1/banking/iso20022/pain001/export', ['invoice_ids' => [$standard->id], 'version' => '05'])->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->api->postJson('/api/v1/banking/iso20022/pain001/export', [])->assertUnprocessable();
});
