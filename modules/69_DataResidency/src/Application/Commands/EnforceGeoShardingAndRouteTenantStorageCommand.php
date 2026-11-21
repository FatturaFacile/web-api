<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Commands;

use Modules\DataResidency\Domain\Enums\JurisdictionZone;

final readonly class EnforceGeoShardingAndRouteTenantStorageCommand
{
    /**
     * @param  list<string>  $replicaDbNodeIds
     * @param  string|null  $secondApproverId  The second authoriser of a sovereign migration of a locked policy
     */
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $actorId,
        public JurisdictionZone $zone,
        public string $primaryDbNodeId,
        public array $replicaDbNodeIds,
        public string $s3StorageNodeId,
        public string $vectorDbNodeId,
        public string $aiInferenceNodeId,
        public string $backupNodeId,
        public string $queueNodeId,
        public bool $enforceStrictEgressBlock = true,
        public bool $allowAggregatedNonPiiConsolidation = true,
        public ?string $secondApproverId = null,
    ) {}
}
