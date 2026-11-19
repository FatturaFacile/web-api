<?php

declare(strict_types=1);

namespace App\Http\Resources\Cpq;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the customer sees of a quote on the signing page: never cost, margin or internal notes.
 *
 * @mixin Quote
 */
class PublicQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quote_number' => $this->quote_number,
            'date' => $this->date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'currency' => $this->currency,
            'status' => $this->status,
            'allow_optional_selection' => $this->allow_optional_selection,
            'total_net' => $this->total_net,
            'total_tax' => $this->total_tax,
            'total_gross' => $this->total_gross,
            'items' => $this->items->map(static fn (QuoteItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
                'discount_percentage' => $item->discount_percentage,
                'line_total' => $item->line_total,
                'is_optional' => $item->is_optional,
                'is_selected' => $item->is_selected,
                'specs' => is_array($item->configuration_snapshot) ? ($item->configuration_snapshot['labels'] ?? []) : [],
            ])->values()->all(),
        ];
    }
}
