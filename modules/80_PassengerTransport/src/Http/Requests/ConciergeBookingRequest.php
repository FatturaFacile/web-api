<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Domain\Enums\ServiceType;

final class ConciergeBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'route_code' => ['required', 'string', 'max:40'],
            'service_type' => ['sometimes', Rule::in(ServiceType::values())],
            'pickup_datetime' => ['required', 'date'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'dropoff_address' => ['nullable', 'string', 'max:255'],
            'lead_passenger_name' => ['required', 'string', 'max:160'],
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
        ];
    }
}
