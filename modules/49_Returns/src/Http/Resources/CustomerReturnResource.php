<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\CustomerReturnLine;

/**
 * @mixin CustomerReturn
 */
final class CustomerReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rma_number' => $this->rma_number,
            'rma_barcode' => $this->rma_barcode,
            'return_tracking_number' => $this->return_tracking_number,
            'carrier' => $this->carrier,
            'status' => $this->status,
            'channel' => $this->channel,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->contact?->company_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->invoice_number,
            'invoice_date' => $this->invoice?->date?->toDateString(),
            'delivery_note_id' => $this->delivery_note_id,
            'delivery_note_number' => $this->deliveryNote->document_number ?? $this->deliveryNote->ddt_number ?? null,
            'sales_order_id' => $this->sales_order_id,
            'warehouse_id' => $this->warehouse_id,
            'reason_code' => $this->reason_code,
            'requested_on' => $this->requested_on->toDateString(),
            'within_return_window' => $this->within_return_window,
            'within_warranty_window' => $this->within_warranty_window,
            'window_overridden' => $this->window_overridden,
            'days_since_purchase' => $this->days_since_purchase,
            'restocking_fee_applicable' => $this->restocking_fee_applicable,
            'restocking_fee_percent' => $this->restocking_fee_percent,
            'restocking_fee_amount' => $this->restocking_fee_amount,
            'gross_return_amount' => $this->gross_return_amount,
            'net_refundable_amount' => $this->net_refundable_amount,
            'photo_urls' => $this->photo_urls ?? [],
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'resolution_type' => $this->resolution_type,
            'credit_note_id' => $this->credit_note_id,
            'credit_note_reference' => $this->credit_note_reference,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map(static fn (CustomerReturnLine $line): array => [
                'id' => $line->id,
                'invoice_item_id' => $line->invoice_item_id,
                'delivery_note_item_id' => $line->delivery_note_item_id,
                'sales_order_item_id' => $line->sales_order_item_id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'quantity_received' => $line->quantity_received,
                'quantity_inspected' => $line->quantity_inspected,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate,
                'serial_number' => $line->serial_number,
                'lot_number' => $line->lot_number,
                'reason_code' => $line->reason_code,
                'grade' => $line->grade,
                'disposition' => $line->disposition,
                'photo_urls' => $line->photo_urls ?? [],
                'inspection_notes' => $line->inspection_notes,
                'stock_movement_id' => $line->stock_movement_id,
            ])->values()->all() : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
