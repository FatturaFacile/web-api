<?php

declare(strict_types=1);

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Services\RegionalFormatter;
use Modules\Platform\Localization\Domain\ValueObjects\DateFormat;
use Modules\Platform\Localization\Domain\ValueObjects\NumberFormat;

dataset('swiss locales', [SupportedLocale::ItCh, SupportedLocale::DeCh, SupportedLocale::FrCh]);

it('groups Swiss numbers with the straight apostrophe', function (SupportedLocale $locale): void {
    $formatter = new RegionalFormatter;

    expect($formatter->number(1234.5, $locale))->toBe("1'234.50")
        ->and($formatter->number('1234567.891', $locale))->toBe("1'234'567.89")
        ->and($formatter->number(999.5, $locale, 0))->toBe('1\'000')
        ->and(str_contains($formatter->number(1234.5, $locale), "\u{2019}"))->toBeFalse()
        ->and(ord($formatter->number(1234.5, $locale)[1]))->toBe(0x27);
})->with('swiss locales');

it('formats Italian and English numbers', function (): void {
    $formatter = new RegionalFormatter;

    expect($formatter->number(1234.5, SupportedLocale::ItIt))->toBe('1.234,50')
        ->and($formatter->number(1234.5, SupportedLocale::En))->toBe('1,234.50')
        ->and($formatter->number(-1234.5, SupportedLocale::ItIt))->toBe('-1.234,50')
        ->and($formatter->number(0.005, SupportedLocale::En))->toBe('0.01');
});

it('formats money by locale', function (): void {
    $formatter = new RegionalFormatter;

    expect($formatter->money('1234.50', 'CHF', SupportedLocale::DeCh))->toBe("CHF 1'234.50")
        ->and($formatter->money(1234.5, 'EUR', SupportedLocale::FrCh))->toBe("EUR 1'234.50")
        ->and($formatter->money('1234.50', 'EUR', SupportedLocale::ItIt))->toBe('1.234,50 €')
        ->and($formatter->money('1234.50', 'CHF', SupportedLocale::En))->toBe('CHF 1,234.50')
        ->and($formatter->money('1234.50', 'EUR', SupportedLocale::En))->toBe('€1,234.50')
        ->and($formatter->money('-5', 'CHF', SupportedLocale::ItCh))->toBe('CHF -5.00');
});

it('formats dates by locale', function (SupportedLocale $locale, string $expected): void {
    expect((new RegionalFormatter)->date('2026-12-31', $locale))->toBe($expected)
        ->and((new RegionalFormatter)->date(new DateTimeImmutable('2026-12-31 23:30:00', new DateTimeZone('UTC')), $locale))->toBe($expected);
})->with([
    'it-CH' => [SupportedLocale::ItCh, '31.12.2026'],
    'de-CH' => [SupportedLocale::DeCh, '31.12.2026'],
    'fr-CH' => [SupportedLocale::FrCh, '31.12.2026'],
    'it-IT' => [SupportedLocale::ItIt, '31/12/2026'],
    'en' => [SupportedLocale::En, '2026-12-31'],
]);

it('starts the week on Monday', function (SupportedLocale $locale): void {
    expect((new RegionalFormatter)->weekStart($locale))->toBe(1);
})->with(SupportedLocale::cases());

it('applies the number and date overrides of a company', function (): void {
    $formatter = new RegionalFormatter;
    $format = NumberFormat::forLocaleWithOverride(SupportedLocale::DeCh, ['decimal_separator' => ',', 'thousands_separator' => '.']);

    expect($formatter->number(1234.5, SupportedLocale::DeCh, 2, $format))->toBe('1.234,50')
        ->and($formatter->money(1234.5, 'CHF', SupportedLocale::DeCh, $format))->toBe('CHF 1.234,50')
        ->and($formatter->number(1234.5, SupportedLocale::En, 2, new NumberFormat('.', '')))->toBe('1234.50')
        ->and($formatter->date('2026-12-31', SupportedLocale::En, DateFormat::forLocaleWithOverride(SupportedLocale::En, 'dd.MM.yyyy')))->toBe('31.12.2026');
});

it('rejects separators that cannot be told apart', function (): void {
    expect(fn () => new NumberFormat('.', '.'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NumberFormat(';', ','))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DateFormat('MM/dd/yy'))->toThrow(InvalidArgumentException::class);
});

it('exposes the language, region, currency and NG-ZORRO pack of a locale', function (): void {
    expect(SupportedLocale::DeCh->language())->toBe('de')
        ->and(SupportedLocale::DeCh->region())->toBe('CH')
        ->and(SupportedLocale::DeCh->defaultCurrency())->toBe('CHF')
        ->and(SupportedLocale::DeCh->ngZorroLocale())->toBe('de_DE')
        ->and(SupportedLocale::ItIt->ngZorroLocale())->toBe('it_IT')
        ->and(SupportedLocale::FrCh->ngZorroLocale())->toBe('fr_FR')
        ->and(SupportedLocale::En->region())->toBeNull()
        ->and(SupportedLocale::En->ngZorroLocale())->toBe('en_US')
        ->and(SupportedLocale::ItIt->defaultCurrency())->toBe('EUR');
});
