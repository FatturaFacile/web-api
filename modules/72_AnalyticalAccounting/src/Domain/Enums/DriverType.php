<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum DriverType: string
{
    case AREA_SQM = 'AREA_SQM';
    case HEADCOUNT_FTE = 'HEADCOUNT_FTE';
    case MACHINE_HOURS = 'MACHINE_HOURS';
    case REVENUE_VOLUME = 'REVENUE_VOLUME';
    case FIXED_PERCENTAGE = 'FIXED_PERCENTAGE';
    case CUSTOM = 'CUSTOM';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
