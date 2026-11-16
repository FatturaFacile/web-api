<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Booking limits of a kind of space. A null limit means no limit.
 */
final class BookingRuleRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_bookings_per_user_per_day' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_minutes_per_booking' => ['nullable', 'integer', 'min:15', 'max:10080'],
            'max_advance_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'checkin_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'requires_checkin' => ['required', 'boolean'],
        ];
    }
}
