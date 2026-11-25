<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Support;

use DateTimeImmutable;

/**
 * Moves the pickup of an airport transfer to follow the real arrival of the flight: estimated arrival plus the time the guests
 * need to reach the arrivals hall (immigration, luggage). Small drifts are ignored so the dispatcher is not flooded.
 */
final class FlightScheduleAdjuster
{
    public function __construct(private readonly int $bufferMinutes = 30, private readonly int $thresholdMinutes = 10) {}

    /**
     * @return DateTimeImmutable|null the new pickup time, or null when the current one still holds
     */
    public function adjustedPickup(DateTimeImmutable $currentPickup, DateTimeImmutable $estimatedArrival, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $target = $estimatedArrival->modify('+'.$this->bufferMinutes.' minutes');

        if (abs($target->getTimestamp() - $currentPickup->getTimestamp()) < $this->thresholdMinutes * 60) {
            return null;
        }

        // An early flight never moves the pickup into the past.
        return $now !== null && $target < $now ? $now : $target;
    }

    public function bufferMinutes(): int
    {
        return $this->bufferMinutes;
    }
}
