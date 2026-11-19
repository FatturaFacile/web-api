<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use RoundingMode;

/**
 * Swiss commercial rounding of VAT amounts to the nearest 0.05 CHF (half away from zero).
 */
final class SwissRounding
{
    public static function to05(string $amount): string
    {
        $twentieths = bcround(Decimal::mul($amount, '20'), 0, RoundingMode::HalfAwayFromZero);

        return Decimal::round2(Decimal::div($twentieths, '20'));
    }
}
