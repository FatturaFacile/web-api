<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\FlightTrackingService;

final class TrackFlightArrivalAndAdjustPickupScheduleHandler
{
    public function __construct(private readonly FlightTrackingService $tracking) {}

    /**
     * @return array{status: string|null, delay_minutes: int, adjusted: bool, previous_pickup: string|null, new_pickup: string|null, conflicts: list<array<string, mixed>>}
     */
    public function handle(TrackFlightArrivalAndAdjustPickupScheduleCommand $command): array
    {
        return $this->tracking->track(PassengerBooking::query()->findOrFail($command->bookingId));
    }
}
