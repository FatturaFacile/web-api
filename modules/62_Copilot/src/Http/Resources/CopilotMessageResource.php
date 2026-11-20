<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Copilot\Models\CopilotMessage;

/**
 * API representation of a message, with its citations, chart hint and staged action.
 *
 * @mixin CopilotMessage
 */
final class CopilotMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'conversation_id' => (string) $this->conversation_id,
            'role' => $this->role->value,
            'content_markdown' => $this->content_markdown,
            'citations' => $this->citations_json ?? [],
            'visualization' => $this->visualization_json,
            'proposed_action' => $this->proposed_action_json,
            'action_status' => $this->action_status?->value,
            'token_usage' => $this->token_usage_json,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
