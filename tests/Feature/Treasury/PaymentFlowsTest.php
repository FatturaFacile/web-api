<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\PaymentBatch;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->itBank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $this->chBank = treasuryBank($this->tenant, 'CH9300762011623852957', $this->accounts['1002'], ['currency' => 'CHF', 'bic_swift' => 'POFICHBEXXX']);
    $this->foreignBank = treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function flowPayload(string $flow, string $bankId, array $overrides = []): array
{
    return array_merge([
        'flow' => $flow,
        'bank_account_id' => $bankId,
        'execution_date' => now()->addDay()->toDateString(),
        'lines' => [['recipient_name' => 'Fornitore GmbH', 'recipient_iban' => 'DE89 3704 0044 0532 0130 00', 'amount' => '100.50', 'currency' => 'EUR', 'remittance_reference' => 'Invoice 7']],
    ], $overrides);
}

test('the catalogue lists the flows with version, currencies and CUC requirement', function (): void {
    $flows = collect($this->getJson('/api/v1/treasury/payment-flows')->assertOk()->json('data'))->keyBy('flow');

    expect($flows->keys()->all())->toBe(['ch_pain001_09', 'ch_pain001_03', 'sepa_pain001_03', 'cbi_sepa_03'])
        ->and($flows['ch_pain001_09']['pain_version'])->toBe('09')
        ->and($flows['ch_pain001_09']['currencies'])->toBe(['CHF', 'EUR'])
        ->and($flows['cbi_sepa_03']['requires_cuc'])->toBeTrue()
        ->and($flows['sepa_pain001_03']['requires_cuc'])->toBeFalse();
});

test('a SEPA payment file is generated, schema valid and summarised', function (): void {
    $data = $this->postJson('/api/v1/treasury/payment-flows/preview', flowPayload('sepa_pain001_03', $this->itBank->id))->assertOk()->json('data');

    expect($data['pain_version'])->toBe('03')
        ->and($data['transactions'])->toBe(1)
        ->and($data['control_sum'])->toBe('100.50')
        ->and($data['currency'])->toBe('EUR')
        ->and($data['sha256'])->toBe(hash('sha256', $data['xml']))
        ->and($data['xml'])->toContain('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03')->toContain('DE89370400440532013000');
});

test('a CBI file carries the CUC of the company as the initiating party', function (): void {
    $payload = flowPayload('cbi_sepa_03', $this->itBank->id, ['cuc' => 'abc12345']);
    $xml = $this->postJson('/api/v1/treasury/payment-flows/preview', $payload)->assertOk()->json('data.xml');

    expect($xml)->toContain('<Id>ABC12345</Id>')->toContain('<Issr>CBI</Issr>');

    $this->postJson('/api/v1/treasury/payment-flows/preview', flowPayload('cbi_sepa_03', $this->itBank->id))->assertUnprocessable()->assertJsonValidationErrors(['cuc']);
});

test('a Swiss QR-bill payment goes out as pain.001.001.09 with a QRR reference', function (): void {
    $payload = flowPayload('ch_pain001_09', $this->chBank->id, ['currency' => 'CHF', 'lines' => [[
        'recipient_name' => 'Robert Schneider AG', 'recipient_iban' => 'CH4431999123000889012', 'amount' => '1949.75', 'currency' => 'CHF',
        'structured_creditor_ref' => '210000000003139471430009017',
    ]]]);
    $data = $this->postJson('/api/v1/treasury/payment-flows/preview', $payload)->assertOk()->json('data');

    expect($data['pain_version'])->toBe('09')->and($data['control_sum'])->toBe('1949.75')
        ->and($data['xml'])->toContain('pain.001.001.09')->toContain('<Prtry>QRR</Prtry>')->toContain('210000000003139471430009017');
});

test('a QR-IBAN without a QR reference is refused before any file exists', function (): void {
    $payload = flowPayload('ch_pain001_09', $this->chBank->id, ['lines' => [[
        'recipient_name' => 'Robert Schneider AG', 'recipient_iban' => 'CH4431999123000889012', 'amount' => '10.00', 'currency' => 'CHF',
    ]]]);

    $this->postJson('/api/v1/treasury/payment-flows/preview', $payload)->assertUnprocessable()->assertJsonValidationErrors(['lines']);
});

test('every broken rule is reported at once with the path of the line', function (): void {
    $payload = flowPayload('sepa_pain001_03', $this->itBank->id, ['lines' => [
        ['recipient_name' => 'Uno', 'recipient_iban' => 'DE89370400440532013000', 'amount' => '10.00', 'currency' => 'CHF'],
        ['recipient_name' => 'Due', 'recipient_iban' => 'IT00X0542811101000000123456', 'amount' => '10.00', 'currency' => 'EUR'],
    ]]);

    $this->postJson('/api/v1/treasury/payment-flows/preview', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.currency', 'lines.1.recipient_iban', 'lines']);
});

test('the debited account must belong to the company and the date cannot be in the past', function (): void {
    $this->postJson('/api/v1/treasury/payment-flows/preview', flowPayload('sepa_pain001_03', $this->foreignBank->id))->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id']);
    $this->postJson('/api/v1/treasury/payment-flows/preview', flowPayload('sepa_pain001_03', $this->itBank->id, ['execution_date' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors(['execution_date']);
});

test('a draft payment batch is created with the CBI CUC and the exported file keeps the CUC', function (): void {
    $payload = flowPayload('cbi_sepa_03', $this->itBank->id, ['cuc' => 'ABC12345']);
    $batchId = $this->postJson('/api/v1/treasury/payment-flows/batches', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $batch = PaymentBatch::query()->findOrFail($batchId);

    expect($batch->format)->toBe('sepa_pain_001_it')
        ->and($batch->pain_version)->toBe('03')
        ->and($batch->cbi_cuc)->toBe('ABC12345')
        ->and($batch->xml_sha256)->not->toBeNull();

    $this->get("/api/v1/treasury/payment-batches/{$batchId}/download-xml")->assertOk()->assertSee('<Issr>CBI</Issr>', false);
});

test('a Swiss batch is stored with the Swiss format and version 09', function (): void {
    $payload = flowPayload('ch_pain001_09', $this->chBank->id, ['currency' => 'CHF', 'lines' => [[
        'recipient_name' => 'Café Müller AG', 'recipient_iban' => 'CH9300762011623852957', 'amount' => '250.00', 'currency' => 'CHF', 'structured_creditor_ref' => 'RF18539007547034',
    ]]]);
    $batchId = $this->postJson('/api/v1/treasury/payment-flows/batches', $payload)->assertCreated()->json('data.id');
    $batch = PaymentBatch::query()->findOrFail($batchId);

    expect($batch->format)->toBe('iso_pain_001_ch')->and($batch->pain_version)->toBe('09')->and($batch->cbi_cuc)->toBeNull();
});
