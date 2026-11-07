<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * A message of the ticket conversation as staff see it.
 *
 * @mixin TicketMessage
 */
final class HelpdeskTicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author_type' => $this->author_type,
            'author_user_id' => $this->author_user_id,
            'author_contact_id' => $this->author_contact_id,
            'author_name' => $this->author_name,
            'author_email' => $this->author_email,
            'visibility' => $this->visibility,
            'channel' => $this->channel,
            'body' => $this->body,
            'attachments' => HelpdeskTicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
