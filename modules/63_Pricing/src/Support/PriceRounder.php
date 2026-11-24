<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use InvalidArgumentException;

/**
 * Commercial rounding of a net unit price. NONE keeps the 4 decimals, HALF_UP_001 rounds half up to 0.01 and SWISS_005
 * rounds half up to the nearest 0.05 (the Swiss five-centime rounding of CHF amounts).
 */
final class PriceRounder
{
    public const string None = 'NONE';

    public const string Swiss005 = 'SWISS_005';

    public const string HalfUp001 = 'HALF_UP_001';

    /** @var list<string> */
    public const array Modes = [self::None, self::Swiss005, self::HalfUp001];

    public static function round(string $price, string $mode): string
    {
        return match ($mode) {
            self::None => bcadd($price, '0', 4),
            self::HalfUp001 => bcdiv(bcdiv(bcadd(bcmul($price, '100', 8), '0.5', 8), '1', 0), '100', 4),
            self::Swiss005 => bcdiv(bcdiv(bcadd(bcmul($price, '20', 8), '0.5', 8), '1', 0), '20', 4),
            default => throw new InvalidArgumentException("Unknown rounding mode {$mode}."),
        };
    }
}
