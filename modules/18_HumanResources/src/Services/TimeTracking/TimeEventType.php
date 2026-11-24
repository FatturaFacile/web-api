<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * The time clock event types.
 */
enum TimeEventType: string
{
    case In = 'In';
    case Out = 'Out';
    case BreakStart = 'BreakStart';
    case BreakEnd = 'BreakEnd';

    /**
     * Whether the event starts a working period.
     */
    public function startsWork(): bool
    {
        return $this === self::In || $this === self::BreakEnd;
    }
}
