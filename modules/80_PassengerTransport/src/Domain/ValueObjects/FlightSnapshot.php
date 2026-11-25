<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use DateTimeImmutable;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;

/**
 * What a flight status provider reports about a flight.
 */
final readonly class FlightSnapshot
{
    public function __construct(
        public FlightStatus $status,
        public ?DateTimeImmutable $estimatedArrivalAt,
        public int $delayMinutes,
    ) {}
}
