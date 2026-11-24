<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\ReplenishmentRequest;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderAuditLog;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Invoicing\Services\CustomerCreditControlService;

/**
 * A sales order of the workspace: header with progress and credit figures; on the detail also its lines with the
 * backorder tree and ATP promise, delivery notes, replenishment pegging and audit trail.
 *
 * @mixin SalesOrder
 */
final class WorkspaceSalesOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type,
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer?->company_name),
            'blanket_agreement_id' => $this->blanket_agreement_id,
            'blanket_agreement_number' => $this->whenLoaded('blanketAgreement', fn (): ?string => $this->blanketAgreement?->agreement_number),
            'quote_id' => $this->quote_id,
            'warehouse_id' => $this->warehouse_id,
            'date' => $this->date->toDateString(),
            'requested_delivery_date' => $this->requested_delivery_date?->toDateString(),
            'confirmed_delivery_date' => $this->confirmed_delivery_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal_amount' => (string) $this->subtotal,
            'discount_amount' => (string) $this->discount_amount,
            'vat_amount' => (string) $this->total_tax,
            'total_amount' => (string) $this->total_gross,
            'credit_status' => $this->credit_status,
            'credit_block_reason' => $this->credit_block_reason,
            'credit_approved_by' => $this->credit_approved_by,
            'credit_approved_at' => $this->credit_approved_at?->toIso8601String(),
            'credit_override_notes' => $this->credit_override_notes,
            'allocated_percentage' => (string) $this->allocated_percentage,
            'delivered_percentage' => (string) $this->delivered_percentage,
            'invoiced_percentage' => (string) $this->invoiced_percentage,
            'has_backorders' => $this->whenLoaded('items', fn (): bool => $this->items->contains(static fn (SalesOrderItem $item): bool => $item->is_backorder)),
            'notes' => $this->notes,
            'credit_exposure' => $this->when($this->relationLoaded('auditLogs'), fn (): array => app(CustomerCreditControlService::class)->snapshot($this->resource)),
            'lines' => $this->whenLoaded('items', fn (): array => $this->items->map(static fn (SalesOrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'blanket_agreement_line_id' => $item->blanket_agreement_line_id,
                'parent_line_id' => $item->parent_line_id,
                'is_backorder' => $item->is_backorder,
                'backorder_sequence' => $item->backorder_sequence,
                'description' => $item->description,
                'unit_of_measure' => $item->uom,
                'ordered_quantity' => (string) $item->quantity,
                'reserved_quantity' => (string) $item->quantity_reserved,
                'delivered_quantity' => (string) $item->quantity_delivered,
                'invoiced_quantity' => (string) $item->quantity_invoiced,
                'cancelled_quantity' => (string) $item->quantity_cancelled,
                'backorder_quantity' => bcsub(bcsub((string) $item->quantity, (string) $item->quantity_delivered, 4), (string) $item->quantity_cancelled, 4),
                'requested_date' => $item->requested_date?->toDateString(),
                'atp_promised_date' => $item->atp_promised_date?->toDateString(),
                'atp_status' => $item->atp_status,
                'replenishment_triggered' => $item->replenishment_triggered,
                'replenishment_reference_type' => $item->replenishment_reference_type,
                'replenishment_reference_id' => $item->replenishment_reference_id,
                'unit_price' => (string) $item->unit_price,
                'discount_percent' => (string) $item->discount_percentage,
                'tax_rate' => (string) $item->tax_rate,
                'line_subtotal' => (string) $item->line_total,
            ])->all()),
            'delivery_notes' => $this->whenLoaded('deliveryNotes', fn (): array => $this->deliveryNotes->map(static fn ($note): array => ['id' => $note->id, 'number' => $note->ddt_number ?? $note->delivery_number ?? null, 'status' => $note->status])->all()),
            'replenishment_requests' => $this->whenLoaded('replenishmentRequests', fn (): array => $this->replenishmentRequests->map(static fn (ReplenishmentRequest $request): array => [
                'id' => $request->id,
                'sales_order_item_id' => $request->sales_order_item_id,
                'request_type' => $request->request_type,
                'quantity' => (string) $request->quantity,
                'needed_by' => $request->needed_by?->toDateString(),
                'status' => $request->status,
            ])->all()),
            'audit_log' => $this->whenLoaded('auditLogs', fn (): array => $this->auditLogs->map(static fn (SalesOrderAuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'actor_id' => $log->actor_id,
                'payload' => $log->payload,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
