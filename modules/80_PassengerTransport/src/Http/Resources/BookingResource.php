<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * A booking with its route, flight, fare, exchange and assignment.
 *
 * @mixin PassengerBooking
 */
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'service_type' => $this->service_type->value,
            'status' => $this->status->value,
            'ordering_customer_id' => $this->ordering_customer_id,
            'ordering_customer_name' => $this->whenLoaded('orderingCustomer', fn (): ?string => $this->orderingCustomer?->company_name),
            'b2b_concierge_user_id' => $this->b2b_concierge_user_id,
            'hotel_contract_id' => $this->hotel_contract_id,
            'school_contract_id' => $this->school_contract_id,
            'lead_passenger_name' => $this->lead_passenger_name,
            'lead_passenger_phone' => $this->lead_passenger_phone,
            'vip_welcome_sign_text' => $this->vip_welcome_sign_text,
            'has_welcome_logo' => $this->vip_welcome_logo_path !== null,
            'passengers_count' => $this->passengers_count,
            'luggage_count' => $this->luggage_count,
            'child_seats_spec' => $this->child_seats_spec,
            'accessibility_notes' => $this->accessibility_notes,
            'flight_or_train_number' => $this->flight_or_train_number,
            'flight_carrier_iata' => $this->flight_carrier_iata,
            'arrival_airport_iata' => $this->arrival_airport_iata,
            'scheduled_flight_arrival_at' => $this->scheduled_flight_arrival_at?->toIso8601String(),
            'estimated_flight_arrival_at' => $this->estimated_flight_arrival_at?->toIso8601String(),
            'flight_status' => $this->flight_status?->value,
            'flight_delay_minutes' => $this->flight_delay_minutes,
            'flight_checked_at' => $this->flight_checked_at?->toIso8601String(),
            'pickup_datetime' => $this->pickup_datetime->toIso8601String(),
            'estimated_dropoff_datetime' => $this->estimated_dropoff_datetime?->toIso8601String(),
            'pickup_address' => $this->pickup_address,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            'pickup_country_code' => $this->pickup_country_code,
            'dropoff_address' => $this->dropoff_address,
            'dropoff_lat' => $this->dropoff_lat,
            'dropoff_lng' => $this->dropoff_lng,
            'dropoff_country_code' => $this->dropoff_country_code,
            'waypoints' => $this->waypoints,
            'total_distance_km' => $this->total_distance_km,
            'it_territory_km' => $this->it_territory_km,
            'ch_territory_km' => $this->ch_territory_km,
            'other_eu_territory_km' => $this->other_eu_territory_km,
            'border_crossing_point' => $this->border_crossing_point->value,
            'cross_border' => $this->isCrossBorder(),
            'agreed_price_net' => $this->agreed_price_net,
            'currency' => $this->currency,
            'vat_exemption_art23' => $this->vat_exemption_art23,
            'municipal_urban_service' => $this->municipal_urban_service,
            'hotel_concierge_commission_rate' => $this->hotel_concierge_commission_rate,
            'hotel_concierge_commission_amount' => $this->hotel_concierge_commission_amount,
            'exchange_direction' => $this->exchange_direction->value,
            'subcontracted_carrier_id' => $this->subcontracted_carrier_id,
            'subcontracted_carrier_name' => $this->whenLoaded('carrier', fn (): ?string => $this->carrier?->company_name),
            'subcontractor_payout_amount' => $this->subcontractor_payout_amount,
            'assignor_commission_amount' => $this->assignor_commission_amount,
            'assigned_vehicle_id' => $this->assigned_vehicle_id,
            'assigned_vehicle_plate' => $this->whenLoaded('vehicle', fn (): ?string => $this->vehicle?->license_plate),
            'assigned_driver_id' => $this->assigned_driver_id,
            'assigned_driver_name' => $this->whenLoaded('driver', fn (): ?string => $this->driver?->full_name),
            'invoice_id' => $this->invoice_id,
            'editable' => $this->isEditable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
