<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;

/**
 * @mixin ByokKeyAccessAudit
 */
final class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kms_configuration_id' => $this->kms_configuration_id,
            'legal_entity_id' => $this->legal_entity_id,
            'operation' => $this->operation,
            'key_version' => $this->key_version,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'source_node_code' => $this->source_node_code,
            'outcome' => $this->outcome,
            'latency_ms' => $this->latency_ms,
            'previous_entry_hash' => $this->previous_entry_hash,
            'entry_hash' => $this->entry_hash,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
