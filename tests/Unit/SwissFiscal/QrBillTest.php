<?php

declare(strict_types=1);

use Modules\SwissFiscal\Domain\Enums\QrReferenceType;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\Services\PaymentReference;
use Modules\SwissFiscal\Domain\Services\QrBillPayloadBuilder;
use Modules\SwissFiscal\Domain\Services\SwissIban;
use Modules\SwissFiscal\Domain\ValueObjects\Currency;
use Modules\SwissFiscal\Domain\ValueObjects\Money;
use Modules\SwissFiscal\Domain\ValueObjects\QrBill;
use Modules\SwissFiscal\Domain\ValueObjects\QrBillAddress;
use Modules\SwissFiscal\Infrastructure\QrBill\QrBillRenderer;

const QR_IBAN = 'CH44 3199 9123 0008 8901 2';
const PLAIN_IBAN = 'CH93 0076 2011 6238 5295 7';
const QR_REFERENCE = '210000000003139471430009017';

function qrCreditor(): QrBillAddress
{
    return new QrBillAddress('Robert Schneider AG', '2501', 'Biel', 'CH', 'Rue du Lac', '1268');
}

it('validates the check digit of the SIX sample QR reference', function (): void {
    expect(PaymentReference::isValidQrReference(QR_REFERENCE))->toBeTrue()
        ->and(PaymentReference::isValidQrReference('210000000003139471430009018'))->toBeFalse()
        ->and(PaymentReference::qrReference('21000000000313947143000901'))->toBe(QR_REFERENCE)
        ->and(PaymentReference::formatQrReference(QR_REFERENCE))->toBe('21 00000 00003 13947 14300 09017');
});

it('builds and validates ISO 11649 creditor references', function (): void {
    expect(PaymentReference::creditorReference('539007547034'))->toBe('RF18539007547034')
        ->and(PaymentReference::isValidCreditorReference('RF18 5390 0754 7034'))->toBeTrue()
        ->and(PaymentReference::isValidCreditorReference('RF19539007547034'))->toBeFalse();
});

it('recognises QR-IBANs and validates IBAN checksums', function (): void {
    expect(SwissIban::isValid(QR_IBAN))->toBeTrue()
        ->and(SwissIban::isQrIban(QR_IBAN))->toBeTrue()
        ->and(SwissIban::isValid(PLAIN_IBAN))->toBeTrue()
        ->and(SwissIban::isQrIban(PLAIN_IBAN))->toBeFalse()
        ->and(SwissIban::isValid('CH44 3199 9123 0008 8901 3'))->toBeFalse()
        ->and(SwissIban::isValid('DE89370400440532013000'))->toBeFalse();
});

it('serialises the SPC payload field by field', function (): void {
    $bill = new QrBill(QR_IBAN, qrCreditor(), Currency::CHF, Money::of('1949.75', Currency::CHF), QrReferenceType::QrReference, QR_REFERENCE,
        new QrBillAddress('Pia-Maria Rutschmann-Schnyder', '9400', 'Rorschach', 'CH', 'Grosse Marktgasse', '28'), 'Order of 15.06.2026');

    $lines = explode("\n", (new QrBillPayloadBuilder)->build($bill));

    expect($lines)->toHaveCount(31)
        ->and(array_slice($lines, 0, 4))->toBe(['SPC', '0200', '1', 'CH4431999123000889012'])
        ->and($lines[4])->toBe('S')
        ->and($lines[5])->toBe('Robert Schneider AG')
        ->and(array_slice($lines, 11, 7))->toBe(array_fill(0, 7, ''))
        ->and($lines[18])->toBe('1949.75')
        ->and($lines[19])->toBe('CHF')
        ->and($lines[21])->toBe('Pia-Maria Rutschmann-Schnyder')
        ->and(array_slice($lines, 27, 4))->toBe(['QRR', QR_REFERENCE, 'Order of 15.06.2026', 'EPD']);
});

it('leaves the amount and debtor empty for an open bill', function (): void {
    $bill = new QrBill(PLAIN_IBAN, qrCreditor(), Currency::EUR, null, QrReferenceType::None);
    $lines = explode("\n", (new QrBillPayloadBuilder)->build($bill));

    expect($lines[18])->toBe('')->and($lines[19])->toBe('EUR')->and($lines[27])->toBe('NON');
});

it('rejects combinations a bank would refuse', function (callable $make): void {
    $make();
})->throws(SwissFiscalException::class)->with([
    'QRR with a regular IBAN' => [fn () => new QrBill(PLAIN_IBAN, qrCreditor(), Currency::CHF, null, QrReferenceType::QrReference, QR_REFERENCE)],
    'SCOR with a QR-IBAN' => [fn () => new QrBill(QR_IBAN, qrCreditor(), Currency::CHF, null, QrReferenceType::CreditorReference, 'RF18539007547034')],
    'NON with a QR-IBAN' => [fn () => new QrBill(QR_IBAN, qrCreditor(), Currency::CHF, null, QrReferenceType::None)],
    'bad QR reference checksum' => [fn () => new QrBill(QR_IBAN, qrCreditor(), Currency::CHF, null, QrReferenceType::QrReference, '210000000003139471430009018')],
    'USD' => [fn () => new QrBill(PLAIN_IBAN, qrCreditor(), Currency::USD, null, QrReferenceType::None)],
    'zero amount' => [fn () => new QrBill(PLAIN_IBAN, qrCreditor(), Currency::CHF, Money::zero(Currency::CHF), QrReferenceType::None)],
    'amount in another currency' => [fn () => new QrBill(PLAIN_IBAN, qrCreditor(), Currency::CHF, Money::of('5', Currency::EUR), QrReferenceType::None)],
    'foreign IBAN' => [fn () => new QrBill('DE89370400440532013000', qrCreditor(), Currency::CHF, null, QrReferenceType::None)],
    'message too long' => [fn () => new QrBill(PLAIN_IBAN, qrCreditor(), Currency::CHF, null, QrReferenceType::None, null, null, str_repeat('x', 141))],
]);

it('renders a QR SVG and a localized payment slip', function (): void {
    $bill = new QrBill(QR_IBAN, qrCreditor(), Currency::CHF, Money::of('10.00', Currency::CHF), QrReferenceType::QrReference, QR_REFERENCE);
    $payload = (new QrBillPayloadBuilder)->build($bill);
    $renderer = new QrBillRenderer;

    expect($renderer->qrSvg($payload))->toContain('<svg')->toContain('fill="#fff"')
        ->and($renderer->slipHtml($bill, $payload, 'de'))->toContain('Zahlteil')->toContain('21 00000 00003 13947 14300 09017')
        ->and($renderer->slipHtml($bill, $payload, 'it'))->toContain('Sezione pagamento')
        ->and(substr($renderer->slipPdf($bill, $payload, 'fr'), 0, 4))->toBe('%PDF');
});
