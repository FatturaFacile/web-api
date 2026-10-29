<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'asset_id' => $this->asset_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_custodian_id' => $this->from_custodian_id,
            'to_custodian_id' => $this->to_custodian_id,
            'from_project_id' => $this->from_project_id,
            'to_project_id' => $this->to_project_id,
            'transfer_date' => $this->transfer_date?->format('Y-m-d'),
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
