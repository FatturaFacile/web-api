<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Services;

use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\ValueObjects\DateFormat;
use Modules\Platform\Localization\Domain\ValueObjects\NumberFormat;
use NumberFormatter;

/**
 * Display formatting of numbers, money and dates by locale, built on PHP intl with strict overrides:
 *  - it-CH / de-CH / fr-CH: 1'234.50 (straight apostrophe U+0027), CHF 1'234.50, 31.12.2026
 *  - it-IT: 1.234,50, 1.234,50 €, 31/12/2026
 *  - en: 1,234.50, CHF 1,234.50 / €1,234.50, 2026-12-31
 * The ICU data of the locale is not trusted for these conventions (it changes between ICU releases and groups 4-digit
 * numbers inconsistently), so the separators come from {@see NumberFormat} and the patterns from {@see DateFormat}.
 */
final class RegionalFormatter
{
    private const array ENGLISH_SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'JPY' => '¥'];

    public function number(string|int|float $value, SupportedLocale $locale, int $decimals = 2, ?NumberFormat $format = null): string
    {
        $format ??= NumberFormat::forLocale($locale);
        $formatter = new NumberFormatter('en', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, $format->thousandsSeparator === '' ? 0 : 1);
        $formatter->setSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, $format->decimalSeparator);
        $formatter->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, $format->thousandsSeparator === '' ? ',' : $format->thousandsSeparator);

        $formatted = $formatter->format((float) $value);

        return $formatted === false ? (string) $value : $formatted;
    }

    public function money(string|int|float $amount, string $currency, SupportedLocale $locale, ?NumberFormat $format = null): string
    {
        $currency = strtoupper($currency);
        $negative = (float) $amount < 0;
        $number = $this->number(abs((float) $amount), $locale, 2, $format);
        $sign = $negative ? '-' : '';

        return match ($locale) {
            SupportedLocale::ItIt => $sign.$number.' '.$this->symbol($currency, $locale),
            SupportedLocale::En => isset(self::ENGLISH_SYMBOLS[$currency])
                ? $sign.self::ENGLISH_SYMBOLS[$currency].$number
                : $currency.' '.$sign.$number,
            default => $currency.' '.$sign.$number,
        };
    }

    public function date(DateTimeInterface|string $date, SupportedLocale $locale, ?DateFormat $format = null): string
    {
        $format ??= DateFormat::forLocale($locale);

        if (is_string($date)) {
            $date = new \DateTimeImmutable($date, new DateTimeZone('UTC'));
        }

        $formatter = new IntlDateFormatter('en', IntlDateFormatter::NONE, IntlDateFormatter::NONE, $date->getTimezone(), IntlDateFormatter::GREGORIAN, $format->pattern);
        $formatted = $formatter->format($date);

        return $formatted === false ? $date->format('Y-m-d') : $formatted;
    }

    /**
     * ISO day number of the first day of the week (1 = Monday).
     */
    public function weekStart(SupportedLocale $locale, ?DateFormat $format = null): int
    {
        return ($format ?? DateFormat::forLocale($locale))->weekStart;
    }

    private function symbol(string $currency, SupportedLocale $locale): string
    {
        return match ($currency) {
            'EUR' => '€',
            default => $currency,
        };
    }
}
