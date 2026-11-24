<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\InvoiceMatchingRun;

/**
 * @mixin InvoiceMatchingRun
 */
final class InvoiceMatchRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'passive_invoice_id' => $this->passive_invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'invoice_date' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->date?->toDateString()),
            'invoice_due_date' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->due_date?->toDateString()),
            'invoice_total_amount' => $this->whenLoaded('invoice', fn (): ?float => $this->invoice !== null ? (float) $this->invoice->total_amount : null),
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->relationLoaded('supplier') ? $this->invoice->supplier_name : null),
            'purchase_order_id' => $this->purchase_order_id,
            'po_number' => $this->whenLoaded('purchaseOrder', fn (): ?string => $this->purchaseOrder?->po_number),
            'match_type' => $this->match_type->value,
            'status' => $this->status->value,
            'is_blocking' => $this->status->isBlocking(),
            'trigger' => $this->trigger,
            'reference_source' => $this->reference_source,
            'reference_value' => $this->reference_value,
            'currency' => $this->currency,
            'ordered_total' => (float) $this->ordered_total,
            'received_total' => (float) $this->received_total,
            'invoiced_total' => (float) $this->invoiced_total,
            'variance_amount' => (float) $this->variance_amount,
            'variance_percent' => (float) $this->variance_percent,
            'gr_ir_amount' => (float) $this->gr_ir_amount,
            'price_variance_amount' => (float) $this->price_variance_amount,
            'line_count' => $this->line_count,
            'discrepancy_count' => $this->discrepancy_count,
            'is_current' => $this->is_current,
            'reasons' => $this->reasons ?? [],
            'tolerance_snapshot' => $this->tolerance_snapshot ?? [],
            'evaluated_at' => $this->evaluated_at->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'lines' => InvoiceMatchLineResource::collection($this->whenLoaded('lines')),
            'resolutions' => InvoiceMatchResolutionResource::collection($this->whenLoaded('resolutions')),
        ];
    }
}
