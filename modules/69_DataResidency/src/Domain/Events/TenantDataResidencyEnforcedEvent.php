<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Events;

/** A node-bound policy was activated for a tenant or legal entity. */
final readonly class TenantDataResidencyEnforcedEvent
{
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $jurisdictionZone,
        public string $policyId,
    ) {}
}
