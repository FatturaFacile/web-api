<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Copilot\Models\CopilotConversation;

/**
 * API representation of a conversation; the messages are present on the detail only (tool call and tool result rows are
 * internal and never listed).
 *
 * @mixin CopilotConversation
 */
final class CopilotConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'locale' => $this->locale,
            'context_route' => $this->context_route,
            'context_entity_type' => $this->context_entity_type,
            'context_entity_id' => $this->context_entity_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'messages' => CopilotMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
