<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelMileageClaim;

/**
 * A mileage claim of a trip.
 *
 * @mixin TravelMileageClaim
 */
final class MileageClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'travel_date' => $this->travel_date?->toDateString(),
            'from_location' => $this->from_location,
            'to_location' => $this->to_location,
            'vehicle_type' => $this->vehicle_type,
            'round_trip' => $this->round_trip,
            'distance_km' => $this->distance_km,
            'rate_per_km' => $this->rate_per_km,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'notes' => $this->notes,
        ];
    }
}
