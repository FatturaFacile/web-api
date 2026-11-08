<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMaterialMovement;

/**
 * A material movement of a production order with its inventory movement and journal entry. Amounts and quantities are
 * decimal strings.
 *
 * @mixin MfgMaterialMovement
 */
final class MfgMaterialMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'production_order_id' => $this->production_order_id,
            'production_order_line_id' => $this->production_order_line_id,
            'subcontract_order_id' => $this->subcontract_order_id,
            'movement_type' => $this->movement_type,
            'mfg_item_id' => $this->mfg_item_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'stock_movement_id' => $this->stock_movement_id,
            'lot_id' => $this->lot_id,
            'serial_number_ids' => $this->serial_number_ids,
            'posting_date' => $this->posting_date->toDateString(),
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
