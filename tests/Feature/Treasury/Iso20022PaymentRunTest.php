<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->chBank = treasuryBank($this->tenant, 'CH9300762011623852957', $this->accounts['1002'], ['currency' => 'CHF', 'bic_swift' => 'POFICHBEXXX']);
});

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function runPayload(string $bankId, array $lines, array $overrides = [], string $flow = 'ch_pain001_09'): array
{
    return array_merge([
        'flow' => $flow,
        'bank_account_id' => $bankId,
        'execution_date' => now()->addDay()->toDateString(),
        'currency' => 'CHF',
        'lines' => $lines,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function runLine(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Robert Schneider AG', 'recipient_iban' => 'CH4431999123000889012', 'amount' => '1949.75', 'currency' => 'CHF',
        'structured_creditor_ref' => '210000000003139471430009017',
    ], $overrides);
}

/**
 * Stores a batch the way the bank sees it after the export: waiting for the outcome under the given message.
 */
function runExported(object $test, array $lines, string $messageId = 'MSG-RUN-1'): PaymentBatch
{
    $batchId = $test->postJson('/api/v1/treasury/payment-flows/batches', runPayload($test->chBank->id, $lines))->assertCreated()->json('data.id');
    PaymentBatch::query()->whereKey($batchId)->update(['status' => 'exported', 'pain001_message_id' => $messageId]);

    return PaymentBatch::query()->findOrFail($batchId);
}

function runPain002(string $messageId, string $groupStatus, string $transactions): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.10"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>STS-{$messageId}</MsgId><CreDtTm>2026-03-16T07:30:00</CreDtTm></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>{$messageId}</OrgnlMsgId><OrgnlMsgNmId>pain.001.001.09</OrgnlMsgNmId><GrpSts>{$groupStatus}</GrpSts></OrgnlGrpInfAndSts>
        <OrgnlPmtInfAndSts><OrgnlPmtInfId>PMT-1</OrgnlPmtInfId><PmtInfSts>{$groupStatus}</PmtInfSts>{$transactions}</OrgnlPmtInfAndSts>
        </CstmrPmtStsRpt></Document>
        XML;
}

test('a salary run goes out with the SALA category purpose and the chosen charge bearer, schema valid', function (): void {
    $payload = runPayload($this->chBank->id, [runLine(['recipient_iban' => 'CH9300762011623852957', 'structured_creditor_ref' => null, 'remittance_reference' => 'Salary March 2026', 'amount' => '5200.00'])], ['category_purpose' => 'SALA', 'charge_bearer' => 'SHAR']);

    $data = $this->postJson('/api/v1/treasury/payment-flows/preview', $payload)->assertOk()->json('data');

    expect($data['xml'])->toContain('<CtgyPurp>')->toContain('<Cd>SALA</Cd>')->toContain('<ChrgBr>SHAR</ChrgBr>')
        ->and($data['control_sum'])->toBe('5200.00');
});

test('the category purpose and the charge bearer are stored with the batch and survive the export', function (): void {
    $payload = runPayload($this->chBank->id, [runLine()], ['category_purpose' => 'SUPP', 'charge_bearer' => 'SLEV']);
    $response = $this->postJson('/api/v1/treasury/payment-flows/batches', $payload)->assertCreated();

    expect($response->json('data.category_purpose'))->toBe('SUPP')
        ->and($response->json('data.charge_bearer'))->toBe('SLEV');

    $this->get('/api/v1/treasury/payment-batches/'.$response->json('data.id').'/download-xml')->assertOk()->assertSee('<Cd>SUPP</Cd>', false)->assertSee('<ChrgBr>SLEV</ChrgBr>', false);
});

test('an unknown category purpose or charge bearer is refused', function (): void {
    $this->postJson('/api/v1/treasury/payment-flows/preview', runPayload($this->chBank->id, [runLine()], ['category_purpose' => 'XXXX']))->assertUnprocessable()->assertJsonValidationErrors(['category_purpose']);
    $this->postJson('/api/v1/treasury/payment-flows/preview', runPayload($this->chBank->id, [runLine()], ['charge_bearer' => 'NONE']))->assertUnprocessable()->assertJsonValidationErrors(['charge_bearer']);
});

test('the Swiss pain.001.001.03 flow produces a schema valid file with a QRR reference', function (): void {
    $data = $this->postJson('/api/v1/treasury/payment-flows/preview', runPayload($this->chBank->id, [runLine()], [], 'ch_pain001_03'))->assertOk()->json('data');

    expect($data['pain_version'])->toBe('03')
        ->and($data['xml'])->toContain('pain.001.001.03')->toContain('<Prtry>QRR</Prtry>');

    $flows = collect($this->getJson('/api/v1/treasury/payment-flows')->json('data'))->keyBy('flow');

    expect($flows['ch_pain001_03']['currencies'])->toBe(['CHF', 'EUR'])->and($flows['ch_pain001_03']['is_sepa'])->toBeFalse();
});

test('the same supplier invoice cannot be paid twice, in one file or across batches', function (): void {
    $invoiceId = (string) Str::uuid7();

    $inFile = runPayload($this->chBank->id, [runLine(['invoice_id' => $invoiceId]), runLine(['invoice_id' => $invoiceId, 'amount' => '10.00'])]);
    $this->postJson('/api/v1/treasury/payment-flows/preview', $inFile)->assertUnprocessable()->assertJsonValidationErrors(['lines.1.invoice_id']);

    $this->postJson('/api/v1/treasury/payment-flows/batches', runPayload($this->chBank->id, [runLine(['invoice_id' => $invoiceId])]))->assertCreated();

    $again = $this->postJson('/api/v1/treasury/payment-flows/batches', runPayload($this->chBank->id, [runLine(['invoice_id' => $invoiceId])]))->assertUnprocessable();

    expect($again->json('errors'))->toHaveKey('lines.0.invoice_id')
        ->and($again->json('errors')['lines.0.invoice_id'][0])->toContain('PAY-')
        ->and(PaymentBatch::query()->count())->toBe(1);
});

test('an end-to-end identifier already used by an active batch is refused', function (): void {
    $this->postJson('/api/v1/treasury/payment-flows/batches', runPayload($this->chBank->id, [runLine(['end_to_end_id' => 'E2E-FIXED-1'])]))->assertCreated();

    $this->postJson('/api/v1/treasury/payment-flows/preview', runPayload($this->chBank->id, [runLine(['end_to_end_id' => 'E2E-FIXED-1'])]))->assertUnprocessable()->assertJsonValidationErrors(['lines.0.end_to_end_id']);
    $this->postJson('/api/v1/treasury/payment-flows/preview', runPayload($this->chBank->id, [runLine(['end_to_end_id' => 'E2E-FIXED-2']), runLine(['end_to_end_id' => 'E2E-FIXED-2'])]))->assertUnprocessable()->assertJsonValidationErrors(['lines.1.end_to_end_id']);
});

test('a pain.002 uploaded without a batch finds it through the message it answers and records the reason of each rejection', function (): void {
    $batch = runExported($this, [
        runLine(['end_to_end_id' => 'E2E-RUN-A', 'amount' => '100.00']),
        runLine(['end_to_end_id' => 'E2E-RUN-B', 'amount' => '200.00']),
    ]);
    $report = runPain002('MSG-RUN-1', 'PART', <<<'XML'
        <TxInfAndSts><OrgnlEndToEndId>E2E-RUN-A</OrgnlEndToEndId><TxSts>RJCT</TxSts><StsRsnInf><Rsn><Cd>AC04</Cd></Rsn><AddtlInf>Account of the creditor is closed</AddtlInf></StsRsnInf></TxInfAndSts>
        <TxInfAndSts><OrgnlEndToEndId>E2E-RUN-B</OrgnlEndToEndId><TxSts>ACTC</TxSts></TxInfAndSts>
        XML);

    $response = $this->post('/api/v1/banking/iso20022/upload', ['file' => UploadedFile::fake()->createWithContent('pain002.xml', $report)], ['Accept' => 'application/json'])->assertCreated();

    expect($response->json('data.type'))->toBe('pain.002')
        ->and($response->json('data.payment_report'))->toMatchArray(['batch_id' => $batch->id, 'group_status' => 'PART', 'accepted' => 0, 'rejected' => 1, 'pending' => 1, 'batch_status' => 'exported'])
        ->and($response->json('data.payment_report.transactions.0'))->toMatchArray(['end_to_end_id' => 'E2E-RUN-A', 'status' => 'RJCT', 'reason_code' => 'AC04', 'additional_info' => 'Account of the creditor is closed', 'line_status' => 'rejected']);

    $rejected = PaymentLine::query()->where('end_to_end_id', 'E2E-RUN-A')->sole();

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->error_code)->toBe('AC04')
        ->and($rejected->error_message)->toBe('Account of the creditor is closed')
        ->and(PaymentLine::query()->where('end_to_end_id', 'E2E-RUN-B')->value('status'))->toBe('pending');
});

test('a pain.002 that answers another message is refused and changes nothing', function (): void {
    $batch = runExported($this, [runLine(['end_to_end_id' => 'E2E-RUN-C'])]);

    $this->post('/api/v1/banking/iso20022/upload', ['file' => UploadedFile::fake()->createWithContent('pain002.xml', runPain002('MSG-SOMEONE-ELSE', 'RJCT', ''))], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($batch->refresh()->status)->toBe('exported')->and(PaymentLine::query()->where('end_to_end_id', 'E2E-RUN-C')->value('status'))->toBe('pending');
});

test('a rejected file closes the batch and frees the invoice for the next payment run', function (): void {
    $invoiceId = (string) Str::uuid7();
    $batch = runExported($this, [runLine(['invoice_id' => $invoiceId, 'end_to_end_id' => 'E2E-RUN-D'])]);

    $this->postJson('/api/v1/treasury/payment-flows/batches', runPayload($this->chBank->id, [runLine(['invoice_id' => $invoiceId])]))->assertUnprocessable();

    $report = runPain002('MSG-RUN-1', 'RJCT', '<TxInfAndSts><OrgnlEndToEndId>E2E-RUN-D</OrgnlEndToEndId><TxSts>RJCT</TxSts><StsRsnInf><Rsn><Cd>AM04</Cd></Rsn></StsRsnInf></TxInfAndSts>');
    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/pain002", ['xml' => $report])->assertOk()->assertJsonPath('status', 'rejected');

    expect(PaymentLine::query()->where('end_to_end_id', 'E2E-RUN-D')->sole())->status->toBe('rejected')->error_code->toBe('AM04');

    $this->postJson('/api/v1/treasury/payment-flows/batches', runPayload($this->chBank->id, [runLine(['invoice_id' => $invoiceId])]))->assertCreated();
});

test('a company can configure the QR-bill identifiers, the pain.001 version and the reconciliation thresholds of a bank account', function (): void {
    $response = $this->putJson("/api/v1/treasury/bank-accounts/{$this->chBank->id}", [
        'qr_iban' => 'CH4431999123000889012', 'qr_iid' => '31999', 'besr_id' => '123456789', 'preferred_pain_version' => '03',
        'reconciliation_tolerance' => '0.10', 'reconciliation_max_fee' => '12.50',
    ])->assertOk();

    expect($response->json())->toMatchArray(['qr_iban' => 'CH4431999123000889012', 'qr_iid' => '31999', 'besr_id' => '123456789', 'preferred_pain_version' => '03', 'reconciliation_tolerance' => 0.1, 'reconciliation_max_fee' => 12.5]);

    $this->putJson("/api/v1/treasury/bank-accounts/{$this->chBank->id}", ['qr_iid' => '12345'])->assertUnprocessable()->assertJsonValidationErrors(['qr_iid']);
    $this->putJson("/api/v1/treasury/bank-accounts/{$this->chBank->id}", ['preferred_pain_version' => '10'])->assertUnprocessable()->assertJsonValidationErrors(['preferred_pain_version']);
    $this->putJson("/api/v1/treasury/bank-accounts/{$this->chBank->id}", ['qr_iban' => 'CH9300762011623852957'])->assertUnprocessable()->assertJsonValidationErrors(['qr_iban']);
});
