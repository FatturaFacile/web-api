<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Level of a spoken language of an instructor.
 */
enum Proficiency: string
{
    case NATIVE = 'NATIVE';
    case FLUENT = 'FLUENT';
    case BASIC = 'BASIC';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
