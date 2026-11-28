<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Category of a personal protective equipment item of the course.
 */
enum PpeCategory: string
{
    case Harness = 'HARNESS';
    case LanyardLonge = 'LANYARD_LONGE';
    case Carabiner = 'CARABINER';
    case Pulley = 'PULLEY';
    case EnergyAbsorber = 'ENERGY_ABSORBER';
    case Helmet = 'HELMET';
    case CourseCable = 'COURSE_CABLE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
