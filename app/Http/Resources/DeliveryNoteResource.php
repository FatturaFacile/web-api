<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryNote
 */
class DeliveryNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->getAttributes())->except(['deleted_at', 'signature_data'])->all(),
            [
                'contact_id' => $this->contact_id,
                'contact' => $this->whenLoaded('contact', fn () => ['id' => $this->contact->id, 'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name)]),
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $this->show_prices
                    ? $item->toArray()
                    : collect($item->toArray())->except(['unit_price', 'tax_rate'])->all())->all()),
            ]
        );
    }
}
