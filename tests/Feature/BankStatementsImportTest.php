<?php

declare(strict_types=1);

use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Banking\BankStatementParserManager;
use App\Services\Banking\Parsers\Camt053Parser;
use App\Services\Banking\Parsers\CbiStatementParser;
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
    $this->otherCustomer = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Other Customer', 'email' => 'other@example.com', 'type' => 'customer', 'country' => 'IT']);
});

// ---------------------------------------------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------------------------------------------

/**
 * The CBI fixture: every record is exactly 120 characters long.
 */
function bsCbiFixture(): string
{
    $iban = 'IT60X0542811101000000123456';

    return implode("\n", array_map(fn (string $record): string => str_pad($record, 120), [
        'RH123456789012345'.$iban.'EUR'.'261023'.'261023'.'0000000500000'.'C',
        '61'.'012345'.'261023'.'261023'.'C'.'000000015025'.'048'.$iban.'000000000000',
        '62'.'012345'.'BONIFICO SALDO FATTURA INV-2026-0042 ACME CORP',
        '63'.'012345'.'CRO 123456789012345 SPESE 00000000',
        'EF123456789012345'.'000000065025'.'C'.'00000000000000000004',
    ]))."\n";
}

function bsCamt053Fixture(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Id>STMT-2026-001</Id>
      <ElctrncSeqNb>1</ElctrncSeqNb>
      <Acct>
        <Id><IBAN>IT60X0542811101000000123456</IBAN></Id>
      </Acct>
      <Bal>
        <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="EUR">5000.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Dt><Dt>2026-10-01</Dt></Dt>
      </Bal>
      <Ntry>
        <Amt Ccy="EUR">147.50</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><Dt>2026-10-23</Dt></BookgDt>
        <ValDt><Dt>2026-10-23</Dt></ValDt>
        <Chrgs>
          <Amt Ccy="EUR">2.50</Amt>
          <CdtDbtInd>DBIT</CdtDbtInd>
        </Chrgs>
        <NtryDtls>
          <TxDtls>
            <Refs><EndToEndId>E2E-998877</EndToEndId></Refs>
            <RltdPties>
              <Dbtr><Nm>Beta Consulting SRL</Nm></Dbtr>
            </RltdPties>
            <RmtInf>
              <Ustrd>Payment for invoice INV-2026-0088</Ustrd>
            </RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
      <Bal>
        <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="EUR">5147.50</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Dt><Dt>2026-10-23</Dt></Dt>
      </Bal>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
}

function bsCamt054Fixture(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04">
  <BkToCstmrDbtCdtNtfctn>
    <Ntfctn>
      <Id>NTF-2026-CH-001</Id>
      <Acct>
        <Id><IBAN>CH4431999123000889012</IBAN></Id>
      </Acct>
      <Ntry>
        <Amt Ccy="CHF">850.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-10-23</Dt></BookgDt>
        <ValDt><Dt>2026-10-23</Dt></ValDt>
        <NtryDtls>
          <TxDtls>
            <Refs>
              <AcctSvcrRef>REF-CH-QR-990</AcctSvcrRef>
            </Refs>
            <RltdPties>
              <Dbtr><Nm>Helvetia Services AG</Nm></Dbtr>
            </RltdPties>
            <RmtInf>
              <Strd>
                <CdtrRefInf>
                  <Tp><CdOrPrtry><Cd>QRR</Cd></CdOrPrtry></Tp>
                  <Ref>210000000003139471430009017</Ref>
                </CdtrRefInf>
              </Strd>
            </RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Ntfctn>
  </BkToCstmrDbtCdtNtfctn>
</Document>
XML;
}

/**
 * A CAMT.053 statement with the given entries: amount, fee, direction (CRDT/DBIT), description, e2e, iban, name.
 *
 * @param  array<int, array<string, mixed>>  $entries
 */
function bsCamt053(array $entries, string $currency = 'EUR', string $account = 'IT60X0542811101000000123456'): string
{
    $xml = '';
    $entries = isset($entries['amount']) ? [$entries] : $entries;

    foreach ($entries as $entry) {
        $fee = isset($entry['fee']) ? "<Chrgs><Amt Ccy=\"{$currency}\">{$entry['fee']}</Amt><CdtDbtInd>DBIT</CdtDbtInd></Chrgs>" : '';
        $iban = isset($entry['iban']) ? "<DbtrAcct><Id><IBAN>{$entry['iban']}</IBAN></Id></DbtrAcct>" : '';
        $name = $entry['name'] ?? 'Some Payer';
        $description = $entry['description'] ?? 'Payment';
        $direction = $entry['direction'] ?? 'CRDT';
        $e2e = $entry['e2e'] ?? 'E2E-'.Str::random(6);

        $xml .= "<Ntry><Amt Ccy=\"{$currency}\">{$entry['amount']}</Amt><CdtDbtInd>{$direction}</CdtDbtInd><Sts>BOOK</Sts>"
            ."<BookgDt><Dt>2026-10-23</Dt></BookgDt><ValDt><Dt>2026-10-23</Dt></ValDt>{$fee}"
            ."<NtryDtls><TxDtls><Refs><EndToEndId>{$e2e}</EndToEndId></Refs><RltdPties><Dbtr><Nm>{$name}</Nm></Dbtr>{$iban}</RltdPties>"
            ."<RmtInf><Ustrd>{$description}</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>S-'.Str::random(6).'</Id>'
        ."<Acct><Id><IBAN>{$account}</IBAN></Id></Acct>{$xml}</Stmt></BkToCstmrStmt></Document>";
}

// ---------------------------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------------------------

function bsApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

function bsImport(string $content, string $name = 'statement.xml', string $query = '', ?string $tenantId = null)
{
    $file = UploadedFile::fake()->createWithContent($name, $content);

    return bsApi($tenantId)->post('/api/v1/banking/statements/import'.$query, ['file' => $file], ['Accept' => 'application/json']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bsInvoice(string $number, float $gross, array $overrides = []): Invoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return Invoice::create(array_merge([
        'tenant_id' => $tenantId, 'contact_id' => $tenantId === test()->tenant->id ? test()->customer->id : test()->otherCustomer->id,
        'invoice_number' => $number, 'date' => '2026-09-30', 'due_date' => '2026-10-30', 'currency' => 'EUR',
        'total_net' => round($gross / 1.22, 2), 'total_tax' => round($gross - $gross / 1.22, 2), 'total_gross' => $gross,
        'payment_status' => 'unpaid', 'invoice_type' => 'standard',
    ], $overrides));
}

/**
 * @return array<int, array{type: string, amount: float}>
 */
function bsPayments(Invoice $invoice): array
{
    return Payment::withoutGlobalScopes()->where('invoice_id', $invoice->id)->orderBy('created_at')->orderBy('amount', 'desc')->get()
        ->map(fn (Payment $payment): array => ['type' => $payment->type, 'amount' => (float) $payment->amount])->all();
}

function bsSetPreferences(array $reconciliation): void
{
    bsApi()->putJson('/api/v1/settings/preferences/banking', ['reconciliation' => $reconciliation])->assertOk();
}

// ---------------------------------------------------------------------------------------------------------------
// Parsing and format detection
// ---------------------------------------------------------------------------------------------------------------

test('the fixtures are well formed: every CBI record has exactly 120 characters', function (): void {
    foreach (explode("\n", trim(bsCbiFixture(), "\n")) as $record) {
        expect(strlen($record))->toBe(120);
    }
});

test('the manager detects and parses a CBI statement', function (): void {
    $manager = app(BankStatementParserManager::class);

    expect($manager->detect(bsCbiFixture()))->toBe('cbi');

    $statement = $manager->parse(bsCbiFixture());
    expect($statement->sourceFormat)->toBe(ParsedStatement::FORMAT_CBI)
        ->and($statement->accountIban)->toBe('IT60X0542811101000000123456')
        ->and($statement->currency)->toBe('EUR')
        ->and($statement->statementDate->toDateString())->toBe('2026-10-23')
        ->and($statement->openingBalance)->toBe(5000.0)
        ->and($statement->closingBalance)->toBe(650.25)
        ->and($statement->totalEntries())->toBe(1);

    $line = $statement->lines[0];
    expect($line->direction)->toBe('credit')
        ->and($line->amount)->toBe(150.25)
        ->and($line->feeAmount)->toBe(0.0)
        ->and($line->abiCode)->toBe('048')
        ->and($line->bookingDate->toDateString())->toBe('2026-10-23')
        ->and($line->valueDate->toDateString())->toBe('2026-10-23')
        ->and($line->reference)->toBe('123456789012345')
        ->and($line->description)->toBe('BONIFICO SALDO FATTURA INV-2026-0042 ACME CORP')
        ->and($line->counterpartIban)->toBe('IT60X0542811101000000123456');
});

test('CBI explicit fees, debits and notes are read from the 61 and 63 records', function (): void {
    $iban = 'IT60X0542811101000000123456';
    $records = [
        'RH123456789012345'.$iban.'EUR261023261023'.'0000000500000'.'C',
        '61'.'000001'.'261022'.'261023'.'C'.'000000010000'.'027'.str_pad('', 27).'000000000250', // fee on the 61 record
        '62'.'000001'.'FATTURA 55',
        '61'.'000002'.'261023'.'261023'.'D'.'000000002000'.'050'.str_pad('', 27),
        '63'.'000002'.'TRN ABC123 SPESE 00000100 QR 210000000003139471430009017',
        'EF123456789012345'.'000000000000'.'C'.str_pad('5', 20, '0', STR_PAD_LEFT),
    ];
    $content = implode("\r\n", array_map(fn (string $record): string => str_pad($record, 120), $records));

    $lines = app(CbiStatementParser::class)->parse($content)->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->feeAmount)->toBe(2.5)
        ->and($lines[0]->bookingDate->toDateString())->toBe('2026-10-22')
        ->and($lines[0]->counterpartIban)->toBeNull()
        ->and($lines[1]->direction)->toBe('debit')
        ->and($lines[1]->feeAmount)->toBe(1.0)
        ->and($lines[1]->reference)->toBe('ABC123')
        ->and($lines[1]->qrReference)->toBe('210000000003139471430009017');
});

test('the manager detects and parses a CAMT.053 statement with charges', function (): void {
    $manager = app(BankStatementParserManager::class);
    expect($manager->detect(bsCamt053Fixture()))->toBe('camt053');

    $statement = $manager->parse(bsCamt053Fixture());
    expect($statement->sourceFormat)->toBe('camt053')
        ->and($statement->accountIban)->toBe('IT60X0542811101000000123456')
        ->and($statement->currency)->toBe('EUR')
        ->and($statement->openingBalance)->toBe(5000.0)
        ->and($statement->closingBalance)->toBe(5147.5)
        ->and($statement->statementDate->toDateString())->toBe('2026-10-23')
        ->and($statement->totalEntries())->toBe(1);

    $line = $statement->lines[0];
    expect($line->direction)->toBe('credit')
        ->and($line->amount)->toBe(147.5)
        ->and($line->feeAmount)->toBe(2.5)
        ->and($line->reference)->toBe('E2E-998877')
        ->and($line->counterpartName)->toBe('Beta Consulting SRL')
        ->and($line->description)->toBe('Payment for invoice INV-2026-0088')
        ->and($line->qrReference)->toBeNull();
});

test('the manager detects and parses a CAMT.054 notification with the Swiss QR reference', function (): void {
    $manager = app(BankStatementParserManager::class);
    expect($manager->detect(bsCamt054Fixture()))->toBe('camt054');

    $statement = $manager->parse(bsCamt054Fixture());
    expect($statement->sourceFormat)->toBe('camt054')
        ->and($statement->accountIban)->toBe('CH4431999123000889012')
        ->and($statement->currency)->toBe('CHF')
        ->and($statement->openingBalance)->toBeNull()
        ->and($statement->closingBalance)->toBeNull()
        ->and($statement->statementDate->toDateString())->toBe('2026-10-23');

    $line = $statement->lines[0];
    expect($line->amount)->toBe(850.0)
        ->and($line->feeAmount)->toBe(0.0)
        ->and($line->qrReference)->toBe('210000000003139471430009017')
        ->and($line->reference)->toBe('REF-CH-QR-990')
        ->and($line->counterpartName)->toBe('Helvetia Services AG');
});

test('an ISO 11649 creditor reference and spaced QR references are extracted', function (): void {
    $xml = str_replace(
        '<Ustrd>Payment for invoice INV-2026-0088</Ustrd>',
        '<Strd><CdtrRefInf><Tp><CdOrPrtry><Cd>SCOR</Cd></CdOrPrtry></Tp><Ref>RF18 5390 0754 7034</Ref></CdtrRefInf></Strd>',
        bsCamt053Fixture(),
    );

    expect(app(Camt053Parser::class)->parse($xml)->lines[0]->qrReference)->toBe('RF18539007547034');
});

test('unsupported and malformed files are rejected with a clear reason', function (): void {
    $manager = app(BankStatementParserManager::class);
    $fail = function (string $content) use ($manager): string {
        try {
            $manager->parse($content);
        } catch (BankStatementException $e) {
            return $e->getMessage();
        }

        return 'parsed';
    };

    expect($fail('just some text'))->toContain('not a supported bank statement')
        ->and($fail(''))->toContain('empty')
        ->and($fail('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"></Document>'))->toContain('not a supported bank statement')
        ->and($fail('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x "y">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"/>'))->toContain('DOCTYPE')
        ->and($fail('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'))->toContain('not a valid XML')
        ->and($fail('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>'))->toContain('does not contain a statement')
        ->and($fail('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>1</Id></Stmt></BkToCstmrStmt></Document>'))->toContain('no valid header');

    $cbi = explode("\n", trim(bsCbiFixture(), "\n"));
    expect(fn () => app(CbiStatementParser::class)->parse(implode("\n", [$cbi[1], $cbi[4]])))->toThrow(BankStatementException::class, 'no valid header');
    expect($fail(implode("\n", [$cbi[0], rtrim($cbi[1])])))->toContain('Line 2 has')
        ->and($fail(implode("\n", array_slice($cbi, 0, 4))))->toContain('EF trailer')
        ->and($fail(implode("\n", [$cbi[0], $cbi[2], $cbi[4]])))->toContain('invalid or unexpected 62 record')
        ->and($fail(implode("\n", [$cbi[0], $cbi[1], $cbi[4]])))->toContain('declares 4 records but 2 were read')
        ->and($fail(implode("\n", [$cbi[0], str_pad('99', 120), $cbi[4]])))->toContain('invalid or unexpected 99 record')
        ->and($fail(implode("\n", [$cbi[0], str_replace('261023261023C', '991399261023C', $cbi[1]), $cbi[2], $cbi[3], $cbi[4]])))->toContain('invalid or unexpected date record');
});

// ---------------------------------------------------------------------------------------------------------------
// Import endpoint
// ---------------------------------------------------------------------------------------------------------------

test('every format is imported through the endpoint and stored with its lines', function (): void {
    $cbi = bsImport(bsCbiFixture(), 'flow.cbi')->assertCreated();
    expect($cbi->json('data.statement.source_format'))->toBe('cbi')
        ->and($cbi->json('data.statement.status'))->toBe('draft')
        ->and($cbi->json('data.statement.total_entries'))->toBe(1)
        ->and($cbi->json('data.statement.opening_balance'))->toEqual(5000)
        ->and($cbi->json('data.statement.lines.0.amount'))->toEqual(150.25)
        ->and($cbi->json('data.statement.lines.0.abi_code'))->toBe('048')
        ->and($cbi->json('data.reconciliation'))->toBeNull();

    $camt053 = bsImport(bsCamt053Fixture())->assertCreated();
    expect($camt053->json('data.statement.source_format'))->toBe('camt053')
        ->and($camt053->json('data.statement.closing_balance'))->toEqual(5147.5)
        ->and($camt053->json('data.statement.lines.0.fee_amount'))->toEqual(2.5)
        ->and($camt053->json('data.statement.lines.0.gross_amount'))->toEqual(150);

    $camt054 = bsImport(bsCamt054Fixture())->assertCreated();
    expect($camt054->json('data.statement.source_format'))->toBe('camt054')
        ->and($camt054->json('data.statement.currency'))->toBe('CHF')
        ->and($camt054->json('data.statement.opening_balance'))->toBeNull()
        ->and($camt054->json('data.statement.lines.0.qr_reference'))->toBe('210000000003139471430009017');

    expect(BankStatement::count())->toBe(3)->and(BankStatementLine::count())->toBe(3);
});

test('the same file cannot be imported twice and invalid files are rejected', function (): void {
    bsImport(bsCamt053Fixture())->assertCreated();
    bsImport(bsCamt053Fixture())->assertUnprocessable()->assertJsonValidationErrors(['file']);

    bsImport('not a bank file', 'garbage.txt')->assertUnprocessable()->assertJsonPath('title', 'Bank statement import');
    bsImport(str_replace("\n", '', bsCbiFixture()), 'oneline.cbi')->assertUnprocessable();
    bsImport(bsCamt053Fixture(), 'statement.pdf')->assertUnprocessable()->assertJsonValidationErrors(['file']);
    bsApi()->postJson('/api/v1/banking/statements/import', [])->assertUnprocessable();

    expect(BankStatement::count())->toBe(1);
});

test('statements are listed with filters and shown with their lines', function (): void {
    $cbi = bsImport(bsCbiFixture(), 'flow.cbi')->json('data.statement.id');
    bsImport(bsCamt053Fixture());
    bsImport(bsCamt054Fixture());

    expect(bsApi()->getJson('/api/v1/banking/statements')->assertOk()->json('data'))->toHaveCount(3);
    expect(bsApi()->getJson('/api/v1/banking/statements?source_format=camt054')->json('data'))->toHaveCount(1);
    expect(bsApi()->getJson('/api/v1/banking/statements?iban=it60 x054 2811 1010 0000 0123 456')->json('data'))->toHaveCount(2);
    expect(bsApi()->getJson('/api/v1/banking/statements?status=reconciled')->json('data'))->toHaveCount(0);
    bsApi()->getJson('/api/v1/banking/statements?status=weird')->assertUnprocessable();

    $shown = bsApi()->getJson("/api/v1/banking/statements/{$cbi}")->assertOk();
    expect($shown->json('data.lines'))->toHaveCount(1)->and($shown->json('data.lines.0.reconciled'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------
// Reconciliation
// ---------------------------------------------------------------------------------------------------------------

test('a Swiss QR reference reconciles the invoice deterministically and settles it', function (): void {
    $invoice = bsInvoice('INV-CH-1', 850, ['currency' => 'CHF']);
    InvoiceQrBillData::create(['invoice_id' => $invoice->id, 'qr_iban' => 'CH4431999123000889012', 'scor_reference' => '21 00000 00003 13947 14300 09017', 'creditor_info' => [], 'debtor_info' => []]);
    bsInvoice('INV-CH-2', 850, ['currency' => 'CHF']);

    $response = bsImport(bsCamt054Fixture(), 'notification.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 0])
        ->and($response->json('data.statement.status'))->toBe('reconciled')
        ->and($response->json('data.statement.lines.0.reconciled'))->toBeTrue()
        ->and($response->json('data.statement.lines.0.reconciliation_type'))->toBe('qr_reference')
        ->and($response->json('data.statement.lines.0.matched_document_id'))->toBe($invoice->id);

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(850.0)
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 850.0]]);
    expect(Invoice::where('invoice_number', 'INV-CH-2')->first()->payment_status)->toBe('unpaid');
});

test('a QR reference never matches an invoice in another currency', function (): void {
    $invoice = bsInvoice('INV-EUR', 850);
    InvoiceQrBillData::create(['invoice_id' => $invoice->id, 'qr_iban' => 'CH4431999123000889012', 'scor_reference' => '210000000003139471430009017', 'creditor_info' => [], 'debtor_info' => []]);

    bsImport(bsCamt054Fixture(), 'notification.xml', '?auto_reconcile=1')->assertCreated()->assertJsonPath('data.reconciliation.unmatched', 1);

    expect($invoice->refresh()->payment_status)->toBe('unpaid');
});

test('the CBI fixture reconciles by the invoice number found in the description', function (): void {
    $invoice = bsInvoice('INV-2026-0042', 150.25);
    bsInvoice('INV-2026-004', 150.25);

    $response = bsImport(bsCbiFixture(), 'flow.cbi', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.statement.lines.0.reconciliation_type'))->toBe('exact')
        ->and($response->json('data.statement.lines.0.matched_document_id'))->toBe($invoice->id)
        ->and($invoice->refresh()->payment_status)->toBe('paid');
});

test('a bank fee withheld at source closes the invoice with a separate fee payment', function (): void {
    $invoice = bsInvoice('INV-2026-0088', 150.00);

    $response = bsImport(bsCamt053Fixture(), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.statement.lines.0.reconciliation_type'))->toBe('exact')
        ->and($response->json('data.statement.status'))->toBe('reconciled');

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(150.0)
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 147.5], ['type' => 'bank_fee', 'amount' => 2.5]]);
});

test('a cent difference within the tolerance is booked as a rounding adjustment', function (): void {
    $invoice = bsInvoice('INV-9001', 150.00);

    $response = bsImport(bsCamt053(['amount' => '149.97', 'description' => 'Saldo INV-9001']), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.statement.lines.0.reconciliation_type'))->toBe('fuzzy_tolerance');
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(150.0)
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 149.97], ['type' => 'adjustment', 'amount' => 0.03]]);
});

test('a small excess over the balance is absorbed instead of overpaying the invoice', function (): void {
    $invoice = bsInvoice('INV-9002', 150.00);

    bsImport(bsCamt053(['amount' => '150.04', 'description' => 'INV-9002']), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and((float) $invoice->total_paid)->toBe(150.0)
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 150.0]]);
});

test('a smaller payment is booked as partial and the rest can be paid later', function (): void {
    $invoice = bsInvoice('INV-9003', 150.00);

    $first = bsImport(bsCamt053(['amount' => '100.00', 'description' => 'Acconto INV-9003']), 'first.xml', '?auto_reconcile=1')->assertCreated();

    expect($first->json('data.statement.lines.0.reconciliation_type'))->toBe('partial')
        ->and($first->json('data.statement.lines.0.reconciled'))->toBeTrue()
        ->and($first->json('data.statement.status'))->toBe('reconciled');
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('partially_paid')->and((float) $invoice->total_paid)->toBe(100.0);

    $second = bsImport(bsCamt053(['amount' => '50.00', 'description' => 'Saldo INV-9003']), 'second.xml', '?auto_reconcile=1')->assertCreated();

    expect($second->json('data.statement.lines.0.reconciliation_type'))->toBe('exact');
    expect($invoice->refresh()->payment_status)->toBe('paid');
});

test('a partial payment also counts the fee the bank withheld', function (): void {
    $invoice = bsInvoice('INV-9004', 150.00);

    bsImport(bsCamt053(['amount' => '97.50', 'fee' => '2.50', 'description' => 'INV-9004']), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('partially_paid')
        ->and((float) $invoice->total_paid)->toBe(100.0)
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 97.5], ['type' => 'bank_fee', 'amount' => 2.5]]);
});

test('overpayments and oversized fees are left for manual handling', function (): void {
    $over = bsInvoice('INV-9005', 150.00);
    $fee = bsInvoice('INV-9006', 150.00);

    $response = bsImport(bsCamt053([
        ['amount' => '200.00', 'description' => 'INV-9005'],
        ['amount' => '130.00', 'fee' => '20.00', 'description' => 'INV-9006'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 2])
        ->and($response->json('data.statement.status'))->toBe('draft')
        ->and($over->refresh()->payment_status)->toBe('unpaid')
        ->and($fee->refresh()->payment_status)->toBe('unpaid');
});

test('the tenant reconciliation preferences drive tolerance, partial payments and the fee limit', function (): void {
    bsApi()->putJson('/api/v1/settings/preferences/banking', ['reconciliation' => ['auto_match_tolerance_amount' => 'lots']])->assertUnprocessable();
    bsApi()->putJson('/api/v1/settings/preferences/banking', ['reconciliation' => ['allow_partial_reconciliation' => 'maybe']])->assertUnprocessable();
    bsApi()->putJson('/api/v1/settings/preferences/banking', ['reconciliation' => ['max_fee_deduction_amount' => -1]])->assertUnprocessable();

    $partial = bsInvoice('INV-9007', 150.00);
    $tolerance = bsInvoice('INV-9008', 150.00);
    $fee = bsInvoice('INV-9009', 150.00);

    bsSetPreferences(['allow_partial_reconciliation' => false, 'auto_match_tolerance_amount' => 0.5, 'max_fee_deduction_amount' => 1]);
    expect(bsApi()->getJson('/api/v1/settings/preferences')->json('data.banking.reconciliation'))->toEqual(['allow_partial_reconciliation' => false, 'auto_match_tolerance_amount' => 0.5, 'max_fee_deduction_amount' => 1]);

    $response = bsImport(bsCamt053([
        ['amount' => '100.00', 'description' => 'INV-9007'],
        ['amount' => '149.70', 'description' => 'INV-9008'],
        ['amount' => '147.50', 'fee' => '2.50', 'description' => 'INV-9009'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 2])
        ->and($response->json('data.statement.status'))->toBe('partially_reconciled')
        ->and($partial->refresh()->payment_status)->toBe('unpaid')
        ->and($tolerance->refresh()->payment_status)->toBe('paid')
        ->and($fee->refresh()->payment_status)->toBe('unpaid');

    // Single options can be changed or cleared without touching the others.
    bsSetPreferences(['max_fee_deduction_amount' => null, 'allow_partial_reconciliation' => true]);
    expect(bsApi()->getJson('/api/v1/settings/preferences')->json('data.banking.reconciliation'))->toEqual(['allow_partial_reconciliation' => true, 'auto_match_tolerance_amount' => 0.5]);
});

test('a customer is recognised by IBAN or VAT number when exactly one invoice has that balance', function (): void {
    $this->customer->update(['iban' => 'DE89 3704 0044 0532 0130 00', 'vat_number' => 'IT00811720580']);
    $byIban = bsInvoice('A-1', 99.99);

    $response = bsImport(bsCamt053(['amount' => '99.99', 'description' => 'Bonifico generico', 'iban' => 'DE89370400440532013000']), 'iban.xml', '?auto_reconcile=1')->assertCreated();
    expect($response->json('data.statement.lines.0.reconciliation_type'))->toBe('exact')
        ->and($byIban->refresh()->payment_status)->toBe('paid');

    $byVat = bsInvoice('A-2', 40.00);
    bsImport(bsCamt053(['amount' => '40.00', 'description' => 'Pagamento P.IVA IT00811720580 grazie']), 'vat.xml', '?auto_reconcile=1')->assertCreated();
    expect($byVat->refresh()->payment_status)->toBe('paid');

    // Two invoices with the same balance are ambiguous: nothing is booked.
    $first = bsInvoice('A-3', 70.00);
    $second = bsInvoice('A-4', 70.00);
    $ambiguous = bsImport(bsCamt053(['amount' => '70.00', 'description' => 'Generic', 'iban' => 'DE89370400440532013000']), 'ambiguous.xml', '?auto_reconcile=1')->assertCreated();
    expect($ambiguous->json('data.reconciliation.unmatched'))->toBe(1)
        ->and($first->refresh()->payment_status)->toBe('unpaid')
        ->and($second->refresh()->payment_status)->toBe('unpaid');

    // A different amount is not enough, even for a known customer.
    $other = bsInvoice('A-5', 33.00);
    bsImport(bsCamt053(['amount' => '30.00', 'description' => 'Generic', 'iban' => 'DE89370400440532013000']), 'amount.xml', '?auto_reconcile=1')->assertCreated();
    expect($other->refresh()->payment_status)->toBe('unpaid');
});

test('outgoing lines that match nothing keep the statement partially reconciled', function (): void {
    $invoice = bsInvoice('INV-9010', 80.00);

    $response = bsImport(bsCamt053([
        ['amount' => '80.00', 'description' => 'INV-9010'],
        ['amount' => '20.00', 'direction' => 'DBIT', 'description' => 'Supplier payment INV-9010'],
    ]), 'statement.xml', '?auto_reconcile=1')->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 1])
        ->and($response->json('data.statement.status'))->toBe('partially_reconciled')
        ->and(collect($response->json('data.statement.lines'))->firstWhere('direction', 'debit')['reconciled'])->toBeFalse()
        ->and($invoice->refresh()->payment_status)->toBe('paid');
});

test('the reconcile endpoint processes the lines that are still open', function (): void {
    $statementId = bsImport(bsCamt053(['amount' => '60.00', 'description' => 'INV-9011']))->assertCreated()->json('data.statement.id');
    $invoice = bsInvoice('INV-9011', 60.00);

    $response = bsApi()->postJson("/api/v1/banking/statements/{$statementId}/reconcile")->assertOk();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 0])
        ->and($response->json('data.statement.status'))->toBe('reconciled')
        ->and($invoice->refresh()->payment_status)->toBe('paid');

    expect(bsApi()->postJson("/api/v1/banking/statements/{$statementId}/reconcile")->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 0]);
});

// ---------------------------------------------------------------------------------------------------------------
// Manual matching and unmatching
// ---------------------------------------------------------------------------------------------------------------

test('a line is matched manually, with the difference accepted as write-off or partial payment', function (): void {
    $statement = bsImport(bsCamt053([
        ['amount' => '90.00', 'description' => 'unknown payer one'],
        ['amount' => '60.00', 'description' => 'unknown payer two'],
        ['amount' => '100.00', 'description' => 'unknown payer three'],
    ]))->assertCreated()->json('data.statement');
    [$one, $two, $three] = array_column($statement['lines'], 'id');
    $invoice = bsInvoice('M-1', 100.00);

    // Without handling a 10.00 difference is refused.
    bsApi()->postJson("/api/v1/banking/statements/lines/{$one}/manual-match", ['invoice_id' => $invoice->id])->assertUnprocessable()->assertJsonValidationErrors(['difference_handling']);

    $written = bsApi()->postJson("/api/v1/banking/statements/lines/{$one}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => 'write_off'])->assertOk();
    expect($written->json('data.reconciliation_type'))->toBe('manual')
        ->and($written->json('data.matched_document_id'))->toBe($invoice->id);
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and(bsPayments($invoice))->toBe([['type' => 'payment', 'amount' => 90.0], ['type' => 'adjustment', 'amount' => 10.0]]);

    // The invoice is settled, so it cannot receive more.
    bsApi()->postJson("/api/v1/banking/statements/lines/{$two}/manual-match", ['invoice_id' => $invoice->id])->assertUnprocessable()->assertJsonValidationErrors(['invoice_id']);

    $partialInvoice = bsInvoice('M-2', 200.00);
    $partial = bsApi()->postJson("/api/v1/banking/statements/lines/{$two}/manual-match", ['invoice_id' => $partialInvoice->id, 'difference_handling' => 'partial'])->assertOk();
    expect($partial->json('data.reconciled'))->toBeTrue()
        ->and($partialInvoice->refresh()->payment_status)->toBe('partially_paid')
        ->and((float) $partialInvoice->total_paid)->toBe(60.0);

    // An exact amount needs no handling; the statement is complete once every line is matched.
    $exact = bsInvoice('M-3', 100.00);
    bsApi()->postJson("/api/v1/banking/statements/lines/{$three}/manual-match", ['invoice_id' => $exact->id])->assertOk();
    expect($exact->refresh()->payment_status)->toBe('paid')
        ->and(BankStatement::find($statement['id'])->status)->toBe('reconciled');
});

test('manual matching refuses overpayments, reconciled lines, debits, other currencies and unknown invoices', function (): void {
    $statement = bsImport(bsCamt053([
        ['amount' => '300.00', 'description' => 'unknown payer'],
        ['amount' => '10.00', 'direction' => 'DBIT', 'description' => 'outgoing'],
    ]))->assertCreated()->json('data.statement');
    $credit = collect($statement['lines'])->firstWhere('direction', 'credit')['id'];
    $debit = collect($statement['lines'])->firstWhere('direction', 'debit')['id'];
    $invoice = bsInvoice('M-4', 100.00);
    $swiss = bsInvoice('M-5', 300.00, ['currency' => 'CHF']);

    foreach (['write_off', 'partial', 'none'] as $handling) {
        bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => $handling])->assertUnprocessable();
    }
    bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $swiss->id])->assertUnprocessable();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$debit}/manual-match", ['invoice_id' => $invoice->id])->assertUnprocessable();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => (string) Str::uuid()])->assertUnprocessable();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => 'forgive'])->assertUnprocessable();
    bsApi()->postJson('/api/v1/banking/statements/lines/'.Str::uuid().'/manual-match', ['invoice_id' => $invoice->id])->assertNotFound();

    expect($invoice->refresh()->payment_status)->toBe('unpaid')->and(Payment::withoutGlobalScopes()->count())->toBe(0);

    $big = bsInvoice('M-6', 300.00);
    bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $big->id])->assertOk();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$credit}/manual-match", ['invoice_id' => $invoice->id])->assertUnprocessable()->assertJsonValidationErrors(['line']);
});

test('unmatching restores the invoice and reopens the line, for automatic and manual matches alike', function (): void {
    $invoice = bsInvoice('INV-2026-0088', 150.00);
    $statement = bsImport(bsCamt053Fixture(), 'statement.xml', '?auto_reconcile=1')->assertCreated()->json('data.statement');
    $line = $statement['lines'][0]['id'];

    expect($invoice->refresh()->payment_status)->toBe('paid');

    $unmatched = bsApi()->postJson("/api/v1/banking/statements/lines/{$line}/unmatch")->assertOk();

    expect($unmatched->json('data.reconciled'))->toBeFalse()
        ->and($unmatched->json('data.matched_document_id'))->toBeNull()
        ->and($unmatched->json('data.reconciliation_type'))->toBeNull();
    $invoice->refresh();
    expect($invoice->payment_status)->toBe('unpaid')
        ->and((float) $invoice->total_paid)->toBe(0.0)
        ->and(bsPayments($invoice))->toBe([])
        ->and(BankStatement::find($statement['id'])->status)->toBe('draft');

    // Nothing left to undo.
    bsApi()->postJson("/api/v1/banking/statements/lines/{$line}/unmatch")->assertUnprocessable();

    // After a partial payment the unmatch only removes that payment.
    $other = bsInvoice('INV-9020', 500.00);
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $other->id, 'amount' => 200, 'paid_at' => '2026-10-01']);
    $other->update(['total_paid' => 200, 'payment_status' => 'partially_paid']);
    $manual = bsImport(bsCamt053(['amount' => '300.00', 'description' => 'anything']))->json('data.statement.lines.0.id');
    bsApi()->postJson("/api/v1/banking/statements/lines/{$manual}/manual-match", ['invoice_id' => $other->id])->assertOk();
    expect($other->refresh()->payment_status)->toBe('paid');

    bsApi()->postJson("/api/v1/banking/statements/lines/{$manual}/unmatch")->assertOk();
    expect($other->refresh()->payment_status)->toBe('partially_paid')->and((float) $other->total_paid)->toBe(200.0);

    // The line can be matched again, to the right invoice this time.
    $right = bsInvoice('INV-9021', 300.00);
    bsApi()->postJson("/api/v1/banking/statements/lines/{$manual}/manual-match", ['invoice_id' => $right->id])->assertOk();
    expect($right->refresh()->payment_status)->toBe('paid');
});

// ---------------------------------------------------------------------------------------------------------------
// Isolation
// ---------------------------------------------------------------------------------------------------------------

test('statements, lines and invoices of other tenants are out of reach', function (): void {
    $foreignInvoice = bsInvoice('INV-2026-0088', 150.00, ['tenant_id' => $this->otherTenant->id]);

    // The file of tenant A never touches the invoice of tenant B, even with the same invoice number.
    $own = bsImport(bsCamt053Fixture(), 'statement.xml', '?auto_reconcile=1')->assertCreated();
    expect($own->json('data.reconciliation.unmatched'))->toBe(1)
        ->and($foreignInvoice->refresh()->payment_status)->toBe('unpaid');

    // Tenant B imports the very same file (the duplicate check is per tenant) and reconciles its own invoice.
    $theirs = bsImport(bsCamt053Fixture(), 'statement.xml', '?auto_reconcile=1', $this->otherTenant->id)->assertCreated();
    expect($theirs->json('data.reconciliation.reconciled'))->toBe(1)
        ->and($foreignInvoice->refresh()->payment_status)->toBe('paid');

    $ownId = $own->json('data.statement.id');
    $ownLine = $own->json('data.statement.lines.0.id');
    $theirId = $theirs->json('data.statement.id');
    $theirLine = $theirs->json('data.statement.lines.0.id');

    expect(bsApi()->getJson('/api/v1/banking/statements')->json('data.*.id'))->toBe([$ownId])
        ->and(bsApi($this->otherTenant->id)->getJson('/api/v1/banking/statements')->json('data.*.id'))->toBe([$theirId]);

    bsApi()->getJson("/api/v1/banking/statements/{$theirId}")->assertNotFound();
    bsApi()->postJson("/api/v1/banking/statements/{$theirId}/reconcile")->assertNotFound();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$theirLine}/unmatch")->assertNotFound();
    bsApi()->postJson("/api/v1/banking/statements/lines/{$theirLine}/manual-match", ['invoice_id' => bsInvoice('OWN-1', 150.00)->id])->assertNotFound();
    bsApi($this->otherTenant->id)->getJson("/api/v1/banking/statements/{$ownId}")->assertNotFound();

    // A line of tenant A cannot be matched with an invoice of tenant B.
    bsApi()->postJson("/api/v1/banking/statements/lines/{$ownLine}/manual-match", ['invoice_id' => bsInvoice('OTHER-2', 147.50, ['tenant_id' => $this->otherTenant->id])->id])->assertUnprocessable();

    expect(BankStatementLine::withoutGlobalScopes()->find($theirLine)->reconciled)->toBeTrue();
});
