<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesQuote;

/**
 * @mixin SalesQuote
 */
final class SalesQuoteResource extends JsonResource
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
            'quote_number' => $this->quote_number,
            'date' => $this->date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'status' => $this->status,
            'payment_terms' => $this->payment_terms,
            'shipping_terms' => $this->shipping_terms,
            'revision_number' => $this->revision_number,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
