<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generic resource for simple tenant records (catalog lookups, price lists, payment terms, alerts, loyalty cards, reminders, bank imports).
 */
class CatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->attributesToArray())->except(['deleted_at'])->all(),
            [
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $item->toArray())->all()),
                'transactions' => $this->whenLoaded('transactions', fn () => $this->transactions->map(fn ($row): array => $row->toArray())->all()),
                'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($row): array => $row->toArray())->all()),
                'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn ($row): array => $row->toArray())->all()),
            ]
        );
    }
}
