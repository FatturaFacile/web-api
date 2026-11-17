<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

/**
 * Publication languages of job openings and careers content.
 */
enum RecruitingLocale: string
{
    case ItCh = 'it-CH';
    case DeCh = 'de-CH';
    case FrCh = 'fr-CH';
    case ItIt = 'it-IT';
    case En = 'en';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Two letter language used for built-in texts (templates, labels, offer letters).
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
     * Resolve the closest publication locale from an application locale such as `it`, `de-CH` or `fr`.
     */
    public static function fromAppLocale(?string $locale): self
    {
        $locale = trim((string) $locale);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $locale) === 0) {
                return $case;
            }
        }

        $language = strtolower(substr($locale, 0, 2));

        return match ($language) {
            'it' => self::ItCh,
            'de' => self::DeCh,
            'fr' => self::FrCh,
            default => self::En,
        };
    }
}
