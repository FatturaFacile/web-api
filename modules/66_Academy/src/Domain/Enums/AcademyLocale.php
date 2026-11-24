<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Enums;

/**
 * Languages and regions of the Academy content. The fallback chain of a locale ends in English; the two Italian
 * variants fall back to each other first (it-CH to it-IT).
 */
enum AcademyLocale: string
{
    case ItCh = 'it-CH';
    case DeCh = 'de-CH';
    case FrCh = 'fr-CH';
    case En = 'en';
    case ItIt = 'it-IT';

    /**
     * Accepts the full tag or the bare language of the application locales ("it", "de", "fr").
     */
    public static function fromInput(?string $value): self
    {
        $value = trim((string) $value);

        return self::tryFrom($value) ?? match (strtolower(substr($value, 0, 2))) {
            'it' => self::ItCh,
            'de' => self::DeCh,
            'fr' => self::FrCh,
            default => self::En,
        };
    }

    /**
     * @return list<self> The locale itself, then each fallback, ending in English.
     */
    public function fallbackChain(): array
    {
        return match ($this) {
            self::ItCh => [self::ItCh, self::ItIt, self::En],
            self::ItIt => [self::ItIt, self::ItCh, self::En],
            self::DeCh => [self::DeCh, self::En],
            self::FrCh => [self::FrCh, self::En],
            self::En => [self::En],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
