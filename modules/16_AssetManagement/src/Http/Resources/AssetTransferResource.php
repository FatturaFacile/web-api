<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetTransfer;

/**
 * A transfer of an asset between locations, custodians, projects or cost centres. Amounts are decimal strings.
 *
 * @mixin AssetTransfer
 */
final class AssetTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'transfer_number' => $this->transfer_number,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_custodian_id' => $this->from_custodian_id,
            'to_custodian_id' => $this->to_custodian_id,
            'from_project_id' => $this->from_project_id,
            'to_project_id' => $this->to_project_id,
            'from_cost_center_id' => $this->from_cost_center_id,
            'to_cost_center_id' => $this->to_cost_center_id,
            'transfer_date' => $this->transfer_date?->format('Y-m-d'),
            'status' => $this->status,
            'reason' => $this->reason,
            'is_locked' => (bool) $this->is_locked,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
