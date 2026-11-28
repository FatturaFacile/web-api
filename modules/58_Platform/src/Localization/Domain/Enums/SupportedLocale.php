<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Enums;

/**
 * The locales a tenant can work in. A locale is a language plus a regional variant that drives number, date and currency
 * conventions (Swiss apostrophe grouping versus Italian dot grouping versus ISO English).
 */
enum SupportedLocale: string
{
    case ItCh = 'it-CH';
    case DeCh = 'de-CH';
    case FrCh = 'fr-CH';
    case ItIt = 'it-IT';
    case En = 'en';

    /**
     * ISO 639-1 language: also the key of the backend dictionaries (lang/{it,de,fr,en}).
     */
    public function language(): string
    {
        return match ($this) {
            self::ItCh, self::ItIt => 'it',
            self::DeCh => 'de',
            self::FrCh => 'fr',
            self::En => 'en',
        };
    }

    /**
     * ISO 3166-1 region of the variant, null for the neutral English locale.
     */
    public function region(): ?string
    {
        return match ($this) {
            self::ItCh, self::DeCh, self::FrCh => 'CH',
            self::ItIt => 'IT',
            self::En => null,
        };
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::ItCh, self::DeCh, self::FrCh => 'CHF',
            self::ItIt, self::En => 'EUR',
        };
    }

    /**
     * Name of the NG-ZORRO locale pack (NG-ZORRO ships no Swiss German pack: de_DE is the closest).
     */
    public function ngZorroLocale(): string
    {
        return match ($this->language()) {
            'it' => 'it_IT',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            default => 'en_US',
        };
    }

    /**
     * ICU locale identifier (underscore form) for NumberFormatter and IntlDateFormatter.
     */
    public function icuLocale(): string
    {
        return str_replace('-', '_', $this->value);
    }

    public function isSwiss(): bool
    {
        return $this->region() === 'CH';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }

    /**
     * Case-insensitive lookup accepting both separators (de_ch, DE-ch).
     */
    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($value)));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $normalized) {
                return $case;
            }
        }

        return null;
    }
}
