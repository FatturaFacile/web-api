<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\ProjectAssetAllocation;

/**
 * An allocation of an asset to a project with utilization and cost. Amounts are decimal strings.
 *
 * @mixin ProjectAssetAllocation
 */
final class ProjectAssetAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'project_id' => $this->project_id,
            'allocated_from' => $this->allocated_from?->format('Y-m-d'),
            'allocated_to' => $this->allocated_to?->format('Y-m-d'),
            'hourly_rate' => $this->hourly_rate !== null ? (string) $this->hourly_rate : null,
            'daily_rate' => $this->daily_rate !== null ? (string) $this->daily_rate : null,
            'utilized_hours' => $this->utilized_hours !== null ? (string) $this->utilized_hours : null,
            'utilized_days' => $this->utilized_days !== null ? (string) $this->utilized_days : null,
            'attributed_cost' => $this->attributed_cost !== null ? (string) $this->attributed_cost : null,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
