<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesOrder
 */
class SalesOrderResource extends JsonResource
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
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $item->toArray())->all()),
            ]
        );
    }
}
