<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Dry run of a booking: nothing is saved. booking_id excludes a booking that is being moved.
 */
final class BookingCheckRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'space_id' => ['required', 'uuid', $this->tenantOwns('fac_spaces')],
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'booking_id' => ['nullable', 'uuid', $this->tenantOwns('fac_bookings')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'attendees' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
