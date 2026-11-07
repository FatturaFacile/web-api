<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * A public message as the customer sees it: staff appear by name only, without their address.
 *
 * @mixin TicketMessage
 */
final class PortalHelpdeskMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_type' => $this->author_type,
            'author_name' => $this->author_name,
            'body' => $this->body,
            'attachments' => HelpdeskTicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
