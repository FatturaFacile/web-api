<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Flight;

use DateTimeImmutable;
use Modules\PassengerTransport\Contracts\FlightStatusProviderInterface;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\ValueObjects\FlightSnapshot;

/**
 * Deterministic provider for development and tests: the outcome follows a checksum of the flight number, so the same flight always
 * answers the same. The last digit of the number decides: 0 cancelled, 1-3 early (-15 min), 4-5 delayed (+45 min), 6 heavily delayed
 * (+120 min), the rest on time. Flights seen after their scheduled arrival report landed.
 */
final class SimulatedFlightStatusProvider implements FlightStatusProviderInterface
{
    public function __construct(private readonly ?DateTimeImmutable $now = null) {}

    public function lookup(string $flightNumber, ?string $carrierIata, string $arrivalAirportIata, DateTimeImmutable $scheduledArrival): ?FlightSnapshot
    {
        $digits = preg_replace('/\D/', '', $flightNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        $outcome = (int) substr($digits, -1);
        [$status, $delay] = match (true) {
            $outcome === 0 => [FlightStatus::Cancelled, 0],
            $outcome <= 3 => [FlightStatus::EnRoute, -15],
            $outcome <= 5 => [FlightStatus::Delayed, 45],
            $outcome === 6 => [FlightStatus::Delayed, 120],
            default => [FlightStatus::Scheduled, 0],
        };

        if ($status === FlightStatus::Cancelled) {
            return new FlightSnapshot($status, null, 0);
        }

        $estimated = $scheduledArrival->modify(sprintf('%+d minutes', $delay));
        $now = $this->now ?? new DateTimeImmutable;

        return new FlightSnapshot($estimated <= $now ? FlightStatus::Landed : $status, $estimated, $delay);
    }
}
