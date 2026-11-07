<?php

declare(strict_types=1);

namespace Modules\Intrastat\Enums;

/**
 * Frontespizio field 8 of the technical specifications (Allegato XII): M = mensile, T = trimestrale.
 */
enum Periodicity: string
{
    case Monthly = 'M';
    case Quarterly = 'T';

    public function lastPeriod(): int
    {
        return $this === self::Monthly ? 12 : 4;
    }
}
