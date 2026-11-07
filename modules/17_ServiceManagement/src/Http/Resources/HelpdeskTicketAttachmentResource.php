<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketAttachment;

/**
 * Metadata of a ticket attachment. The file itself is downloaded from the authorized endpoint, never from a public URL.
 *
 * @mixin TicketAttachment
 */
final class HelpdeskTicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
