<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetCustodian;

/**
 * A custody of an asset by a user. Amounts are decimal strings.
 *
 * @mixin AssetCustodian
 */
final class AssetCustodianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'custodian_user_id' => $this->custodian_user_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'department' => $this->department,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'condition_on_checkout' => $this->condition_on_checkout,
            'status' => $this->status,
        ];
    }
}
