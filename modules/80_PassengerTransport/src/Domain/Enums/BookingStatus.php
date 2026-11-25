<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum BookingStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Assigned = 'assigned';
    case EnRouteToPickup = 'en_route_to_pickup';
    case AtPickupWaiting = 'at_pickup_waiting';
    case PassengerOnBoard = 'passenger_on_board';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Invoiced = 'invoiced';
}
