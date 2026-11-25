<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

final readonly class TrackFlightArrivalAndAdjustPickupScheduleCommand
{
    public function __construct(public string $bookingId) {}
}
