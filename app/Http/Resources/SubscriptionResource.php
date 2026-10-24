<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->attributesToArray())->except(['deleted_at'])->all(),
            [
                'contact' => $this->whenLoaded('contact', fn () => [
                    'id' => $this->contact->id,
                    'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name),
                ]),
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $item->toArray())->all()),
                'runs' => $this->whenLoaded('runs', fn () => $this->runs->map(fn ($run): array => $run->toArray())->all()),
            ]
        );
    }
}
