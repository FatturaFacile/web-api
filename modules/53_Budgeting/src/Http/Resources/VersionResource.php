<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * A budget version with the stamps of its approval; the approval trail is included when loaded.
 *
 * @mixin BudgetVersion
 */
final class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'fiscal_year' => $this->fiscal_year,
            'year_start' => $this->year_start->toDateString(),
            'version_type' => $this->version_type,
            'status' => $this->status,
            'currency' => $this->currency,
            'base_version_id' => $this->base_version_id,
            'reforecast_from_period' => $this->reforecast_from_period,
            'seasonal_weights' => $this->seasonal_weights,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'locked_by' => $this->locked_by,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'lines_count' => $this->whenCounted('lines'),
            'drivers_count' => $this->whenCounted('drivers'),
            'total_amount' => $this->when(isset($this->resource->lines_sum_amount), fn (): string => bcadd((string) $this->resource->lines_sum_amount, '0', 2)),
            'approvals' => ApprovalResource::collection($this->whenLoaded('approvals')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
