<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;

/**
 * @mixin AiSuggestion
 */
final class AiSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'workflow' => $this->workflow,
            'source_type' => $this->source_type,
            'source_reference' => $this->source_reference,
            'source_text' => $this->source_text,
            'proposed_payload' => $this->proposed_payload_json,
            'field_confidences' => $this->field_confidences_json ?? [],
            'confidence' => (float) $this->confidence,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'reasoning' => $this->reasoning,
            'citations' => $this->citations_json ?? [],
            'status' => $this->status->value,
            'priority' => $this->priority,
            'provider_used' => $this->provider_used,
            'model_used' => $this->model_used,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_feedback' => $this->decision_feedback,
            'final_payload' => $this->final_payload_json,
            'resulting_entity_type' => $this->resulting_entity_type,
            'resulting_entity_id' => $this->resulting_entity_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
