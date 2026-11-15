<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsSpcCharacteristic;

/**
 * A control chart characteristic with its specification limits.
 *
 * @mixin QmsSpcCharacteristic
 */
final class SpcCharacteristicResource extends JsonResource
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
            'unit' => $this->unit,
            'lower_spec_limit' => $this->lower_spec_limit,
            'upper_spec_limit' => $this->upper_spec_limit,
            'target' => $this->target,
            'subgroup_size' => $this->subgroup_size,
            'instrument_id' => $this->instrument_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
