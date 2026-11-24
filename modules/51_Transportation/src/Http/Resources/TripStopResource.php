<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTripStop;

/**
 * A pickup or delivery stop of a trip, in the order the vehicle visits it.
 *
 * @mixin TmsTripStop
 */
final class TripStopResource extends JsonResource
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
            'stop_type' => $this->stop_type,
            'transport_order_id' => $this->transport_order_id,
            'location' => $this->location,
            'city' => $this->city,
            'planned_arrival_at' => $this->planned_arrival_at?->toIso8601String(),
            'actual_arrival_at' => $this->actual_arrival_at?->toIso8601String(),
            'actual_departure_at' => $this->actual_departure_at?->toIso8601String(),
            'status' => $this->status,
            'order_number' => $this->whenLoaded('transportOrder', fn (): ?string => $this->transportOrder?->number),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
