<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetMeterReading;

/**
 * A cumulative meter reading of an asset. Amounts are decimal strings.
 *
 * @mixin AssetMeterReading
 */
final class AssetMeterReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'meter_code' => $this->meter_code,
            'reading_value' => $this->reading_value !== null ? (string) $this->reading_value : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'source' => $this->source,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'notes' => $this->notes,
        ];
    }
}
