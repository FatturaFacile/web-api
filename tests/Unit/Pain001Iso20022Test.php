<?php

declare(strict_types=1);

use App\Exceptions\Pain001Exception;
use App\Services\Banking\Iso20022\Pain001Message;
use App\Services\Banking\Iso20022\Pain001Payment;
use App\Services\Banking\Iso20022\Pain001SchemaValidator;
use App\Services\Banking\Iso20022\Pain001Version;
use App\Services\Banking\Iso20022\Pain001XmlBuilder;
use Carbon\CarbonImmutable;

const P001_QR_IBAN = 'CH4431999123000889012';
const P001_IBAN = 'CH9300762011623852957';

function pain001Message(array $payments, ?string $debtorBic = 'POFICHBEXXX'): Pain001Message
{
    return new Pain001Message(
        messageId: 'MSG-20261117-0001',
        createdAt: CarbonImmutable::parse('2026-11-17 10:30:00'),
        initiatorName: 'FatturaFacile Switzerland AG',
        paymentInfoId: 'PAY-20261117-ABC123',
        executionDate: CarbonImmutable::parse('2026-11-18'),
        debtorName: 'FatturaFacile Switzerland AG',
        debtorIban: P001_IBAN,
        debtorBic: $debtorBic,
        payments: $payments,
    );
}

function qrrPayment(string $reference = '210000000003139471430009017'): Pain001Payment
{
    return new Pain001Payment('Robert Schneider AG', P001_QR_IBAN, 1949.75, 'CHF', 'E2E-QRR-0001', 'INSTR-1', null, $reference);
}

function scorPayment(): Pain001Payment
{
    return new Pain001Payment('Café Müller & Söhne AG', P001_IBAN, 250.00, 'CHF', 'E2E-SCOR-0001', null, 'POFICHBEXXX', 'RF18 5390 0754 7034');
}

function unstructuredPayment(): Pain001Payment
{
    return new Pain001Payment('Rossi Impianti S.r.l.', 'IT60X0542811101000000123456', 99.90, 'EUR', 'E2E-NON-0001', null, null, null, 'Invoice 2026/17');
}

it('generates files that validate against the bundled ISO 20022 schema', function (Pain001Version $version): void {
    $xml = app(Pain001XmlBuilder::class)->build(pain001Message([qrrPayment(), scorPayment(), unstructuredPayment()]), $version);

    expect(app(Pain001SchemaValidator::class)->errors($xml, $version))->toBe([]);
    app(Pain001SchemaValidator::class)->validate($xml, $version);
})->with([Pain001Version::V03, Pain001Version::V09]);

it('uses the namespace, date and BIC elements of each version', function (): void {
    $builder = app(Pain001XmlBuilder::class);
    $v03 = $builder->build(pain001Message([scorPayment()]), Pain001Version::V03);
    $v09 = $builder->build(pain001Message([scorPayment()]), Pain001Version::V09);

    expect($v03)->toContain('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03')
        ->toContain('<ReqdExctnDt>2026-11-18</ReqdExctnDt>')
        ->toContain('<BIC>POFICHBEXXX</BIC>')
        ->and($v09)->toContain('urn:iso:std:iso:20022:tech:xsd:pain.001.001.09')
        ->toContain('<Dt>2026-11-18</Dt>')
        ->toContain('<BICFI>POFICHBEXXX</BICFI>');
});

it('types the structured reference as QRR for QR-IBANs and SCOR for creditor references', function (): void {
    $xml = app(Pain001XmlBuilder::class)->build(pain001Message([qrrPayment(), scorPayment(), unstructuredPayment()]), Pain001Version::V09);

    expect($xml)->toContain('<Prtry>QRR</Prtry>')
        ->toContain('<Ref>210000000003139471430009017</Ref>')
        ->toContain('<Cd>SCOR</Cd>')
        ->toContain('<Ref>RF18539007547034</Ref>')
        ->toContain('<Ustrd>Invoice 2026/17</Ustrd>')
        ->toContain('<CtrlSum>2299.65</CtrlSum>')
        ->toContain('<NbOfTxs>3</NbOfTxs>')
        ->toContain('Café Müller &amp; Söhne AG');
});

it('refuses payments that break the QR-IBAN and reference pairing', function (Pain001Payment $payment, string $message): void {
    expect(fn () => app(Pain001XmlBuilder::class)->build(pain001Message([$payment]), Pain001Version::V09))
        ->toThrow(Pain001Exception::class, $message);
})->with([
    'QR-IBAN without reference' => [new Pain001Payment('A AG', P001_QR_IBAN, 10.0, 'CHF', 'E2E-1'), 'needs a QR reference'],
    'QR-IBAN with SCOR' => [new Pain001Payment('A AG', P001_QR_IBAN, 10.0, 'CHF', 'E2E-2', null, null, 'RF18539007547034'), 'which needs a QR reference'],
    'QRR with standard IBAN' => [new Pain001Payment('A AG', P001_IBAN, 10.0, 'CHF', 'E2E-3', null, null, '210000000003139471430009017'), 'not a QR-IBAN'],
    'bad QRR check digit' => [new Pain001Payment('A AG', P001_QR_IBAN, 10.0, 'CHF', 'E2E-4', null, null, '210000000003139471430009018'), 'not a valid QR reference'],
    'bad SCOR check digits' => [new Pain001Payment('A AG', P001_IBAN, 10.0, 'CHF', 'E2E-5', null, null, 'RF19539007547034'), 'not a valid ISO 11649'],
]);

it('rejects a file without payments', function (): void {
    expect(fn () => app(Pain001XmlBuilder::class)->build(pain001Message([]), Pain001Version::V09))->toThrow(Pain001Exception::class);
});

it('reports schema violations of a tampered file', function (): void {
    $xml = app(Pain001XmlBuilder::class)->build(pain001Message([scorPayment()]), Pain001Version::V09);
    $broken = str_replace('<PmtMtd>TRF</PmtMtd>', '<PmtMtd>XXX</PmtMtd>', $xml);
    $wrongCurrency = str_replace('Ccy="CHF"', 'Ccy="chf"', $xml);

    $validator = app(Pain001SchemaValidator::class);

    expect($validator->errors($broken, Pain001Version::V09))->not->toBe([])
        ->and($validator->errors($wrongCurrency, Pain001Version::V09))->not->toBe([])
        ->and(fn () => $validator->validate($broken, Pain001Version::V09))->toThrow(Pain001Exception::class)
        ->and($validator->errors('<not-xml', Pain001Version::V09))->toBe(['The document is not well-formed XML.'])
        ->and($validator->errors($xml, Pain001Version::V03))->not->toBe([]);
});
