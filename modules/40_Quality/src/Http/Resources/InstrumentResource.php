<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsInstrument;

/**
 * A measuring instrument with its calibration status (valid, due_soon, overdue, quarantined, retired).
 *
 * @mixin QmsInstrument
 */
final class InstrumentResource extends JsonResource
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
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'location' => $this->location,
            'unit' => $this->unit,
            'range_min' => $this->range_min,
            'range_max' => $this->range_max,
            'tolerance' => $this->tolerance,
            'calibration_interval_days' => $this->calibration_interval_days,
            'status' => $this->status,
            'notes' => $this->notes,
            'last_calibrated_on' => $this->last_calibrated_on?->toDateString(),
            'next_due_on' => $this->next_due_on?->toDateString(),
            'calibration_status' => $this->calibrationStatus(),
        ];
    }
}
