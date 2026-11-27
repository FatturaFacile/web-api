<?php

declare(strict_types=1);

use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Treasury\Models\BankAccount;

uses(RefreshDatabase::class);

const HUB_IBAN = 'CH9300762011623852957';
const HUB_QR_IBAN = 'CH4431999123000889012';
const HUB_QRR = '210000000003139471430009017';
const HUB_QRR_BROKEN_CHECK_DIGIT = '210000000003139471430009018';
const HUB_QRR_UNKNOWN = '000000000000000000000999995';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Switzerland AG', 'legal_name' => 'FatturaFacile Switzerland AG', 'country' => 'CH', 'default_currency' => 'CHF', 'iban' => HUB_IBAN, 'qr_iban' => HUB_QR_IBAN]);
    $this->user = User::create(['name' => 'Leonardo', 'email' => 'hub@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Café Müller Zürich AG', 'type' => 'customer', 'country' => 'CH', 'email' => 'customer@example.com']);
    $this->api = $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * A camt.054 notification with one credit entry carrying a QR reference.
 */
function hubCamt054(string $messageId, string $bankRef, string $amount, ?string $qrr, ?string $fee = null): string
{
    $structured = $qrr === null ? '<Ustrd>Payment without reference</Ustrd>' : "<Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>{$qrr}</Ref></CdtrRefInf></Strd>";
    $charges = $fee === null ? '' : "<Chrgs><TtlChrgsAndTaxAmt Ccy=\"CHF\">{$fee}</TtlChrgsAndTaxAmt></Chrgs>";

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08">
          <BkToCstmrDbtCdtNtfctn>
            <GrpHdr><MsgId>{$messageId}</MsgId><CreDtTm>2026-03-15T08:00:00</CreDtTm></GrpHdr>
            <Ntfctn>
              <Id>NTF-{$messageId}</Id>
              <Acct><Id><IBAN>CH4431999123000889012</IBAN></Id></Acct>
              <Ntry>
                <NtryRef>{$bankRef}</NtryRef>
                <Amt Ccy="CHF">{$amount}</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-14</Dt></BookgDt>
                <ValDt><Dt>2026-03-14</Dt></ValDt>
                <AcctSvcrRef>{$bankRef}</AcctSvcrRef>
                {$charges}
                <NtryDtls><TxDtls>
                  <Refs><AcctSvcrRef>{$bankRef}</AcctSvcrRef></Refs>
                  <RltdPties><Dbtr><Nm>Café Müller Zürich AG</Nm></Dbtr></RltdPties>
                  <RmtInf>{$structured}</RmtInf>
                </TxDtls></NtryDtls>
              </Ntry>
            </Ntfctn>
          </BkToCstmrDbtCdtNtfctn>
        </Document>
        XML;
}

function hubUpload(string $xml, string $name = 'bank-file.xml', bool $autoReconcile = true): TestResponse
{
    return test()->api->post('/api/v1/banking/iso20022/upload', [
        'file' => UploadedFile::fake()->createWithContent($name, $xml),
        'auto_reconcile' => $autoReconcile ? '1' : '0',
    ]);
}

function hubInvoice(string $number, float $gross, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->customer->id,
        'invoice_number' => $number, 'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'CHF', 'jurisdiction' => 'CH', 'invoice_type' => 'ch_qr_bill',
        'total_net' => round($gross / 1.081, 2), 'total_tax' => round($gross - $gross / 1.081, 2), 'total_gross' => $gross, 'payment_status' => 'unpaid',
    ], $overrides));
}

/**
 * @return array<string, int>
 */
function hubExceptionCounts(): array
{
    return test()->api->getJson('/api/v1/banking/reconciliation/exceptions')->assertOk()->json('meta.counts');
}

test('the hub detects a camt.054 notification, settles the invoice and reports the summary', function (): void {
    $invoice = hubInvoice('INV-2026-1001', 1949.75, ['qr_reference' => HUB_QRR]);

    $response = hubUpload(hubCamt054('MSG-HUB-1', 'BANK-HUB-1', '1949.75', HUB_QRR), 'camt054.xml')->assertCreated();

    expect($response->json('data.type'))->toBe('camt.054')
        ->and($response->json('data.filename'))->toBe('camt054.xml')
        ->and($response->json('data.reconciliation'))->toBe(['reconciled' => 1, 'unmatched' => 0])
        ->and($response->json('data.duplicates'))->toBe(0)
        ->and($response->json('data.payment_report'))->toBeNull()
        ->and($invoice->refresh()->payment_status)->toBe('paid')
        ->and(hubExceptionCounts())->toBe(['Unmatched' => 0, 'AmountMismatch' => 0, 'DuplicatePayment' => 0, 'AlreadyPaid' => 0, 'InvalidReference' => 0]);
});

test('the hub refuses an outgoing pain.001 file and anything that is not ISO 20022', function (): void {
    hubUpload('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.09"><CstmrCdtTrfInitn/></Document>')->assertUnprocessable();
    hubUpload('<?xml version="1.0"?><invoice><number>1</number></invoice>')->assertUnprocessable();
    hubUpload('not xml at all')->assertUnprocessable();
});

test('a QR reference whose modulo 10 check digit fails is never matched and is queued as an invalid reference', function (): void {
    $invoice = hubInvoice('INV-2026-1002', 500.00, ['qr_reference' => HUB_QRR]);

    $response = hubUpload(hubCamt054('MSG-HUB-2', 'BANK-HUB-2', '500.00', HUB_QRR_BROKEN_CHECK_DIGIT))->assertCreated();

    expect($response->json('data.reconciliation'))->toBe(['reconciled' => 0, 'unmatched' => 1])
        ->and($invoice->refresh()->payment_status)->toBe('unpaid');

    $queue = $this->api->getJson('/api/v1/banking/reconciliation/exceptions?code=InvalidReference')->assertOk();

    expect($queue->json('data'))->toHaveCount(1)
        ->and($queue->json('data.0.exception_code'))->toBe('InvalidReference')
        ->and($queue->json('data.0.exception_candidate'))->toBeNull()
        ->and($queue->json('meta.counts.InvalidReference'))->toBe(1);
});

test('a payment of an unknown but well formed reference is queued as unmatched', function (): void {
    hubInvoice('INV-2026-1003', 120.00, ['qr_reference' => HUB_QRR]);

    hubUpload(hubCamt054('MSG-HUB-3', 'BANK-HUB-3', '99.00', HUB_QRR_UNKNOWN))->assertCreated();

    $queue = $this->api->getJson('/api/v1/banking/reconciliation/exceptions')->assertOk();

    expect($queue->json('data.0.exception_code'))->toBe('Unmatched')
        ->and($queue->json('meta.counts'))->toMatchArray(['Unmatched' => 1, 'InvalidReference' => 0]);
});

test('a payment of the right reference but the wrong amount is an amount mismatch that proposes the invoice', function (): void {
    $invoice = hubInvoice('INV-2026-1004', 500.00, ['qr_reference' => HUB_QRR]);

    hubUpload(hubCamt054('MSG-HUB-4', 'BANK-HUB-4', '650.00', HUB_QRR))->assertCreated();

    $line = $this->api->getJson('/api/v1/banking/reconciliation/exceptions?code=AmountMismatch')->assertOk()->json('data.0');

    expect($line['exception_candidate'])->toMatchArray(['id' => $invoice->id, 'invoice_number' => 'INV-2026-1004', 'kind' => 'customer', 'due_amount' => 500.0, 'currency' => 'CHF'])
        ->and($line['gross_amount'])->toBe(650.0);

    // One click: the shortfall of a lower payment can be forgiven, an excess can never be booked as a payment.
    $this->api->postJson("/api/v1/banking/statements/lines/{$line['id']}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => 'write_off'])->assertUnprocessable();
});

test('the second payment of an invoice a bank movement already settled is a duplicate payment', function (): void {
    $invoice = hubInvoice('INV-2026-1005', 300.00, ['qr_reference' => HUB_QRR]);

    hubUpload(hubCamt054('MSG-HUB-5A', 'BANK-HUB-5A', '300.00', HUB_QRR))->assertCreated()->assertJsonPath('data.reconciliation.reconciled', 1);
    hubUpload(hubCamt054('MSG-HUB-5B', 'BANK-HUB-5B', '300.00', HUB_QRR))->assertCreated()->assertJsonPath('data.reconciliation.unmatched', 1);

    expect(hubExceptionCounts()['DuplicatePayment'])->toBe(1)
        ->and((float) $invoice->refresh()->total_paid)->toBe(300.0);
});

test('a payment of an invoice that was paid by other means is flagged as already paid', function (): void {
    hubInvoice('INV-2026-1006', 250.00, ['qr_reference' => HUB_QRR, 'payment_status' => 'paid']);

    hubUpload(hubCamt054('MSG-HUB-6', 'BANK-HUB-6', '250.00', HUB_QRR))->assertCreated()->assertJsonPath('data.reconciliation.unmatched', 1);

    expect(hubExceptionCounts()['AlreadyPaid'])->toBe(1);
});

test('the review queue filters by direction and search and never shows another company', function (): void {
    hubUpload(hubCamt054('MSG-HUB-7', 'BANK-HUB-7', '10.00', null))->assertCreated();

    $other = Tenant::create(['name' => 'Other AG', 'legal_name' => 'Other AG', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->user->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    expect($this->api->getJson('/api/v1/banking/reconciliation/exceptions?direction=credit&search=without')->json('meta.total'))->toBe(1)
        ->and($this->api->getJson('/api/v1/banking/reconciliation/exceptions?direction=debit')->json('meta.total'))->toBe(0)
        ->and($this->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/banking/reconciliation/exceptions')->json('meta.total'))->toBe(0);

    $this->api->getJson('/api/v1/banking/reconciliation/exceptions?code=Bogus')->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

test('a resolved exception leaves the queue', function (): void {
    $invoice = hubInvoice('INV-2026-1008', 500.00);

    hubUpload(hubCamt054('MSG-HUB-8', 'BANK-HUB-8', '500.00', null))->assertCreated();
    $lineId = $this->api->getJson('/api/v1/banking/reconciliation/exceptions')->json('data.0.id');

    $this->api->postJson("/api/v1/banking/statements/lines/{$lineId}/manual-match", ['invoice_id' => $invoice->id, 'difference_handling' => 'none'])->assertOk();

    expect($this->api->getJson('/api/v1/banking/reconciliation/exceptions')->json('meta.total'))->toBe(0)
        ->and(BankStatementLine::query()->whereKey($lineId)->value('exception_code'))->toBeNull();
});

test('the tolerance of the bank account overrides the company default', function (): void {
    $gl = ChartOfAccount::create(['tenant_id' => $this->tenant->id, 'account_code' => '1021', 'name' => 'Bank', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => 'CHF']);
    BankAccount::create([
        'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'bank_name' => 'PostFinance', 'account_number' => 'A-'.Str::random(6), 'account_type' => 'OPERATING',
        'iban' => HUB_QR_IBAN, 'bic_swift' => 'POFICHBEXXX', 'currency' => 'CHF', 'is_active' => true, 'gl_account_id' => $gl->id, 'reconciliation_tolerance' => 2.00, 'reconciliation_max_fee' => 5.00,
    ]);
    $invoice = hubInvoice('INV-2026-1009', 500.00, ['qr_reference' => HUB_QRR]);

    // The bank deducted CHF 1.50: within the CHF 2.00 tolerance of this account, so the invoice is closed.
    hubUpload(hubCamt054('MSG-HUB-9', 'BANK-HUB-9', '498.50', HUB_QRR))->assertCreated()->assertJsonPath('data.reconciliation.reconciled', 1);

    expect($invoice->refresh()->payment_status)->toBe('paid');
});
