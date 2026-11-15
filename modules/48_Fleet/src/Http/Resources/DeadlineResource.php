<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetDeadline;
use Modules\Fleet\Services\DeadlineService;

/**
 * A compliance deadline with the days left to its expiry.
 *
 * @mixin FleetDeadline
 */
final class DeadlineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $days = DeadlineService::daysToExpiry($this->resource);

        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn (): array => ['id' => $this->vehicle->id, 'license_plate' => $this->vehicle->license_plate]),
            'deadline_type' => $this->deadline_type,
            'provider' => $this->provider,
            'reference' => $this->reference,
            'expires_on' => $this->expires_on->toDateString(),
            'days_to_expiry' => $days,
            'is_expired' => $this->status === FleetDeadline::Open && $days < 0,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'status' => $this->status,
            'renewed_at' => $this->renewed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
