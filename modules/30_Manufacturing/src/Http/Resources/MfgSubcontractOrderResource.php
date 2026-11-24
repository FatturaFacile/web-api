<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgSubcontractOrder;
use Modules\Manufacturing\Models\MfgSubcontractOrderLine;

/**
 * A subcontract order with the components sent, consumed and returned. Amounts and quantities are decimal strings.
 *
 * @mixin MfgSubcontractOrder
 */
final class MfgSubcontractOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subcontract_number' => $this->subcontract_number,
            'production_order_id' => $this->production_order_id,
            'production_order_operation_id' => $this->production_order_operation_id,
            'subcontractor_contact_id' => $this->subcontractor_contact_id,
            'source_warehouse_id' => $this->source_warehouse_id,
            'subcontract_warehouse_id' => $this->subcontract_warehouse_id,
            'status' => $this->status,
            'quantity' => $this->quantity,
            'received_quantity' => $this->received_quantity,
            'scrapped_quantity' => $this->scrapped_quantity,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (MfgSubcontractOrderLine $line): array => [
                'id' => $line->id,
                'production_order_line_id' => $line->production_order_line_id,
                'mfg_item_id' => $line->mfg_item_id,
                'quantity_sent' => $line->quantity_sent,
                'quantity_consumed' => $line->quantity_consumed,
                'quantity_returned' => $line->quantity_returned,
            ])->values()->all()),
        ];
    }
}
