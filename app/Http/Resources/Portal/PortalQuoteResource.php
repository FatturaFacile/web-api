<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class PortalQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_number' => $this->quote_number,
            'date' => $this->date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'currency' => $this->currency ?? 'EUR',
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'status' => $this->status,
            'is_accepted' => $this->status === 'accepted' || $this->signed_at !== null,
            'is_rejected' => $this->status === 'rejected',
            'signed_by' => $this->signed_by,
            'signed_at' => $this->signed_at?->toISOString(),
            'notes' => $this->notes,
            'custom_text' => $this->custom_text,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) $item->tax_rate,
                'discount_percentage' => (float) $item->discount_percentage,
                'line_total' => (float) $item->line_total,
            ])->all()),
        ];
    }
}
