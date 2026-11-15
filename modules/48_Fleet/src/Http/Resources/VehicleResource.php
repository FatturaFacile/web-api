<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetVehicle;

/**
 * A company vehicle with its current driver when the assignment is loaded.
 *
 * @mixin FleetVehicle
 */
final class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_plate' => $this->license_plate,
            'vin' => $this->vin,
            'make' => $this->make,
            'model' => $this->model,
            'model_year' => $this->model_year,
            'vehicle_type' => $this->vehicle_type,
            'fuel_type' => $this->fuel_type,
            'status' => $this->status,
            'initial_odometer_km' => $this->initial_odometer_km,
            'odometer_km' => $this->odometer_km,
            'ownership' => $this->ownership,
            'acquired_on' => $this->acquired_on?->toDateString(),
            'contract_ends_on' => $this->contract_ends_on?->toDateString(),
            'purchase_value' => $this->purchase_value,
            'currency' => $this->currency,
            'asset_id' => $this->asset_id,
            'notes' => $this->notes,
            'current_assignment' => $this->whenLoaded('openAssignment', fn (): ?AssignmentResource => $this->openAssignment !== null ? new AssignmentResource($this->openAssignment) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
