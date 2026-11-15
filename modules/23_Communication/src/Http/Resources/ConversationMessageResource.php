<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Communication\Models\ConversationMessage;

/**
 * A message of an inbox conversation.
 *
 * @mixin ConversationMessage
 */
final class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'channel' => $this->channel,
            'sender_address' => $this->sender_address,
            'sender_user_id' => $this->sender_user_id,
            'body' => $this->body,
            'delivery_log_id' => $this->delivery_log_id,
            'delivery_status' => $this->delivery_status,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
