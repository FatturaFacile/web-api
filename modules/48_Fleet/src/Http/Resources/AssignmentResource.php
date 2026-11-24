<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetVehicleAssignment;

/**
 * A driver assignment.
 *
 * @mixin FleetVehicleAssignment
 */
final class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn (): array => ['id' => $this->vehicle->id, 'license_plate' => $this->vehicle->license_plate]),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn (): ?array => $this->employee !== null ? ['id' => $this->employee->id, 'full_name' => $this->employee->full_name] : null),
            'driver_label' => $this->driver_label,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'start_odometer_km' => $this->start_odometer_km,
            'end_odometer_km' => $this->end_odometer_km,
            'is_open' => $this->ends_on === null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
