<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * @mixin TenantDataResidencyPolicy
 */
final class ResidencyPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'legal_entity_id' => $this->legal_entity_id,
            'scope_type' => $this->scope_type->value,
            'jurisdiction_zone' => $this->jurisdiction_zone->value,
            'compliance_frameworks' => $this->jurisdiction_zone->complianceFrameworks(),
            'primary_db_node_id' => $this->primary_db_node_id,
            'replica_db_node_ids' => $this->replica_db_node_ids_json ?? [],
            's3_storage_node_id' => $this->s3_storage_node_id,
            'vector_db_node_id' => $this->vector_db_node_id,
            'ai_inference_node_id' => $this->ai_inference_node_id,
            'backup_node_id' => $this->backup_node_id,
            'queue_node_id' => $this->queue_node_id,
            'enforce_strict_egress_block' => $this->enforce_strict_egress_block,
            'allow_aggregated_non_pii_consolidation' => $this->allow_aggregated_non_pii_consolidation,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
