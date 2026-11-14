<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityBookingRule;

/**
 * Booking limits of a kind of space.
 *
 * @mixin FacilityBookingRule
 */
final class BookingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_kind' => $this->space_kind,
            'max_bookings_per_user_per_day' => $this->max_bookings_per_user_per_day,
            'max_minutes_per_booking' => $this->max_minutes_per_booking,
            'max_advance_days' => $this->max_advance_days,
            'checkin_grace_minutes' => $this->checkin_grace_minutes,
            'requires_checkin' => $this->requires_checkin,
        ];
    }
}
