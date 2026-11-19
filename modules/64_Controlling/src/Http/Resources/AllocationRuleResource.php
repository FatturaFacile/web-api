<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\CostAllocationRule;

/**
 * API representation of a CostAllocationRule.
 *
 * @property CostAllocationRule $resource
 */
final class AllocationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'source_cost_center_id' => $this->resource->source_cost_center_id,
            'driver_type' => $this->resource->driver_type,
            'step_order' => $this->resource->step_order,
            'recipients' => $this->resource->recipients,
            'is_active' => $this->resource->is_active,
        ];
    }
}
