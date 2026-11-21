<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * The residency policy that governs a tenant or one of its legal entities: the entity override when there is one,
 * otherwise the tenant default. Null when the tenant never configured residency.
 */
final class ResidencyPolicyResolver
{
    public function resolve(string $tenantId, ?string $legalEntityId = null): ?TenantDataResidencyPolicy
    {
        $base = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->with(['primaryDbNode', 's3StorageNode', 'vectorDbNode', 'aiInferenceNode', 'backupNode', 'queueNode']);

        if ($legalEntityId !== null) {
            $override = (clone $base)->where('legal_entity_id', $legalEntityId)->first();

            if ($override !== null) {
                return $override;
            }
        }

        return $base->whereNull('legal_entity_id')->first();
    }
}
