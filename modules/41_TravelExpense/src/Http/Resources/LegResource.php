<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelLeg;

/**
 * A leg of the itinerary.
 *
 * @mixin TravelLeg
 */
final class LegResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'sequence' => $this->sequence,
            'leg_type' => $this->leg_type,
            'from_location' => $this->from_location,
            'to_location' => $this->to_location,
            'departs_at' => $this->departs_at?->toIso8601String(),
            'arrives_at' => $this->arrives_at?->toIso8601String(),
            'provider' => $this->provider,
            'booking_reference' => $this->booking_reference,
            'booking_status' => $this->booking_status,
            'booked_cost' => $this->booked_cost,
            'currency' => $this->currency,
            'notes' => $this->notes,
        ];
    }
}
