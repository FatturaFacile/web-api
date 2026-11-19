<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\AnalyticalCostCenter;

/**
 * API representation of a AnalyticalCostCenter.
 *
 * @property AnalyticalCostCenter $resource
 */
final class CostCenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'parent_id' => $this->resource->parent_id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'center_type' => $this->resource->center_type,
            'responsible_user_id' => $this->resource->responsible_user_id,
            'is_active' => $this->resource->is_active,
            'valid_from' => $this->resource->valid_from,
            'valid_to' => $this->resource->valid_to,
        ];
    }
}
