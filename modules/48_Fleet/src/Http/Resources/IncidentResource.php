<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Services\IncidentService;

/**
 * A traffic fine, accident or damage with its driver and payment status.
 *
 * @mixin FleetIncident
 */
final class IncidentResource extends JsonResource
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
            'kind' => $this->kind,
            'occurred_on' => $this->occurred_on->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'location' => $this->location,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_status' => $this->payment_status,
            'due_on' => $this->due_on?->toDateString(),
            'paid_on' => $this->paid_on?->toDateString(),
            'is_overdue' => IncidentService::isOverdue($this->resource),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
