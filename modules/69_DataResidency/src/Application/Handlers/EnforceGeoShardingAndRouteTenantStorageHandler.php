<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\DataResidency\Application\Commands\EnforceGeoShardingAndRouteTenantStorageCommand;
use Modules\DataResidency\Domain\Enums\ResidencyScopeType;
use Modules\DataResidency\Domain\Enums\SovereignServiceType;
use Modules\DataResidency\Domain\Events\TenantDataResidencyEnforcedEvent;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\SovereignS3StorageRouter;

/**
 * Binds the database shard, replicas, object storage, vector store, AI inference, backup vault and queue broker of a
 * tenant or legal entity to sovereign nodes inside its jurisdiction zone, and activates (locks) the policy. A locked policy
 * moves only through a dual-authorised sovereign migration: a second, different approver is required.
 */
final class EnforceGeoShardingAndRouteTenantStorageHandler
{
    public function __construct(private readonly SovereignS3StorageRouter $storage) {}

    public function handle(EnforceGeoShardingAndRouteTenantStorageCommand $command): TenantDataResidencyPolicy
    {
        $slots = [
            'primary_db_node_id' => [$command->primaryDbNodeId, SovereignServiceType::PostgresPrimary],
            's3_storage_node_id' => [$command->s3StorageNodeId, SovereignServiceType::S3Dms],
            'vector_db_node_id' => [$command->vectorDbNodeId, SovereignServiceType::Pgvector],
            'ai_inference_node_id' => [$command->aiInferenceNodeId, SovereignServiceType::AiInference],
            'backup_node_id' => [$command->backupNodeId, SovereignServiceType::BackupVault],
            'queue_node_id' => [$command->queueNodeId, SovereignServiceType::QueueBroker],
        ];

        $ids = array_values(array_unique([...array_column($slots, 0), ...$command->replicaDbNodeIds]));
        $nodes = $this->nodes($command->tenantId, $ids);

        $existing = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)
            ->where('legal_entity_id', $command->legalEntityId)->first();

        if ($command->legalEntityId === null && $existing === null) {
            $existing = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)->whereNull('legal_entity_id')->first();
        }

        if ($existing !== null && $existing->isLocked() && ($command->secondApproverId === null || $command->secondApproverId === $command->actorId)) {
            throw new DataResidencyException('The policy is locked: a sovereign migration needs a second, different approver.');
        }

        $policy = $existing ?? new TenantDataResidencyPolicy;
        $policy->forceFill(['tenant_id' => $command->tenantId, 'jurisdiction_zone' => $command->zone]);

        $attributes = [];

        foreach ($slots as $column => [$nodeId, $type]) {
            $attributes[$column] = $this->bind($policy, $nodes, $nodeId, $type)->id;
        }

        $replicas = [];

        foreach ($command->replicaDbNodeIds as $replicaId) {
            $replicas[] = $this->bind($policy, $nodes, $replicaId, SovereignServiceType::PostgresReplica)->id;
        }

        $storageNode = $nodes->get($command->s3StorageNodeId);
        $this->storage->assertNoCrossRegionReplication($storageNode instanceof SovereignRegionNode ? $storageNode->endpoint() : []);

        return DB::transaction(function () use ($command, $policy, $attributes, $replicas, $existing): TenantDataResidencyPolicy {
            $policy->forceFill([
                ...$attributes,
                'legal_entity_id' => $command->legalEntityId,
                'scope_type' => $command->legalEntityId === null ? ResidencyScopeType::TenantDefault : ResidencyScopeType::LegalEntityOverride,
                'replica_db_node_ids_json' => $replicas,
                'enforce_strict_egress_block' => $command->enforceStrictEgressBlock,
                'allow_aggregated_non_pii_consolidation' => $command->allowAggregatedNonPiiConsolidation,
                'locked_at' => now(),
                'updated_by' => $command->actorId,
            ]);

            if ($existing === null) {
                $policy->forceFill(['created_by' => $command->actorId]);
            }

            $policy->save();

            event(new TenantDataResidencyEnforcedEvent($command->tenantId, $command->legalEntityId, $command->zone->value, $policy->id));

            return $policy->load(['primaryDbNode', 's3StorageNode', 'vectorDbNode', 'aiInferenceNode', 'backupNode', 'queueNode']);
        });
    }

    /**
     * @param  Collection<string, SovereignRegionNode>  $nodes
     */
    private function bind(TenantDataResidencyPolicy $policy, Collection $nodes, string $nodeId, SovereignServiceType $type): SovereignRegionNode
    {
        $node = $nodes->get($nodeId) ?? throw new DataResidencyException("Sovereign node {$nodeId} does not exist.");

        if ($node->service_type !== $type) {
            throw new DataResidencyException("Node {$node->node_code} is a {$node->service_type->value} node, a {$type->value} node is required.");
        }

        if (! $node->is_active) {
            throw new DataResidencyException("Node {$node->node_code} is not active.");
        }

        $policy->assertNodeAllowedForJurisdiction($node);

        return $node;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, SovereignRegionNode>
     */
    private function nodes(string $tenantId, array $ids): Collection
    {
        return SovereignRegionNode::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereIn('id', $ids)
            ->where(static fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->get()->keyBy('id');
    }
}
