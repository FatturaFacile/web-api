<?php

declare(strict_types=1);

namespace Modules\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Events\Models\ManagedEvent;

/**
 * An event with its places taken and, when loaded, its sessions and ticket types.
 *
 * @mixin ManagedEvent
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at->format('Y-m-d H:i:s'),
            'ends_at' => $this->ends_at->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'capacity' => $this->capacity,
            'waitlist_enabled' => $this->waitlist_enabled,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'confirmed_count' => $this->when(array_key_exists('confirmed_count', $attributes), fn (): int => (int) $this->resource->getAttribute('confirmed_count')),
            'waitlisted_count' => $this->when(array_key_exists('waitlisted_count', $attributes), fn (): int => (int) $this->resource->getAttribute('waitlisted_count')),
            'sessions' => SessionResource::collection($this->whenLoaded('sessions')),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
