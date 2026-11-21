<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

/**
 * The labour-law regime of an establishment. It selects the compliance strategy, the public holidays and the time zone.
 */
enum Jurisdiction: string
{
    case Switzerland = 'CH';
    case Italy = 'IT';

    public static function fromCountry(?string $country): self
    {
        return self::tryFrom(strtoupper((string) $country)) ?? self::Switzerland;
    }

    public function timezone(): string
    {
        return match ($this) {
            self::Switzerland => 'Europe/Zurich',
            self::Italy => 'Europe/Rome',
        };
    }
}
