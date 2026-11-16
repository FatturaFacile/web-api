<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetFuelEntry;

/**
 * A fuel or charging entry with its computed consumption and cost per km.
 *
 * @mixin FleetFuelEntry
 */
final class FuelEntryResource extends JsonResource
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
            'entry_date' => $this->entry_date->toDateString(),
            'energy_unit' => $this->energy_unit,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_cost' => $this->total_cost,
            'currency' => $this->currency,
            'odometer_km' => $this->odometer_km,
            'is_full_tank' => $this->is_full_tank,
            'station' => $this->station,
            'distance_km' => $this->distance_km,
            'consumption_per_100km' => $this->consumption_per_100km,
            'cost_per_km' => $this->cost_per_km,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
