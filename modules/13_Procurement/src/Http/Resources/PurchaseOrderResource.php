<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;

/**
 * A purchase order with its lines. Amounts and quantities are decimal strings.
 *
 * @mixin PurchaseOrder
 */
final class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number ?? $this->order_number,
            'supplier_id' => $this->supplier_id,
            'status' => $this->status,
            'order_type' => $this->order_type,
            'order_date' => $this->order_date?->toDateString(),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'currency' => $this->currency,
            'exchange_rate' => (string) ($this->exchange_rate ?? '1.000000'),
            'net_amount' => (string) ($this->net_amount ?? $this->total_net ?? '0.00'),
            'tax_amount' => (string) ($this->tax_amount ?? $this->total_tax ?? '0.00'),
            'total_amount' => (string) ($this->total_amount ?? $this->total_gross ?? '0.00'),
            'is_budget_encumbered' => (bool) $this->is_budget_encumbered,
            'budget_committed_amount' => (string) ($this->budget_committed_amount ?? '0.00'),
            'is_locked' => (bool) $this->is_locked,
            'supplier_contract_id' => $this->supplier_contract_id,
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'rfq_id' => $this->rfq_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'workflow_instance_id' => $this->workflow_instance_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sales_order_id' => $this->sales_order_id,
            'is_dropship' => (bool) $this->is_dropship,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'dispatch_channel' => $this->dispatch_channel,
            'supplier_confirmation_number' => $this->supplier_confirmation_number,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'promised_delivery_date' => $this->promised_delivery_date?->toDateString(),
            'exchange_rate_date' => $this->exchange_rate_date?->toDateString(),
            'exchange_rate_source' => $this->exchange_rate_source,
            'reminder_count' => (int) $this->reminder_count,
            'last_reminder_at' => $this->last_reminder_at?->toIso8601String(),
            'payment_terms_code' => $this->payment_terms_code,
            'incoterms' => $this->incoterms,
            'shipping_address' => $this->shipping_address,
            'notes' => $this->notes,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (PurchaseOrderLine $line): array => [
                'id' => (string) $line->id,
                'line_number' => $line->line_number,
                'description' => $line->description,
                'supplier_catalog_id' => $line->supplier_catalog_id,
                'supplier_contract_line_id' => $line->supplier_contract_line_id,
                'quantity_ordered' => (string) $line->quantity_ordered,
                'quantity_received' => (string) $line->quantity_received,
                'quantity_returned' => (string) $line->quantity_returned,
                'quantity_invoiced' => (string) $line->quantity_invoiced,
                'uom' => $line->uom,
                'base_uom' => $line->base_uom,
                'uom_conversion_factor' => (string) $line->uom_conversion_factor,
                'quantity_base_ordered' => (string) $line->quantity_base_ordered,
                'quantity_base_received' => (string) $line->quantity_base_received,
                'quantity_confirmed' => $line->quantity_confirmed !== null ? (string) $line->quantity_confirmed : null,
                'discount_cascade' => $line->discount_cascade,
                'discount_percent_effective' => (string) $line->discount_percent_effective,
                'confirmed_delivery_date' => $line->confirmed_delivery_date?->toDateString(),
                'promised_delivery_date' => $line->promised_delivery_date?->toDateString(),
                'sales_order_line_id' => $line->sales_order_line_id,
                'unit_price' => (string) $line->unit_price,
                'list_unit_price' => $line->list_unit_price !== null ? (string) $line->list_unit_price : null,
                'tax_rate' => (string) $line->tax_rate,
                'line_net_amount' => (string) $line->line_net_amount,
                'line_tax_amount' => (string) $line->line_tax_amount,
                'line_total_amount' => (string) $line->line_total_amount,
                'is_closed' => (bool) $line->is_closed,
            ])->all()),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
            'source_requisition_links' => $this->whenLoaded('sourceRequisitionLinks', fn () => $this->sourceRequisitionLinks->map(static fn (PurchaseRequisitionDocumentLink $link): array => [
                'id' => (string) $link->id,
                'purchase_requisition_id' => (string) $link->purchase_requisition_id,
                'purchase_requisition_line_id' => (string) $link->purchase_requisition_line_id,
                'requisition_number' => $link->relationLoaded('requisition') ? $link->requisition?->requisition_number : null,
                'target_document_line_id' => $link->target_document_line_id,
                'converted_quantity' => (string) $link->converted_quantity,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
