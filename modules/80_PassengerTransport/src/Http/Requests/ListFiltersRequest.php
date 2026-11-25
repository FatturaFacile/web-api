<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\ServiceType;

/**
 * Filters shared by the list endpoints (every key is optional).
 */
final class ListFiltersRequest extends FormRequest
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
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(BookingStatus::values())],
            'service_type' => ['sometimes', 'nullable', Rule::in(ServiceType::values())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
            'driver_employee_id' => ['sometimes', 'nullable', 'uuid'],
            'carrier_id' => ['sometimes', 'nullable', 'uuid'],
            'period' => ['sometimes', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
