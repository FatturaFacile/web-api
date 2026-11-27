<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Lifecycle of a crew route schedule.
 */
enum RouteStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Postponed = 'postponed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
