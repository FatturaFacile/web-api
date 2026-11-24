<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Assistant\Models\AssistantThread;

/**
 * API representation of a conversation; the messages are present on the detail only.
 *
 * @mixin AssistantThread
 */
final class AssistantThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => AssistantLogResource::collection($this->whenLoaded('messages')),
        ];
    }
}
