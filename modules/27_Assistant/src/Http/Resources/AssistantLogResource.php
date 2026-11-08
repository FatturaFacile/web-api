<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of the assistant log.
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
