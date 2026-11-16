<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Communication\Models\CommunicationConversation;

/**
 * An inbox conversation, with its messages when loaded.
 *
 * @mixin CommunicationConversation
 */
final class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'counterparty_address' => $this->counterparty_address,
            'counterparty_name' => $this->counterparty_name,
            'contact_id' => $this->contact_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'assigned_to' => $this->assigned_to,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'unread_count' => $this->unread_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'messages' => ConversationMessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
