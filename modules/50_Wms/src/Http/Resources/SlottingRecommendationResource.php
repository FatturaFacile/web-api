<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsSlottingRecommendation;

/**
 * A suggested move of an item to a better bin, computed from its pick velocity and ABC class. Accepting it creates a move task.
 *
 * @mixin WmsSlottingRecommendation
 */
final class SlottingRecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,
            'slotting_rule_id' => $this->slotting_rule_id,
            'abc_class' => $this->abc_class,
            'velocity' => $this->velocity,
            'period_days' => $this->period_days,
            'from_bin_id' => $this->from_bin_id,
            'to_bin_id' => $this->to_bin_id,
            'target_bin_type' => $this->target_bin_type,
            'status' => $this->status,
            'task_id' => $this->task_id,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'reason' => $this->reason,
            'stock_item' => $this->whenLoaded('stockItem', fn (): ?array => $this->stockItem !== null ? ['id' => $this->stockItem->id, 'sku' => $this->stockItem->sku, 'name' => $this->stockItem->name] : null),
            'from_bin' => $this->whenLoaded('fromBin', fn (): ?array => $this->fromBin !== null ? ['id' => $this->fromBin->id, 'code' => $this->fromBin->code] : null),
            'to_bin' => $this->whenLoaded('toBin', fn (): ?array => $this->toBin !== null ? ['id' => $this->toBin->id, 'code' => $this->toBin->code] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
