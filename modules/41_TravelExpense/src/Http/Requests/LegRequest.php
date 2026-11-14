<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelLeg;

/**
 * A leg of the itinerary: flight, train, car or hotel stay with its booking and booked cost.
 */
final class LegRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
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
            'leg_type' => ['required', Rule::in(TravelLeg::Types)],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:999'],
            'from_location' => ['nullable', 'string', 'max:150'],
            'to_location' => ['required', 'string', 'max:150'],
            'departs_at' => ['nullable', 'date'],
            'arrives_at' => ['nullable', 'date', 'after_or_equal:departs_at'],
            'provider' => ['nullable', 'string', 'max:150'],
            'booking_reference' => ['nullable', 'string', 'max:60'],
            'booking_status' => ['nullable', Rule::in(TravelLeg::BookingStatuses)],
            'booked_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', Rule::in(['EUR', 'CHF'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
