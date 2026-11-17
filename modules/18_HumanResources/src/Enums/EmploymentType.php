<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contractor = 'contractor';
    case Apprentice = 'apprentice';
    case Seasonal = 'seasonal';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
