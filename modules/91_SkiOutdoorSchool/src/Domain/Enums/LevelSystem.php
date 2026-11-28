<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Technical level taxonomy of the group courses.
 */
enum LevelSystem: string
{
    case SNOW_LEAGUE = 'SNOW_LEAGUE';
    case FISI_AMSI = 'FISI_AMSI';
    case CUSTOM = 'CUSTOM';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
