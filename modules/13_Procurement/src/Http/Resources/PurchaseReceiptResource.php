<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;

/**
 * A goods receipt note with its lines, inspection outcome and landed costs.
 *
 * @mixin PurchaseReceipt
 */
final class PurchaseReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_delivery_note_ref' => $this->supplier_delivery_note_ref,
            'received_date' => $this->received_date?->toDateString(),
            'received_by_user_id' => $this->received_by_user_id,
            'warehouse_id' => $this->warehouse_id,
            'inspection_required' => (bool) $this->inspection_required,
            'inspection_status' => $this->inspection_status,
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'landed_cost_amount' => (string) ($this->landed_cost_amount ?? '0.00'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (PurchaseReceiptLine $line): array => [
                'id' => (string) $line->id,
                'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                'quantity_received' => (string) $line->quantity_received,
                'quantity_accepted' => (string) $line->quantity_accepted,
                'quantity_rejected' => (string) $line->quantity_rejected,
                'quantity_released' => (string) $line->quantity_released,
                'quantity_returned' => (string) $line->quantity_returned,
                'unit_cost' => $line->unit_cost !== null ? (string) $line->unit_cost : null,
                'inspection_status' => $line->inspection_status,
                'lot_number' => $line->lot_number,
                'serial_numbers' => $line->serial_numbers ?? ($line->serial_number !== null ? [$line->serial_number] : []),
                'warehouse_bin_id' => $line->warehouse_bin_id,
                'landed_cost_amount' => (string) $line->landed_cost_amount,
                'rejection_reason' => $line->rejection_reason,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
