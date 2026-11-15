<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetServiceRecord;

/**
 * A service or maintenance record.
 *
 * @mixin FleetServiceRecord
 */
final class ServiceRecordResource extends JsonResource
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
            'scheduled_service_id' => $this->scheduled_service_id,
            'service_type' => $this->service_type,
            'service_date' => $this->service_date->toDateString(),
            'odometer_km' => $this->odometer_km,
            'vendor' => $this->vendor,
            'description' => $this->description,
            'reference' => $this->reference,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
