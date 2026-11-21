<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * The residency context of the running request or job: the tenant, the legal entity and the policy that governs them. The
 * middleware binds it; the storage, queue and AI routers read it instead of resolving the policy again.
 */
final class ResidencyRuntime
{
    private ?string $tenantId = null;

    private ?string $legalEntityId = null;

    private ?TenantDataResidencyPolicy $policy = null;

    public function bind(string $tenantId, ?string $legalEntityId, ?TenantDataResidencyPolicy $policy): void
    {
        $this->tenantId = $tenantId;
        $this->legalEntityId = $legalEntityId;
        $this->policy = $policy;
    }

    public function policy(): ?TenantDataResidencyPolicy
    {
        return $this->policy;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function legalEntityId(): ?string
    {
        return $this->legalEntityId;
    }

    /** The queue routing key of the bound policy (`ch-zrh-q-01`), or null on the default queue. */
    public function queueRoutingKey(): ?string
    {
        return $this->policy?->queueNode->node_code;
    }
}
