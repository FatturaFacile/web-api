<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;

/**
 * A customer return with its lines.
 *
 * @mixin Rma
 */
final class RmaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rma_number' => $this->rma_number,
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->contact?->company_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->invoice_number,
            'sales_order_id' => $this->sales_order_id,
            'warehouse_id' => $this->warehouse_id,
            'reason_code' => $this->reason_code,
            'requested_on' => $this->requested_on->toDateString(),
            'window_overridden' => $this->window_overridden,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'received_at' => $this->received_at?->toIso8601String(),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'resolution_type' => $this->resolution_type,
            'refund_amount' => $this->refund_amount,
            'credit_note_reference' => $this->credit_note_reference,
            'resolution_notes' => $this->resolution_notes,
            'notes' => $this->notes,
            'allowed_transitions' => Rma::Transitions[$this->status] ?? [],
            'lines' => $this->relationLoaded('lines') ? $this->lines->map(static fn (RmaLine $line): array => [
                'id' => $line->id,
                'invoice_item_id' => $line->invoice_item_id,
                'sales_order_item_id' => $line->sales_order_item_id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'quantity_received' => $line->quantity_received,
                'quantity_inspected' => $line->quantity_inspected,
                'unit_price' => $line->unit_price,
                'serial_number' => $line->serial_number,
                'lot_number' => $line->lot_number,
                'reason_code' => $line->reason_code,
                'disposition' => $line->disposition,
                'disposition_warehouse_id' => $line->disposition_warehouse_id,
                'supplier_id' => $line->supplier_id,
                'inspection_notes' => $line->inspection_notes,
                'stock_movement_id' => $line->stock_movement_id,
            ])->values()->all() : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
