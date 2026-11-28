<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * What a commission line of an instructor comes from.
 */
enum CommissionSource: string
{
    case LESSON = 'LESSON';
    case COURSE = 'COURSE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
