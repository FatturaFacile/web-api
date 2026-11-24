<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Swiss VAT reporting frequency (quarterly default for Effective, semi-annual default for Saldosteuersatz).
 */
enum SwissVatFrequency: string
{
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Monthly = 'monthly';

    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Semiannual => 2,
        };
    }
}
