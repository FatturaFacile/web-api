<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Services\ScheduledServiceService;

/**
 * A scheduled service with its overdue detection (date reached or kilometres reached).
 *
 * @mixin FleetScheduledService
 */
final class ScheduledServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle;

        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $vehicle !== null ? ['id' => $vehicle->id, 'license_plate' => $vehicle->license_plate, 'odometer_km' => $vehicle->odometer_km] : null,
            'name' => $this->name,
            'service_type' => $this->service_type,
            'interval_days' => $this->interval_days,
            'interval_km' => $this->interval_km,
            'last_service_on' => $this->last_service_on?->toDateString(),
            'last_service_km' => $this->last_service_km,
            'next_due_on' => $this->next_due_on?->toDateString(),
            'next_due_km' => $this->next_due_km,
            'is_active' => $this->is_active,
            ...($vehicle !== null ? app(ScheduledServiceService::class)->dueStatus($this->resource, $vehicle) : ['days_to_due' => null, 'km_to_due' => null, 'is_overdue' => false]),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
