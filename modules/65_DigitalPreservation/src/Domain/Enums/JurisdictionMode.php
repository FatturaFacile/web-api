<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * Legal framework a preservation batch complies with.
 */
enum JurisdictionMode: string
{
    case It = 'IT';
    case Ch = 'CH';
    case Dual = 'DUAL';

    /**
     * Whether the Italian AgID rules apply.
     */
    public function appliesItaly(): bool
    {
        return $this !== self::Ch;
    }

    /**
     * Whether the Swiss GeBüV/Olico rules apply.
     */
    public function appliesSwitzerland(): bool
    {
        return $this !== self::It;
    }
}
