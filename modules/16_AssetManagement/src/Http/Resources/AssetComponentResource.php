<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetComponent;

/**
 * A component of an asset with its cost allocation. Amounts are decimal strings.
 *
 * @mixin AssetComponent
 */
final class AssetComponentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_asset_id' => $this->parent_asset_id,
            'component_asset_id' => $this->component_asset_id,
            'component_code' => $this->component_code,
            'name' => $this->name,
            'serial_number' => $this->serial_number,
            'cost_allocation' => $this->cost_allocation !== null ? (string) $this->cost_allocation : null,
            'useful_life_months' => $this->useful_life_months !== null ? (int) $this->useful_life_months : null,
            'condition' => $this->condition,
            'status' => $this->status,
            'installed_date' => $this->installed_date?->format('Y-m-d'),
        ];
    }
}
