<?php

declare(strict_types=1);

use App\Exceptions\QrBillParseException;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\SwissQrBill\SwissQrBillParserService;
use Fpdf\Fpdf;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\FpdfOutput\FpdfOutput;
use Sprain\SwissQrBill\QrBill;

const ENGINE_QR_IBAN = 'CH4431999123000889012';
const ENGINE_IBAN = 'CH9300762011623852957';

function engineBill(string $iban, string $type, ?string $reference, ?string $info = null): QrBill
{
    $bill = QrBill::create();
    $bill->setCreditorInformation(CreditorInformation::create($iban));
    $bill->setCreditor(StructuredAddress::createWithStreet('Robert Schneider AG', 'Rue du Lac', '1268/2/22', '2501', 'Biel', 'CH'));
    $bill->setPaymentAmountInformation(PaymentAmountInformation::create('CHF', 1949.75));
    $bill->setUltimateDebtor(StructuredAddress::createWithStreet('Pia-Maria Rutschmann-Schnyder', 'Grosse Marktgasse', '28', '9400', 'Rorschach', 'CH'));
    $bill->setPaymentReference(PaymentReference::create($type, $reference));
    $bill->setAdditionalInformation(AdditionalInformation::create('Invoice 2026-0001', $info));

    return $bill;
}

it('validates SIX modulo 10 recursive QR references', function (string $reference, bool $valid): void {
    expect((new QrBillGeneratorService)->validateScorReference($reference))->toBe($valid);
})->with([
    'SIX example' => ['210000000003139471430009017', true],
    'spaced SIX example' => ['21 00000 00003 13947 14300 09017', true],
    'all zeros' => ['000000000000000000000000000', true],
    'wrong check digit' => ['210000000003139471430009018', false],
    'too short' => ['2100000000031394714300090', false],
    'letters' => ['21000000000313947143000901X', false],
]);

it('calculates the SIX check digit of a 26 digit base', function (): void {
    expect((new QrBillGeneratorService)->calculateMod10RecursiveCheckDigit('21000000000313947143000901'))->toBe(7);
});

it('validates ISO 11649 creditor references with modulo 97-10', function (string $reference, bool $valid): void {
    expect((new QrBillGeneratorService)->validateIso11649($reference))->toBe($valid);
})->with([
    'ISO example' => ['RF18 5390 0754 7034', true],
    'compact' => ['RF18539007547034', true],
    'wrong check digits' => ['RF19539007547034', false],
    'missing prefix' => ['18539007547034', false],
    'too long' => ['RF18539007547034539007547034', false],
]);

it('generates ISO 11649 references that validate and rejects over-long bases', function (): void {
    $service = new QrBillGeneratorService;

    expect($service->generateIso11649('539007547034'))->toBe('RF18539007547034')
        ->and($service->validateIso11649($service->generateIso11649('INV2026')))->toBeTrue()
        ->and(fn () => $service->generateIso11649(str_repeat('1', 22)))->toThrow(InvalidArgumentException::class);
});

it('derives a 27 digit QR reference from the tenant prefix and the invoice sequence', function (): void {
    $service = new QrBillGeneratorService;
    $reference = $service->deriveQrReference('123456', '2026-0001');

    expect($reference)->toHaveLength(27)
        ->and($reference)->toStartWith('123456')
        ->and($service->validateScorReference($reference))->toBeTrue()
        ->and($service->deriveQrReference(null, '1'))->toStartWith('00000000000000000000000001');
});

it('pairs accounts with reference types', function (string $iban, ?string $reference, bool $valid, string $type, array $errors): void {
    $result = (new QrBillGeneratorService)->checkReference($iban, $reference);

    expect($result['valid'])->toBe($valid)
        ->and($result['type'])->toBe($type)
        ->and($result['errors'])->toBe($errors);
})->with([
    'QR-IBAN with QRR' => [ENGINE_QR_IBAN, '210000000003139471430009017', true, 'QRR', []],
    'QR-IBAN with bad QRR' => [ENGINE_QR_IBAN, '210000000003139471430009018', false, 'QRR', ['invalid_reference']],
    'QR-IBAN without reference' => [ENGINE_QR_IBAN, null, false, 'NON', ['reference_mismatch']],
    'QR-IBAN with SCOR' => [ENGINE_QR_IBAN, 'RF18539007547034', false, 'SCOR', ['reference_mismatch']],
    'IBAN with SCOR' => [ENGINE_IBAN, 'RF18 5390 0754 7034', true, 'SCOR', []],
    'IBAN with bad SCOR' => [ENGINE_IBAN, 'RF19539007547034', false, 'SCOR', ['invalid_reference']],
    'IBAN with QRR' => [ENGINE_IBAN, '210000000003139471430009017', false, 'QRR', ['reference_mismatch']],
    'IBAN without reference' => [ENGINE_IBAN, null, true, 'NON', []],
    'foreign IBAN' => ['DE89370400440532013000', null, false, 'NON', ['invalid_creditor_iban']],
]);

it('rejects IBANs whose institution id is outside the QR-IID range', function (): void {
    $service = new QrBillGeneratorService;

    expect($service->isValidQrIban(ENGINE_QR_IBAN))->toBeTrue()
        ->and($service->isValidQrIban(ENGINE_IBAN))->toBeFalse()
        ->and($service->isValidQrIban('CH4432000123000889012'))->toBeFalse();
});

it('round-trips an SPC 0200 payload with a QR reference through the parser', function (): void {
    $payload = engineBill(ENGINE_QR_IBAN, PaymentReference::TYPE_QR, '210000000003139471430009017', '//S1/10/2026-0001/11/260301/30/CHE-123.456.789/40/0:30')->getQrCode()->getText();
    $parsed = app(SwissQrBillParserService::class)->parsePayload($payload);

    expect($parsed->valid)->toBeTrue()
        ->and($parsed->errors)->toBe([])
        ->and($parsed->version)->toBe('0200')
        ->and($parsed->iban)->toBe(ENGINE_QR_IBAN)
        ->and($parsed->isQrIban)->toBeTrue()
        ->and($parsed->referenceType)->toBe('QRR')
        ->and($parsed->reference)->toBe('210000000003139471430009017')
        ->and($parsed->amount)->toBe(1949.75)
        ->and($parsed->currency)->toBe('CHF')
        ->and($parsed->creditor)->toMatchArray(['address_type' => 'S', 'name' => 'Robert Schneider AG', 'street' => 'Rue du Lac', 'house_number' => '1268/2/22', 'postal_code' => '2501', 'city' => 'Biel', 'country' => 'CH'])
        ->and($parsed->debtor['name'])->toBe('Pia-Maria Rutschmann-Schnyder')
        ->and($parsed->message)->toBe('Invoice 2026-0001')
        ->and($parsed->billingInformation)->toBe(['invoice_number' => '2026-0001', 'invoice_date' => '2026-03-01', 'vat_number' => 'CHE-123.456.789', 'payment_terms' => '0:30'])
        ->and($parsed->toArray()['draft'])->toMatchArray(['supplier_name' => 'Robert Schneider AG', 'invoice_number' => '2026-0001', 'total_amount' => 1949.75]);
});

it('round-trips an SPC 0200 payload with a SCOR reference through the parser', function (): void {
    $payload = engineBill(ENGINE_IBAN, PaymentReference::TYPE_SCOR, 'RF18539007547034')->getQrCode()->getText();
    $parsed = app(SwissQrBillParserService::class)->parsePayload($payload);

    expect($parsed->valid)->toBeTrue()
        ->and($parsed->isQrIban)->toBeFalse()
        ->and($parsed->referenceType)->toBe('SCOR')
        ->and($parsed->reference)->toBe('RF18539007547034');
});

it('flags payloads that break the QR-IBAN and reference pairing', function (): void {
    $payload = engineBill(ENGINE_QR_IBAN, PaymentReference::TYPE_QR, '210000000003139471430009017')->getQrCode()->getText();
    $tampered = str_replace('210000000003139471430009017', '210000000003139471430009018', $payload);
    $parsed = app(SwissQrBillParserService::class)->parsePayload($tampered);

    expect($parsed->valid)->toBeFalse()
        ->and($parsed->errors)->toContain('invalid_reference');
});

it('rejects payloads that are not Swiss QR-bills', function (): void {
    expect(fn () => app(SwissQrBillParserService::class)->parsePayload("BEGIN:VCARD\nEND:VCARD"))->toThrow(QrBillParseException::class);
});

it('decodes the QR code of a raster image', function (): void {
    $bill = engineBill(ENGINE_QR_IBAN, PaymentReference::TYPE_QR, '210000000003139471430009017');
    $parsed = app(SwissQrBillParserService::class)->parseFile($bill->getQrCode()->getAsString('png'));

    expect($parsed->valid)->toBeTrue()
        ->and($parsed->creditor['name'])->toBe('Robert Schneider AG');
});

it('decodes the QR code embedded in a payment slip PDF', function (): void {
    $bill = engineBill(ENGINE_QR_IBAN, PaymentReference::TYPE_QR, '210000000003139471430009017');
    $fpdf = new Fpdf('P', 'mm', 'A4');
    $fpdf->AddPage();
    (new FpdfOutput($bill, 'it', $fpdf))->getPaymentPart();

    $parsed = app(SwissQrBillParserService::class)->parseFile($fpdf->Output('S'));

    expect($parsed->valid)->toBeTrue()
        ->and($parsed->reference)->toBe('210000000003139471430009017')
        ->and($parsed->amount)->toBe(1949.75);
});

it('reports a typed error for files without a QR code', function (): void {
    $blank = imagecreatetruecolor(200, 200);
    ob_start();
    imagepng($blank);
    $png = (string) ob_get_clean();

    expect(fn () => app(SwissQrBillParserService::class)->parseFile($png))->toThrow(QrBillParseException::class)
        ->and(fn () => app(SwissQrBillParserService::class)->parseFile(''))->toThrow(QrBillParseException::class);
});
