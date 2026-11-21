<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DataResidency\Application\Queries\GetDataSovereigntyDashboardQuery;
use Modules\DataResidency\Domain\Enums\KeyOperation;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;

/**
 * The sovereignty posture of a tenant: the geo-sharding per tenant and per legal entity (a Swiss holding and its Italian
 * subsidiary sit on different nodes), the state of every key envelope with its last KMS outcome, the latest entries of
 * the hash-chained KMS log and the blocked egress attempts. No secret material is returned.
 */
final class GetDataSovereigntyDashboardHandler
{
    public function __construct(private readonly ByokAuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDataSovereigntyDashboardQuery $query): array
    {
        $tenantId = $query->tenantId;
        $since = Carbon::now()->subDays(30);

        $policies = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->with(['primaryDbNode', 's3StorageNode', 'vectorDbNode', 'aiInferenceNode', 'backupNode', 'queueNode'])->get();
        $envelopes = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->get();
        $entities = DB::table('companies')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['id', 'code', 'legal_name', 'country'])->keyBy('id');

        $blocked = CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenantId);

        return [
            'scopes' => $policies->map(function (TenantDataResidencyPolicy $policy) use ($entities, $envelopes): array {
                $entity = $policy->legal_entity_id === null ? null : $entities->get($policy->legal_entity_id);
                $envelope = $envelopes->firstWhere('legal_entity_id', $policy->legal_entity_id) ?? ($policy->legal_entity_id === null ? null : $envelopes->firstWhere('legal_entity_id', null));

                return [
                    'policy_id' => $policy->id,
                    'scope_type' => $policy->scope_type->value,
                    'legal_entity_id' => $policy->legal_entity_id,
                    'legal_entity_name' => $entity?->legal_name,
                    'legal_entity_country' => $entity?->country,
                    'jurisdiction_zone' => $policy->jurisdiction_zone->value,
                    'compliance_frameworks' => $policy->jurisdiction_zone->complianceFrameworks(),
                    'locked' => $policy->isLocked(),
                    'enforce_strict_egress_block' => $policy->enforce_strict_egress_block,
                    'aggregated_non_pii_consolidation' => $policy->allow_aggregated_non_pii_consolidation,
                    'nodes' => [
                        'postgres_primary' => $policy->primaryDbNode->coordinates()->toArray(),
                        'postgres_replicas' => SovereignRegionNode::query()->withoutGlobalScopes()->whereIn('id', $policy->replica_db_node_ids_json ?? [])->get()->map(static fn (SovereignRegionNode $node): array => $node->coordinates()->toArray())->all(),
                        's3_dms' => $policy->s3StorageNode->coordinates()->toArray(),
                        'pgvector' => $policy->vectorDbNode->coordinates()->toArray(),
                        'ai_inference' => $policy->aiInferenceNode->coordinates()->toArray(),
                        'backup_vault' => $policy->backupNode->coordinates()->toArray(),
                        'queue_broker' => $policy->queueNode->coordinates()->toArray(),
                    ],
                    'key_envelope_id' => $envelope?->id,
                ];
            })->values()->all(),
            'key_envelopes' => $envelopes->map(fn (CustomerManagedKeyEnvelope $envelope): array => $this->envelope($envelope))->values()->all(),
            'kms_audit' => [
                'chain' => $this->audit->verifyChain($tenantId, $since),
                'recent' => ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderByDesc('occurred_at')->orderByDesc('id')->limit($query->auditLimit)->get()->map(static fn (ByokKeyAccessAudit $entry): array => [
                    'id' => $entry->id, 'operation' => $entry->operation, 'key_version' => $entry->key_version, 'outcome' => $entry->outcome,
                    'latency_ms' => $entry->latency_ms, 'source_node_code' => $entry->source_node_code, 'occurred_at' => $entry->occurred_at->toIso8601String(),
                ])->all(),
            ],
            'egress_firewall' => [
                'blocked_last_30_days' => (clone $blocked)->where('occurred_at', '>=', $since)->count(),
                'recent_blocked' => (clone $blocked)->orderByDesc('occurred_at')->limit(20)->get()->map(static fn (CrossBorderBlockedAttempt $attempt): array => [
                    'id' => $attempt->id, 'destination_country_iso2' => $attempt->destination_country_iso2, 'purpose' => $attempt->purpose,
                    'target' => $attempt->target, 'reason' => $attempt->reason, 'occurred_at' => $attempt->occurred_at->toIso8601String(),
                ])->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(CustomerManagedKeyEnvelope $envelope): array
    {
        $last = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('kms_configuration_id', $envelope->id)
            ->whereIn('operation', [KeyOperation::UnwrapDek->value, KeyOperation::WrapDek->value, KeyOperation::KmsHealthCheck->value, KeyOperation::RotateKek->value])
            ->orderByDesc('occurred_at')->first();

        return [
            'id' => $envelope->id,
            'legal_entity_id' => $envelope->legal_entity_id,
            'mode' => $envelope->key_management_mode->value,
            'provider' => $envelope->provider_type->value,
            'status' => $envelope->status->value,
            'key_version' => $envelope->current_key_version,
            'dek_cache_ttl_seconds' => $envelope->dek_cache_ttl_seconds,
            'rotation_interval_days' => $envelope->rotation_interval_days,
            'last_rotated_at' => $envelope->last_rotated_at?->toIso8601String(),
            'next_rotation_due_at' => $envelope->next_rotation_due_at?->toIso8601String(),
            'rotation_due' => $envelope->isRotationDue(),
            'revoked_at' => $envelope->revoked_at?->toIso8601String(),
            'kms_health' => $last === null ? 'UNKNOWN' : ($last->outcome === 'SUCCESS' ? 'HEALTHY' : 'DEGRADED'),
            'last_kms_access_at' => $last?->occurred_at->toIso8601String(),
        ];
    }
}
