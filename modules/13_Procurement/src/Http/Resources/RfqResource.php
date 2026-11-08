<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqLine;
use Modules\Procurement\Models\RfqSupplier;

/**
 * A request for quotation with its lines, invited suppliers and quotes.
 *
 * @mixin Rfq
 */
final class RfqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rfq_number' => $this->rfq_number,
            'title' => $this->title,
            'status' => $this->status,
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'response_deadline' => $this->response_deadline->toDateString(),
            'currency' => $this->currency,
            'cost_center_id' => $this->cost_center_id,
            'awarded_supplier_id' => $this->awarded_supplier_id,
            'awarded_quote_id' => $this->awarded_quote_id,
            'purchase_order_id' => $this->purchase_order_id,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (RfqLine $line): array => [
                'id' => (string) $line->id,
                'line_number' => $line->line_number,
                'description' => $line->description,
                'internal_sku' => $line->internal_sku,
                'quantity' => (string) $line->quantity,
                'uom' => $line->uom,
                'required_by_date' => $line->required_by_date?->toDateString(),
            ])->all()),
            'suppliers' => $this->whenLoaded('invitedSuppliers', fn () => $this->invitedSuppliers->map(static fn (RfqSupplier $invitation): array => [
                'supplier_id' => (string) $invitation->supplier_id,
                'status' => $invitation->status,
                'invited_at' => $invitation->invited_at?->toIso8601String(),
                'responded_at' => $invitation->responded_at?->toIso8601String(),
            ])->all()),
            'quotes' => SupplierQuoteResource::collection($this->whenLoaded('quotes')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
