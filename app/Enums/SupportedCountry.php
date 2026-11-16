<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Countries a company (tenant) can be registered in. The list is the single source of the `tenants.country` column
 * (a portable string, not a database enum): adding a country is a code change, never a schema change.
 */
enum SupportedCountry: string
{
    case Italy = 'IT';
    case Switzerland = 'CH';
    case France = 'FR';
    case Spain = 'ES';
    case Germany = 'DE';
    case UnitedStates = 'US';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $country): string => $country->value, self::cases());
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::Switzerland => 'CHF',
            self::UnitedStates => 'USD',
            default => 'EUR',
        };
    }
}
