<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Assistant\Models\AssistantQuestionLog;

/**
 * API representation of the assistant log.
 *
 * @mixin AssistantQuestionLog
 */
final class AssistantLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'role' => $this->role,
            'question' => $this->question,
            'resolver' => $this->resolver,
            'query_key' => $this->query_key,
            'parameters' => (object) ($this->parameters ?? []),
            'outcome' => $this->outcome,
            'row_count' => (int) $this->row_count,
            'thread_id' => $this->thread_id,
            'feedback' => $this->feedback,
            'feedback_comment' => $this->feedback_comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
