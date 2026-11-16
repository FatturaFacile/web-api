<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsSpcSample;

/**
 * A subgroup of measurements with its mean and range.
 *
 * @mixin QmsSpcSample
 */
final class SpcSampleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'characteristic_id' => $this->characteristic_id,
            'lot' => $this->lot,
            'measurements' => $this->measurements,
            'mean' => $this->mean,
            'range' => $this->range,
            'instrument_id' => $this->instrument_id,
            'recorded_by' => $this->recorded_by,
            'sampled_at' => $this->sampled_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
