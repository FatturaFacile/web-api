<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Teaching discipline of an instructor, lesson or course.
 */
enum Discipline: string
{
    case ALPINE_SKI = 'alpine_ski';
    case SNOWBOARD = 'snowboard';
    case TELEMARK = 'telemark';
    case CROSS_COUNTRY = 'cross_country';
    case FREERIDE = 'freeride';
    case ALPINE_GUIDE = 'alpine_guide';
    case OUTDOOR_RAFTING = 'outdoor_rafting';
    case OUTDOOR_CANYONING = 'outdoor_canyoning';
    case OUTDOOR_PARAGLIDING = 'outdoor_paragliding';
    case OUTDOOR_MTB = 'outdoor_mtb';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isSnowSport(): bool
    {
        return in_array($this, [self::ALPINE_SKI, self::SNOWBOARD, self::TELEMARK, self::CROSS_COUNTRY, self::FREERIDE], true);
    }
}
