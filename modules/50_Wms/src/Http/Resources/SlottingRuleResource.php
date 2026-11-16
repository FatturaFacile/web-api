<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsSlottingRule;

/**
 * Says which kind of bin an item should live in, by ABC class and pick velocity (picks per day).
 *
 * @mixin WmsSlottingRule
 */
final class SlottingRuleResource extends JsonResource
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
            'abc_class' => $this->abc_class,
            'min_velocity' => $this->min_velocity,
            'max_velocity' => $this->max_velocity,
            'target_bin_type' => $this->target_bin_type,
            'target_zone_id' => $this->target_zone_id,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
