<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

/**
 * Creates or updates a booking, and prices it without saving (quote).
 */
final class SaveBookingRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'service_type' => [$required, Rule::in(ServiceType::values())],
            'ordering_customer_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'hotel_contract_id' => ['nullable', 'uuid', $this->tenantExists('ptr_hotel_concierge_contracts')],
            'school_contract_id' => ['nullable', 'uuid', $this->tenantExists('ptr_school_bus_contracts')],
            'lead_passenger_name' => ['nullable', 'string', 'max:160'],
            'lead_passenger_phone' => ['nullable', 'string', 'max:40'],
            'vip_welcome_sign_text' => ['nullable', 'string', 'max:200'],
            'passengers_count' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'luggage_count' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'child_seats_spec' => ['nullable', 'array'],
            'child_seats_spec.infant' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'child_seats_spec.child' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'child_seats_spec.booster' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'accessibility_notes' => ['nullable', 'string', 'max:2000'],
            'flight_or_train_number' => ['nullable', 'string', 'max:20'],
            'flight_carrier_iata' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2,3}$/'],
            'arrival_airport_iata' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'scheduled_flight_arrival_at' => ['nullable', 'date'],
            'pickup_datetime' => [$required, 'date'],
            'estimated_dropoff_datetime' => ['nullable', 'date', 'after:pickup_datetime'],
            'pickup_address' => [$required, 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_lng'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_lat'],
            'pickup_country_code' => ['sometimes', 'string', 'size:2'],
            'dropoff_address' => [$required, 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:dropoff_lng'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:dropoff_lat'],
            'dropoff_country_code' => ['sometimes', 'string', 'size:2'],
            'waypoints' => ['nullable', 'array', 'max:20'],
            'waypoints.*.address' => ['required_with:waypoints', 'string', 'max:255'],
            'waypoints.*.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'waypoints.*.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'border_crossing_point' => ['sometimes', Rule::in(BorderCrossing::values())],
            'total_distance_km' => ['sometimes', 'numeric', 'min:0'],
            'it_territory_km' => ['sometimes', 'numeric', 'min:0'],
            'ch_territory_km' => ['sometimes', 'numeric', 'min:0'],
            'other_eu_territory_km' => ['sometimes', 'numeric', 'min:0'],
            'agreed_price_net' => [$required, 'numeric', 'min:0'],
            'currency' => ['sometimes', Rule::in(['CHF', 'EUR'])],
            'vat_exemption_art23' => ['sometimes', 'boolean'],
            'municipal_urban_service' => ['sometimes', 'boolean'],
            'hotel_concierge_commission_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
