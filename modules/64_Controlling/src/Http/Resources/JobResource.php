<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\AnalyticalJob;

/**
 * API representation of a AnalyticalJob.
 *
 * @property AnalyticalJob $resource
 */
final class JobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'customer_id' => $this->resource->customer_id,
            'sales_order_id' => $this->resource->sales_order_id,
            'cost_center_id' => $this->resource->cost_center_id,
            'status' => $this->resource->status,
            'start_date' => $this->resource->start_date,
            'end_date' => $this->resource->end_date,
            'contract_value' => $this->resource->contract_value,
            'estimated_cost_budget' => $this->resource->estimated_cost_budget,
        ];
    }
}
