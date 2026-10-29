<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * @mixin MfgProductionOrder
 */
final class MfgProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'mfg_item_id' => $this->mfg_item_id,
            'bom_id' => $this->bom_id,
            'routing_id' => $this->routing_id,
            'status' => $this->status,
            'target_quantity' => (float) $this->target_quantity,
            'completed_quantity' => (float) $this->completed_quantity,
            'scrapped_quantity' => (float) $this->scrapped_quantity,
            'uom' => $this->uom,
            'planned_start_date' => $this->planned_start_date?->toDateString(),
            'planned_end_date' => $this->planned_end_date?->toDateString(),
            'standard_cost_total' => (float) $this->standard_cost_total,
            'actual_cost_total' => (float) $this->actual_cost_total,
            'variance_total' => (float) $this->variance_total,
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
