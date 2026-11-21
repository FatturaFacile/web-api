<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Bucket size of an OEE time series.
 */
enum OeeCalculationGranularity: string
{
    case RealTime = 'real_time';
    case Shift = 'shift';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case CustomRange = 'custom_range';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
