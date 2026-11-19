<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\CostAllocationRun;

/**
 * API representation of a CostAllocationRun.
 *
 * @property CostAllocationRun $resource
 */
final class AllocationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'fiscal_year' => $this->resource->fiscal_year,
            'fiscal_month' => $this->resource->fiscal_month,
            'status' => $this->resource->status,
            'total_allocated_amount' => $this->resource->total_allocated_amount,
            'executed_by' => $this->resource->executed_by,
            'executed_at' => $this->resource->executed_at,
            'reversed_at' => $this->resource->reversed_at,
        ];
    }
}
