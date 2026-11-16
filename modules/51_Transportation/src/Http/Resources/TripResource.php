<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTrip;

/**
 * A planned run of a vehicle or carrier that consolidates transport orders into ordered pickup and delivery stops.
 *
 * @mixin TmsTrip
 */
final class TripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'carrier_id' => $this->carrier_id,
            'vehicle_id' => $this->vehicle_id,
            'planned_start_at' => $this->planned_start_at?->toIso8601String(),
            'planned_end_at' => $this->planned_end_at?->toIso8601String(),
            'actual_start_at' => $this->actual_start_at?->toIso8601String(),
            'actual_end_at' => $this->actual_end_at?->toIso8601String(),
            'capacity_weight_kg' => $this->capacity_weight_kg,
            'capacity_volume_cbm' => $this->capacity_volume_cbm,
            'planned_distance_km' => $this->planned_distance_km,
            'actual_distance_km' => $this->actual_distance_km,
            'actual_cost' => $this->actual_cost,
            'currency' => $this->currency,
            'total_weight_kg' => $this->total_weight_kg,
            'total_volume_cbm' => $this->total_volume_cbm,
            'utilization_percent' => $this->utilization_percent,
            'notes' => $this->notes,
            'carrier' => $this->whenLoaded('carrier', fn (): ?array => $this->carrier !== null ? ['id' => $this->carrier->id, 'code' => $this->carrier->code, 'name' => $this->carrier->name] : null),
            'stops' => TripStopResource::collection($this->whenLoaded('stops')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
