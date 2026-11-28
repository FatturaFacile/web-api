<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Kind of PPE or course alert.
 */
enum AlertType: string
{
    case LifespanExpiring = 'LIFESPAN_EXPIRING';
    case LifespanExpired = 'LIFESPAN_EXPIRED';
    case InspectionDueSoon = 'INSPECTION_DUE_SOON';
    case InspectionOverdue = 'INSPECTION_OVERDUE';
    case DailyCheckMissing = 'DAILY_CHECK_MISSING';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
