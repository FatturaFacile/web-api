<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMachineSignalMap;

/**
 * A protocol signal mapping of a machine.
 *
 * @mixin MfgMachineSignalMap
 */
final class MfgMachineSignalMapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'machine_id' => $this->machine_id,
            'gateway_id' => $this->gateway_id,
            'protocol' => $this->protocol,
            'source_key' => $this->source_key,
            'signal' => $this->signal,
            'data_type' => $this->data_type,
            'word_order' => $this->word_order,
            'scale_factor' => $this->scale_factor,
            'offset_value' => $this->offset_value,
            'value_path' => $this->value_path,
            'state_map' => $this->state_map,
            'unit' => $this->unit,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
