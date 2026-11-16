<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;

/**
 * A planned order of MRP: make order or purchase suggestion, with the document it was converted into.
 *
 * @mixin MfgMrpPlannedOrder
 */
final class MfgPlannedOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'planned_order_number' => $this->planned_order_number,
            'mrp_run_id' => $this->mrp_run_id,
            'mfg_item_id' => $this->mfg_item_id,
            'item_code' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->item_code),
            'item_name' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->name),
            'order_type' => $this->order_type,
            'quantity' => $this->quantity,
            'net_requirement' => $this->net_requirement,
            'bom_level' => $this->bom_level,
            'uom' => $this->uom,
            'start_date' => $this->start_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'bom_id' => $this->bom_id,
            'routing_id' => $this->routing_id,
            'converted_order_id' => $this->converted_order_id,
            'procurement_document_type' => $this->procurement_document_type,
            'procurement_document_id' => $this->procurement_document_id,
            'converted_at' => $this->converted_at?->toIso8601String(),
        ];
    }
}
