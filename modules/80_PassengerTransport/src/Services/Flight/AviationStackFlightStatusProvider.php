<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Flight;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Modules\PassengerTransport\Contracts\FlightStatusProviderInterface;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\ValueObjects\FlightSnapshot;
use Throwable;

/**
 * AviationStack real-time flights API. A failing call answers null, so the booking keeps its last known schedule.
 */
final class AviationStackFlightStatusProvider implements FlightStatusProviderInterface
{
    public function __construct(private readonly ?string $apiKey, private readonly int $timeoutSeconds = 8) {}

    public function lookup(string $flightNumber, ?string $carrierIata, string $arrivalAirportIata, DateTimeImmutable $scheduledArrival): ?FlightSnapshot
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->acceptJson()->get('https://api.aviationstack.com/v1/flights', [
                'access_key' => $this->apiKey,
                'flight_iata' => strtoupper(($carrierIata ?? '').preg_replace('/^[A-Za-z]{2}/', '', $flightNumber)),
                'arr_iata' => strtoupper($arrivalAirportIata),
            ]);
            $flight = $response->json('data.0');

            if (! $response->successful() || ! is_array($flight)) {
                return null;
            }

            $arrival = $flight['arrival'] ?? [];
            $estimated = $arrival['estimated'] ?? $arrival['actual'] ?? $arrival['scheduled'] ?? null;

            return new FlightSnapshot(
                match ($flight['flight_status'] ?? null) {
                    'landed' => FlightStatus::Landed,
                    'cancelled', 'diverted' => FlightStatus::Cancelled,
                    'active' => ((int) ($arrival['delay'] ?? 0)) > 15 ? FlightStatus::Delayed : FlightStatus::EnRoute,
                    default => ((int) ($arrival['delay'] ?? 0)) > 15 ? FlightStatus::Delayed : FlightStatus::Scheduled,
                },
                $estimated === null ? null : new DateTimeImmutable((string) $estimated),
                (int) ($arrival['delay'] ?? 0),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
