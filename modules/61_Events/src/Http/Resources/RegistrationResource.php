<?php

declare(strict_types=1);

namespace Modules\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Events\Models\EventRegistration;

/**
 * A registration with its QR token for the check-in.
 *
 * @mixin EventRegistration
 */
final class RegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->whenLoaded('event', fn (): array => ['id' => $this->event->id, 'code' => $this->event->code, 'name' => $this->event->name]),
            'ticket_type_id' => $this->ticket_type_id,
            'ticket_type' => $this->whenLoaded('ticketType', fn (): array => ['id' => $this->ticketType->id, 'name' => $this->ticketType->name]),
            'contact_id' => $this->contact_id,
            'attendee_name' => $this->attendee_name,
            'attendee_email' => $this->attendee_email,
            'status' => $this->status,
            'qr_token' => $this->qr_token,
            'price' => $this->price,
            'registered_at' => $this->registered_at->format('Y-m-d H:i:s'),
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'checked_in_at' => $this->checked_in_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
        ];
    }
}
