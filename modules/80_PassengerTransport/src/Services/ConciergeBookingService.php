<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Carbon\CarbonImmutable;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\HotelConciergeContract;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * Booking by the concierge of a hotel or an event agency at the tariff of its contract: the fare comes from the rate card of the route,
 * the concierge commission from the rate of the contract, and the booking is confirmed at once.
 */
final class ConciergeBookingService
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * @param  array<string, mixed>  $data  route_code, pickup_datetime, passengers and guest data of the request
     */
    public function book(HotelConciergeContract $contract, array $data, string $tenantId, ?string $userId): PassengerBooking
    {
        $date = CarbonImmutable::parse((string) $data['pickup_datetime']);

        if (! $contract->is_active || ($contract->valid_from !== null && $date->lt($contract->valid_from)) || ($contract->valid_to !== null && $date->gt($contract->valid_to->endOfDay()))) {
            throw new PassengerTransportException('contract_not_valid_on_date', 422, ['date' => $date->toDateString()]);
        }

        $route = collect($contract->rate_card ?? [])->firstWhere('route_code', $data['route_code'] ?? null);

        if (! is_array($route)) {
            throw new PassengerTransportException('contract_route_unknown', 422, ['route' => (string) ($data['route_code'] ?? '')]);
        }

        $booking = $this->bookings->create($tenantId, [
            'service_type' => $data['service_type'] ?? ServiceType::AirportTransfer->value,
            'ordering_customer_id' => $contract->contact_id,
            'hotel_contract_id' => $contract->id,
            'b2b_concierge_user_id' => $userId,
            'lead_passenger_name' => $data['lead_passenger_name'] ?? null,
            'lead_passenger_phone' => $data['lead_passenger_phone'] ?? null,
            'vip_welcome_sign_text' => $data['vip_welcome_sign_text'] ?? ($data['lead_passenger_name'] ?? null),
            'passengers_count' => $data['passengers_count'] ?? 1,
            'luggage_count' => $data['luggage_count'] ?? 0,
            'child_seats_spec' => $data['child_seats_spec'] ?? null,
            'accessibility_notes' => $data['accessibility_notes'] ?? null,
            'flight_or_train_number' => $data['flight_or_train_number'] ?? null,
            'flight_carrier_iata' => $data['flight_carrier_iata'] ?? null,
            'arrival_airport_iata' => $data['arrival_airport_iata'] ?? null,
            'scheduled_flight_arrival_at' => $data['scheduled_flight_arrival_at'] ?? null,
            'pickup_datetime' => $date->toDateTimeString(),
            'pickup_address' => $data['pickup_address'] ?? $route['origin_label'],
            'pickup_country_code' => $route['origin_country'] ?? 'IT',
            'pickup_lat' => $route['origin_lat'] ?? null,
            'pickup_lng' => $route['origin_lng'] ?? null,
            'dropoff_address' => $data['dropoff_address'] ?? $route['destination_label'],
            'dropoff_country_code' => $route['destination_country'] ?? 'IT',
            'dropoff_lat' => $route['destination_lat'] ?? null,
            'dropoff_lng' => $route['destination_lng'] ?? null,
            'border_crossing_point' => $route['border_crossing'] ?? 'none',
            'total_distance_km' => $route['total_km'] ?? 0,
            'it_territory_km' => $route['it_km'] ?? 0,
            'ch_territory_km' => $route['ch_km'] ?? 0,
            'agreed_price_net' => $route['price'],
            'currency' => $contract->currency,
            'hotel_concierge_commission_rate' => $contract->commission_rate,
        ], $userId);

        return $this->bookings->transition($booking, 'confirm', $userId);
    }
}
