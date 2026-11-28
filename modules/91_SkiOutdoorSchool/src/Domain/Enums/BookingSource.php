<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Where a booking came from.
 */
enum BookingSource: string
{
    case BACK_OFFICE = 'BACK_OFFICE';
    case PUBLIC_PORTAL = 'PUBLIC_PORTAL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
