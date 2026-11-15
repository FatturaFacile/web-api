<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsTrackingEvent;

/**
 * A milestone of a transport order (picked up, at hub, delivered, exception, delay). Events drive the status of the order.
 *
 * @mixin TmsTrackingEvent
 */
final class TrackingEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transport_order_id' => $this->transport_order_id,
            'event_type' => $this->event_type,
            'location' => $this->location,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
