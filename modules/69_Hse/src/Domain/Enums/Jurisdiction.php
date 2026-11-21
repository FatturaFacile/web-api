<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

/**
 * The statutory regime a rule or a report belongs to: Italy (D.Lgs. 81/2008, INAIL) or Switzerland (UVG, OLL, SUVA).
 */
enum Jurisdiction: string
{
    case Italy = 'IT';
    case Switzerland = 'CH';

    /**
     * Days of prognosis above which a lost-time accident must be reported: more than 1 day (communication, Art. 18
     * D.Lgs. 81/08) in Italy, more than 3 days in Switzerland (UVG).
     */
    public function lostTimeThresholdDays(): int
    {
        return match ($this) {
            self::Italy => 1,
            self::Switzerland => 3,
        };
    }

    public function claimSchemaVersion(): string
    {
        return match ($this) {
            self::Italy => 'INAIL-DENUNCIA-2026.1',
            self::Switzerland => 'SUVA-SUNET-2026.1',
        };
    }

    public static function forCountry(?string $country): self
    {
        return strtoupper((string) $country) === 'CH' ? self::Switzerland : self::Italy;
    }
}
