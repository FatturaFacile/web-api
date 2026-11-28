<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

/**
 * Decimal and thousands separators of displayed numbers. Derived from the locale (Swiss: 1'234.50 with the straight
 * apostrophe U+0027, Italian: 1.234,50, English: 1,234.50) and overridable per tenant.
 */
final readonly class NumberFormat
{
    public const array DECIMAL_SEPARATORS = ['.', ','];

    public const array THOUSANDS_SEPARATORS = ["'", '.', ',', ' ', ''];

    public function __construct(public string $decimalSeparator, public string $thousandsSeparator)
    {
        if (! in_array($decimalSeparator, self::DECIMAL_SEPARATORS, true)) {
            throw new InvalidArgumentException("Unsupported decimal separator [{$decimalSeparator}].");
        }

        if (! in_array($thousandsSeparator, self::THOUSANDS_SEPARATORS, true) || $thousandsSeparator === $decimalSeparator) {
            throw new InvalidArgumentException("Unsupported thousands separator [{$thousandsSeparator}].");
        }
    }

    public static function forLocale(SupportedLocale $locale): self
    {
        return match ($locale) {
            SupportedLocale::ItCh, SupportedLocale::DeCh, SupportedLocale::FrCh => new self('.', "'"),
            SupportedLocale::ItIt => new self(',', '.'),
            SupportedLocale::En => new self('.', ','),
        };
    }

    /**
     * @param  array{decimal_separator?: string, thousands_separator?: string}|null  $override
     */
    public static function forLocaleWithOverride(SupportedLocale $locale, ?array $override): self
    {
        $base = self::forLocale($locale);

        if ($override === null || $override === []) {
            return $base;
        }

        return new self($override['decimal_separator'] ?? $base->decimalSeparator, $override['thousands_separator'] ?? $base->thousandsSeparator);
    }

    /**
     * @return array{decimal_separator: string, thousands_separator: string}
     */
    public function toArray(): array
    {
        return ['decimal_separator' => $this->decimalSeparator, 'thousands_separator' => $this->thousandsSeparator];
    }
}
