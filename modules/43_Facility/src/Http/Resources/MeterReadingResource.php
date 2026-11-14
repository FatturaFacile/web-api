<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityMeterReading;

/**
 * A meter reading with the consumption since the previous one and its cost.
 *
 * @mixin FacilityMeterReading
 */
final class MeterReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meter_id' => $this->meter_id,
            'read_on' => $this->read_on?->toDateString(),
            'value' => $this->value,
            'consumption' => $this->consumption,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'reading_type' => $this->reading_type,
            'recorded_by' => $this->recorded_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
