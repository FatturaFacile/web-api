<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * How the instructor of a lesson was chosen.
 */
enum AssignmentMethod: string
{
    case PENDING = 'PENDING';
    case NOMINATED = 'NOMINATED';
    case FAIR_ROTATION = 'FAIR_ROTATION';
    case MANUAL = 'MANUAL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
