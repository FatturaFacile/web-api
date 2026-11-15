<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsWaveRule;

/**
 * Criteria that group open pick tasks of a warehouse into a wave: minimum priority, due window, reference prefix and the size bounds of the wave.
 *
 * @mixin WmsWaveRule
 */
final class WaveRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'name' => $this->name,
            'min_priority' => $this->min_priority,
            'due_within_hours' => $this->due_within_hours,
            'reference_prefix' => $this->reference_prefix,
            'min_tasks' => $this->min_tasks,
            'max_tasks' => $this->max_tasks,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
