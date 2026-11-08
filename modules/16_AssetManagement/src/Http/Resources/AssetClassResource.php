<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetClass;

/**
 * An operational asset class (criticality, maintenance interval). Amounts are decimal strings.
 *
 * @mixin AssetClass
 */
final class AssetClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'criticality' => $this->criticality,
            'default_maintenance_interval_days' => $this->default_maintenance_interval_days !== null ? (int) $this->default_maintenance_interval_days : null,
            'requires_custodian' => (bool) $this->requires_custodian,
            'requires_calibration' => (bool) $this->requires_calibration,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
