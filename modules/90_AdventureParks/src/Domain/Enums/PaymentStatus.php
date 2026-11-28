<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Payment state of a ticket.
 */
enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Refunded = 'REFUNDED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
