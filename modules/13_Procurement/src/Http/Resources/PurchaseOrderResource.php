<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'exchange_rate' => (float) $this->exchange_rate,
            'net_amount' => (float) ($this->net_amount ?? $this->total_net ?? 0.0),
            'tax_amount' => (float) ($this->tax_amount ?? $this->total_tax ?? 0.0),
            'total_amount' => (float) ($this->total_amount ?? $this->total_gross ?? 0.0),
            'is_budget_encumbered' => (bool) $this->is_budget_encumbered,
            'budget_committed_amount' => (float) $this->budget_committed_amount,
            'is_locked' => (bool) $this->is_locked,
            'payment_terms_code' => $this->payment_terms_code,
            'incoterms' => $this->incoterms,
            'shipping_address' => $this->shipping_address,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'lines' => $this->whenLoaded('lines'),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
