<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Events;

/**
 * The flight of an airport transfer changed (delay, early arrival, cancellation) and the pickup moved or needs attention.
 * Dispatchers and the assigned driver are notified; listeners can add mail, push or chat.
 */
final readonly class FlightScheduleAdjusted
{
    /**
     * @param  list<string>  $conflictCodes
     */
    public function __construct(
        public string $tenantId,
        public string $bookingId,
        public string $bookingCode,
        public string $flightStatus,
        public int $delayMinutes,
        public ?string $previousPickup,
        public ?string $newPickup,
        public array $conflictCodes,
    ) {}
}
