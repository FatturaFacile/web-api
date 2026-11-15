<?php

declare(strict_types=1);

namespace Modules\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Events\Models\EventTicketType;

/**
 * A ticket type with the places sold when counted.
 *
 * @mixin EventTicketType
 */
final class TicketTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'is_active' => $this->is_active,
            'sold_count' => $this->when(array_key_exists('sold_count', $this->resource->getAttributes()), fn (): int => (int) $this->resource->getAttribute('sold_count')),
        ];
    }
}
