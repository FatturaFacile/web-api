<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;

/**
 * An item of the human-in-the-loop queue: a proposed ERP action with field level confidences, the reasoning and the source
 * it came from. The source text and the payloads can hold personal data, so they stay out of the audit ledger payloads.
 *
 * @property string $id
 * @property string $tenant_id
 * @property SuggestionKind $kind
 * @property string $workflow
 * @property string $source_type
 * @property string|null $source_reference
 * @property string|null $source_text
 * @property array<string, mixed> $proposed_payload_json
 * @property array<string, float>|null $field_confidences_json
 * @property string $confidence
 * @property string|null $amount
 * @property string|null $reasoning
 * @property list<array<string, mixed>>|null $citations_json
 * @property SuggestionStatus $status
 * @property string $priority
 * @property string|null $fingerprint
 * @property string|null $provider_used
 * @property string|null $model_used
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_feedback
 * @property array<string, mixed>|null $final_payload_json
 * @property string|null $resulting_entity_type
 * @property string|null $resulting_entity_id
 * @property Carbon|null $expires_at
 */
final class AiSuggestion extends BaseEnterpriseModel
{
    protected $table = 'ai_suggestions';

    protected function casts(): array
    {
        return [
            'kind' => SuggestionKind::class,
            'status' => SuggestionStatus::class,
            'proposed_payload_json' => 'array',
            'field_confidences_json' => 'array',
            'citations_json' => 'array',
            'final_payload_json' => 'array',
            'confidence' => 'decimal:2',
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function auditPayload(array $attributes): array
    {
        unset($attributes['source_text'], $attributes['proposed_payload_json'], $attributes['final_payload_json']);

        return $attributes;
    }
}
