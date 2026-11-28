<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\LocalizationFrontendTranslations;
use Database\Seeders\Translations\LocalizationStatutoryTranslations;
use Modules\Platform\Localization\Domain\Fiscal\FiscalNoticeContext;
use Modules\Platform\Localization\Domain\Fiscal\ItalianFiscalLocalization;
use Modules\Platform\Localization\Domain\Fiscal\SwissFiscalLocalization;

it('defines every statutory text in all four languages', function (): void {
    foreach (LocalizationStatutoryTranslations::definitions() as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }
    }

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(LocalizationStatutoryTranslations::all($locale)))->toBe(LocalizationStatutoryTranslations::keys());
    }
});

it('seeds every key a fiscal strategy can ask for', function (): void {
    $known = LocalizationStatutoryTranslations::keys();
    $swiss = (new SwissFiscalLocalization)->statutoryNoticeKeys(new FiscalNoticeContext('de-CH', flatRateVat: true, qrBill: true, privacyNotice: true));
    $italian = (new ItalianFiscalLocalization)->statutoryNoticeKeys(new FiscalNoticeContext('en', privacyNotice: true, naturaCode: 'N1', exemptArt10: true, splitPayment: true, reverseCharge: true, virtualStamp: true));

    foreach ([...$swiss, ...$italian] as $key) {
        expect($known)->toContain($key);
    }

    foreach (['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7'] as $natura) {
        expect($known)->toContain((new ItalianFiscalLocalization)->naturaKey($natura));
    }
});

it('gives the QR-bill headings of SIX v2.3 in the language of the bill', function (): void {
    $de = LocalizationStatutoryTranslations::all('de');
    $fr = LocalizationStatutoryTranslations::all('fr');
    $it = LocalizationStatutoryTranslations::all('it');

    expect($de['statutory.ch.qr_bill.payment_part'])->toBe('Zahlteil')
        ->and($de['statutory.ch.qr_bill.receipt'])->toBe('Empfangsschein')
        ->and($fr['statutory.ch.qr_bill.payment_part'])->toBe('Section paiement')
        ->and($it['statutory.ch.qr_bill.payment_part'])->toBe('Sezione di pagamento')
        ->and($de['statutory.ch.vat_suffix'])->toBe('MWST')
        ->and($fr['statutory.ch.vat_suffix'])->toBe('TVA')
        ->and($it['statutory.ch.vat_suffix'])->toBe('IVA');
});

it('keeps the localization UI keys complete in all four languages and clear of the other dictionaries', function (): void {
    expect(LocalizationFrontendTranslations::definitions())->not->toBeEmpty();

    foreach (LocalizationFrontendTranslations::definitions() as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }
    }

    foreach (['common.locale.it_CH', 'common.locale.de_CH', 'common.locale.fr_CH', 'common.locale.it_IT', 'common.locale.en'] as $key) {
        expect(LocalizationFrontendTranslations::definitions())->toHaveKey($key);
    }

    expect(array_intersect_key(LocalizationFrontendTranslations::definitions(), LocalizationStatutoryTranslations::definitions()))->toBe([]);
});

it('serves the new keys through the frontend dictionary of every language', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        expect($dictionary)->toHaveKey('statutory.ch.vat_suffix')->toHaveKey('common.locale.de_CH');
    }
});
