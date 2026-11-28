<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

/**
 * Date display pattern (ICU pattern) and first day of the week. Derived from the locale (Swiss 31.12.2026, Italian
 * 31/12/2026, English ISO 2026-12-31) and overridable per tenant among the whitelisted patterns.
 */
final readonly class DateFormat
{
    public const array PATTERNS = ['dd.MM.yyyy', 'dd/MM/yyyy', 'yyyy-MM-dd'];

    /**
     * @param  int  $weekStart  ISO day number of the first weekday: 1 = Monday, 7 = Sunday
     */
    public function __construct(public string $pattern, public int $weekStart = 1)
    {
        if (! in_array($pattern, self::PATTERNS, true)) {
            throw new InvalidArgumentException("Unsupported date pattern [{$pattern}].");
        }

        if ($weekStart < 1 || $weekStart > 7) {
            throw new InvalidArgumentException("Invalid week start [{$weekStart}].");
        }
    }

    public static function forLocale(SupportedLocale $locale): self
    {
        return match ($locale) {
            SupportedLocale::ItCh, SupportedLocale::DeCh, SupportedLocale::FrCh => new self('dd.MM.yyyy', 1),
            SupportedLocale::ItIt => new self('dd/MM/yyyy', 1),
            SupportedLocale::En => new self('yyyy-MM-dd', 1),
        };
    }

    public static function forLocaleWithOverride(SupportedLocale $locale, ?string $pattern): self
    {
        $base = self::forLocale($locale);

        return $pattern === null || $pattern === '' ? $base : new self($pattern, $base->weekStart);
    }
}
