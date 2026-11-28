<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * State of a school trip booking.
 */
enum SchoolBookingStatus: string
{
    case Draft = 'DRAFT';
    case Confirmed = 'CONFIRMED';
    case Completed = 'COMPLETED';
    case Invoiced = 'INVOICED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
