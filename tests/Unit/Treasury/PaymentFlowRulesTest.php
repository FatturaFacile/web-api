<?php

declare(strict_types=1);

use Modules\Treasury\Services\PaymentFlows\CbiInitiatorDecorator;
use Modules\Treasury\Services\PaymentFlows\PaymentFlow;
use Modules\Treasury\Services\PaymentFlows\PaymentFlowRules;

const PFR_CH_IBAN = 'CH9300762011623852957';
const PFR_QR_IBAN = 'CH4431999123000889012';
const PFR_QRR = '210000000003139471430009017';
const PFR_IT_IBAN = 'IT60X0542811101000000123456';
const PFR_DE_IBAN = 'DE89370400440532013000';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pfrLine(array $overrides = []): array
{
    return array_merge(['recipient_name' => 'Fornitore SA', 'recipient_iban' => PFR_DE_IBAN, 'amount' => '100.50', 'currency' => 'EUR', 'remittance_reference' => 'Invoice 1', 'structured_creditor_ref' => null], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @return list<string> issue codes
 */
function pfrCodes(PaymentFlow $flow, string $debtor, array $lines, ?string $cuc = null): array
{
    return array_column(app(PaymentFlowRules::class)->check($flow, $debtor, $lines, $cuc), 'code');
}

test('a valid SEPA payment raises no issue', function (): void {
    expect(pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, [pfrLine()]))->toBe([]);
});

test('the SEPA flow refuses a currency other than EUR and a creditor outside the SEPA area', function (): void {
    expect(pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, [pfrLine(['currency' => 'CHF'])]))->toContain('currency_not_allowed')
        ->and(pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, [pfrLine(['recipient_iban' => 'TR330006100519786457841326'])]))->toContain('iban_country_not_allowed')
        ->and(pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, [pfrLine(['recipient_iban' => 'DE00000000000000000000'])]))->toContain('iban_invalid');
});

test('the Swiss flow carries CHF and EUR, needs a Swiss debtor and checks the QR reference', function (): void {
    expect(pfrCodes(PaymentFlow::ChPain001V09, PFR_CH_IBAN, [pfrLine(['recipient_iban' => PFR_QR_IBAN, 'currency' => 'CHF', 'structured_creditor_ref' => PFR_QRR])]))->toBe([])
        ->and(pfrCodes(PaymentFlow::ChPain001V09, PFR_CH_IBAN, [pfrLine(['currency' => 'USD'])]))->toContain('currency_not_allowed')
        ->and(pfrCodes(PaymentFlow::ChPain001V09, PFR_IT_IBAN, [pfrLine()]))->toContain('debtor_iban_country')
        ->and(pfrCodes(PaymentFlow::ChPain001V09, PFR_CH_IBAN, [pfrLine(['recipient_iban' => PFR_QR_IBAN, 'currency' => 'CHF', 'structured_creditor_ref' => '210000000003139471430009018'])]))->toContain('reference_invalid');
});

test('the CBI flow needs a well formed CUC and an Italian debtor account', function (): void {
    expect(pfrCodes(PaymentFlow::CbiSepaV03, PFR_IT_IBAN, [pfrLine()], null))->toContain('cuc_required')
        ->and(pfrCodes(PaymentFlow::CbiSepaV03, PFR_IT_IBAN, [pfrLine()], 'ABC123'))->toContain('cuc_invalid')
        ->and(pfrCodes(PaymentFlow::CbiSepaV03, PFR_CH_IBAN, [pfrLine()], 'ABC12345'))->toContain('debtor_iban_country')
        ->and(pfrCodes(PaymentFlow::CbiSepaV03, PFR_IT_IBAN, [pfrLine()], 'ABC12345'))->toBe([]);
});

test('amounts are checked as exact decimals', function (string $amount, bool $valid): void {
    $codes = pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, [pfrLine(['amount' => $amount])]);

    expect(in_array('amount_out_of_range', $codes, true))->toBe(! $valid);
})->with([
    ['0.01', true],
    ['999999999.99', true],
    ['1000000000.00', false],
    ['0.00', false],
    ['10.123', false],
    ['abc', false],
]);

test('mixed currencies, long names and forbidden characters are reported', function (): void {
    $codes = pfrCodes(PaymentFlow::ChPain001V09, PFR_CH_IBAN, [
        pfrLine(['currency' => 'CHF', 'recipient_name' => str_repeat('A', 71)]),
        pfrLine(['currency' => 'EUR', 'remittance_reference' => 'Pay 😀']),
    ]);

    expect($codes)->toContain('mixed_currency')->toContain('name_too_long')->toContain('charset');
});

test('an empty payment list is refused', function (): void {
    expect(pfrCodes(PaymentFlow::SepaPain001V03, PFR_IT_IBAN, []))->toContain('no_lines');
});

test('the CBI decorator puts the CUC in the initiating party and keeps the file schema valid', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"><CstmrCdtTrfInitn><GrpHdr><MsgId>M1</MsgId><CreDtTm>2026-01-01T10:00:00</CreDtTm><NbOfTxs>1</NbOfTxs><CtrlSum>1.00</CtrlSum><InitgPty><Nm>ACME</Nm></InitgPty></GrpHdr></CstmrCdtTrfInitn></Document>
XML;
    $decorated = (new CbiInitiatorDecorator)->apply($xml, 'ABC12345');

    expect($decorated)->toContain('<Id>ABC12345</Id>')->toContain('<Issr>CBI</Issr>')
        ->and(strpos($decorated, '<Nm>ACME</Nm>'))->toBeLessThan(strpos($decorated, '<OrgId>'));
});

test('the flow enum maps to the batch format and schema version', function (): void {
    expect(PaymentFlow::ChPain001V09->version()->value)->toBe('09')
        ->and(PaymentFlow::ChPain001V09->batchFormat())->toBe('iso_pain_001_ch')
        ->and(PaymentFlow::SepaPain001V03->version()->value)->toBe('03')
        ->and(PaymentFlow::CbiSepaV03->batchFormat())->toBe('sepa_pain_001_it')
        ->and(PaymentFlow::CbiSepaV03->requiresCuc())->toBeTrue()
        ->and(PaymentFlow::SepaPain001V03->requiresCuc())->toBeFalse();
});
