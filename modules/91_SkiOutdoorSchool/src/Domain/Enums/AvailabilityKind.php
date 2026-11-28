<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * A window in which an instructor is explicitly available, or blocked.
 */
enum AvailabilityKind: string
{
    case AVAILABLE = 'AVAILABLE';
    case BLACKOUT = 'BLACKOUT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
