<?php

declare(strict_types=1);

use Modules\Platform\Localization\Domain\Fiscal\FiscalLocalizationFactory;
use Modules\Platform\Localization\Domain\Fiscal\FiscalNoticeContext;
use Modules\Platform\Localization\Domain\Fiscal\ItalianFiscalLocalization;
use Modules\Platform\Localization\Domain\Fiscal\SwissFiscalLocalization;

it('rounds a Swiss document total to 0.05 CHF', function (string $amount, string $expected): void {
    expect((new SwissFiscalLocalization)->roundDocumentTotal($amount))->toBe($expected);
})->with([
    ['10.02', '10.00'],
    ['10.03', '10.05'],
    ['10.025', '10.05'],
    ['10.07', '10.05'],
    ['10.08', '10.10'],
    ['1234.56', '1234.55'],
    ['0.02', '0.00'],
    ['-10.03', '-10.05'],
    ['100', '100.00'],
]);

it('rounds an Italian document total to two decimals, half up', function (string $amount, string $expected): void {
    expect((new ItalianFiscalLocalization)->roundDocumentTotal($amount))->toBe($expected);
})->with([
    ['10.005', '10.01'],
    ['10.004', '10.00'],
    ['10.015', '10.02'],
    ['7', '7.00'],
]);

it('declares the rounding increment of each country', function (): void {
    expect((new SwissFiscalLocalization)->roundingIncrement())->toBe('0.05')
        ->and((new ItalianFiscalLocalization)->roundingIncrement())->toBe('0.01')
        ->and((new SwissFiscalLocalization)->country())->toBe('CH')
        ->and((new ItalianFiscalLocalization)->country())->toBe('IT');
});

it('prints the QR-bill amount without a thousands separator', function (): void {
    $swiss = new SwissFiscalLocalization;

    expect($swiss->qrBillAmount('1234.5'))->toBe('1234.50')
        ->and($swiss->qrBillAmount('1234567.891'))->toBe('1234567.89')
        ->and($swiss->qrBillAmount('0'))->toBe('0.00');
});

it('lists the mandatory Swiss texts: UID suffix always, flat rate, QR-bill headings and nLPD on demand', function (): void {
    $swiss = new SwissFiscalLocalization;

    expect($swiss->statutoryNoticeKeys(new FiscalNoticeContext('de-CH')))->toBe(['statutory.ch.vat_suffix', 'statutory.ch.uid_label']);

    $full = $swiss->statutoryNoticeKeys(new FiscalNoticeContext('de-CH', flatRateVat: true, qrBill: true, privacyNotice: true));

    expect($full)->toContain('statutory.ch.flat_rate_notice', 'statutory.ch.nlpd_notice')
        ->and($full)->toContain(...SwissFiscalLocalization::QR_BILL_HEADING_KEYS)
        ->and(SwissFiscalLocalization::QR_BILL_HEADING_KEYS)->toHaveCount(9);
});

it('lists the mandatory Italian texts of the document', function (): void {
    $italian = new ItalianFiscalLocalization;
    $keys = $italian->statutoryNoticeKeys(new FiscalNoticeContext('it-IT', privacyNotice: true, naturaCode: 'N3.2', exemptArt10: true, splitPayment: true, reverseCharge: true, virtualStamp: true));

    expect($keys)->toBe([
        'statutory.it.natura.n3',
        'statutory.it.art10_dpr633',
        'statutory.it.split_payment',
        'statutory.it.reverse_charge',
        'statutory.it.virtual_stamp',
        'statutory.it.gdpr_art12',
    ]);
});

it('asks for the Italian translation print of an invoice issued in another language (Art. 21 DPR 633/72)', function (): void {
    $italian = new ItalianFiscalLocalization;

    expect($italian->requiresItalianTranslation('de-CH'))->toBeTrue()
        ->and($italian->requiresItalianTranslation('en'))->toBeTrue()
        ->and($italian->requiresItalianTranslation('it-IT'))->toBeFalse()
        ->and($italian->requiresItalianTranslation('it-CH'))->toBeFalse()
        ->and($italian->statutoryNoticeKeys(new FiscalNoticeContext('en')))->toBe(['statutory.it.translation_notice'])
        ->and($italian->statutoryNoticeKeys(new FiscalNoticeContext('it-IT')))->toBe([]);
});

it('maps every natura sub-code to the text of its parent code', function (string $code, string $key): void {
    expect((new ItalianFiscalLocalization)->naturaKey($code))->toBe($key);
})->with([
    ['N1', 'statutory.it.natura.n1'],
    ['N2.2', 'statutory.it.natura.n2'],
    ['N6.9', 'statutory.it.natura.n6'],
    ['N7', 'statutory.it.natura.n7'],
]);

it('transliterates text to Latin-1 for FatturaPA files', function (): void {
    $italian = new ItalianFiscalLocalization;

    expect($italian->transliterateToLatin1('Prezzo: 5 € – “ok”'))->toBe('Prezzo: 5 EUR - "ok"')
        ->and($italian->transliterateToLatin1('Perché è già così'))->toBe('Perché è già così')
        ->and($italian->transliterateToLatin1('Łódź'))->toBe('Lódz')
        ->and(mb_check_encoding(mb_convert_encoding($italian->transliterateToLatin1('Žižek – 100 €'), 'ISO-8859-1', 'UTF-8'), 'ISO-8859-1'))->toBeTrue();
});

it('picks the strategy of a country, none for the others', function (): void {
    $factory = new FiscalLocalizationFactory;

    expect($factory->forCountry('ch'))->toBeInstanceOf(SwissFiscalLocalization::class)
        ->and($factory->forCountry('IT'))->toBeInstanceOf(ItalianFiscalLocalization::class)
        ->and($factory->forCountry('DE'))->toBeNull()
        ->and($factory->forCountry(null))->toBeNull();
});
