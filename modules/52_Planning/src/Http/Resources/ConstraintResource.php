<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningCapacityConstraint;

/**
 * The capacity of a resource in a month of a scenario.
 *
 * @mixin PlanningCapacityConstraint
 */
final class ConstraintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scenario_id' => $this->scenario_id,
            'resource_id' => $this->resource_id,
            'resource_code' => $this->resource?->code,
            'period' => $this->period->toDateString(),
            'available_capacity' => $this->available_capacity,
            'notes' => $this->notes,
        ];
    }
}
