<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * State of a timed-slot ticket.
 */
enum TicketStatus: string
{
    case Booked = 'BOOKED';
    case WaiverSigned = 'WAIVER_SIGNED';
    case Equipped = 'EQUIPPED';
    case OnCourse = 'ON_COURSE';
    case Returned = 'RETURNED';
    case NoShow = 'NO_SHOW';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
