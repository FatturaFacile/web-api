<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsWave;

/**
 * A group of pick tasks released together to the floor. Planned from a wave rule; completes when every task is closed.
 *
 * @mixin WmsWave
 */
final class WaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'wave_rule_id' => $this->wave_rule_id,
            'number' => $this->number,
            'status' => $this->status,
            'task_count' => $this->task_count,
            'released_at' => $this->released_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
