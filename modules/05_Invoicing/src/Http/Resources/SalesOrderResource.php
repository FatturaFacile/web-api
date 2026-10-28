<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesOrder;

/**
 * @mixin SalesOrder
 */
final class SalesOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contact_id' => $this->contact_id,
            'quote_id' => $this->quote_id,
            'order_number' => $this->order_number,
            'date' => $this->date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'status' => $this->status,
            'credit_status' => $this->credit_status,
            'credit_hold_reason' => $this->credit_hold_reason,
            'inventory_status' => $this->inventory_status,
            'fulfillment_status' => $this->fulfillment_status,
            'billing_status' => $this->billing_status,
            'payment_terms' => $this->payment_terms,
            'shipping_method' => $this->shipping_method,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items'),
            'holds' => $this->whenLoaded('holds'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
