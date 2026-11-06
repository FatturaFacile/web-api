<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Retention of the working time records: art. 73 cpv. 2 OLL 1 requires them to be kept for at least five years.
 */
final class DataRetentionPolicy
{
    public const MINIMUM_YEARS = 5;

    public const MAXIMUM_YEARS = 30;

    /**
     * The number of years actually applied (never less than the legal minimum).
     */
    public static function effectiveYears(int $configuredYears): int
    {
        return max(self::MINIMUM_YEARS, min(self::MAXIMUM_YEARS, $configuredYears));
    }

    /**
     * The first day that must still be kept: records of whole calendar years before it have expired.
     */
    public static function cutoff(DateTimeInterface $today, int $configuredYears): CarbonImmutable
    {
        $year = SwissWorkingTimeRules::toLocal($today)->year - self::effectiveYears($configuredYears);

        return SwissWorkingTimeRules::localDate(sprintf('%04d-01-01', $year));
    }
}
