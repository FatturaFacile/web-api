<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Milk;

use Carbon\CarbonImmutable;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;

/**
 * A declaration or settlement month written YYYY-MM.
 */
final class Period
{
    public static function isValid(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /**
     * First day of the month.
     */
    public static function start(string $period): CarbonImmutable
    {
        if (! self::isValid($period)) {
            throw new DairyCheeseException('The period is not valid.', 'period_invalid');
        }

        return CarbonImmutable::parse($period.'-01')->startOfDay();
    }
}
