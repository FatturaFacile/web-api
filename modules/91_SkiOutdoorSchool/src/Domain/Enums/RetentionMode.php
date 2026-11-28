<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * How the school secretariat retention is computed on the gross fee of an instructor.
 */
enum RetentionMode: string
{
    case PERCENT = 'PERCENT';
    case FIXED_HOURLY = 'FIXED_HOURLY';
    case NONE = 'NONE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
