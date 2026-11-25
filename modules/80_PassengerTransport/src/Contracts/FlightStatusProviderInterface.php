<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Contracts;

use DateTimeImmutable;
use Modules\PassengerTransport\Domain\ValueObjects\FlightSnapshot;

/**
 * Status of a flight at its destination airport. Returns null when the provider does not know the flight.
 */
interface FlightStatusProviderInterface
{
    public function lookup(string $flightNumber, ?string $carrierIata, string $arrivalAirportIata, DateTimeImmutable $scheduledArrival): ?FlightSnapshot;
}
