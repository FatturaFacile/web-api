<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class PortalTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->where('is_internal', false)->map(fn ($msg): array => [
                'id' => $msg->id,
                'author_type' => $msg->author_type,
                'author_name' => $msg->author_name,
                'message' => $msg->message,
                'created_at' => $msg->created_at?->toISOString(),
            ])->values()->all()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($att): array => [
                'id' => $att->id,
                'original_name' => $att->original_name,
                'mime_type' => $att->mime_type,
                'size' => $att->size,
            ])->all()),
        ];
    }
}
