<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Kind of inspection of a PPE item.
 */
enum InspectionType: string
{
    case DailyVisual = 'DAILY_VISUAL';
    case AnnualEn365 = 'ANNUAL_EN365';
    case Incident = 'INCIDENT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
