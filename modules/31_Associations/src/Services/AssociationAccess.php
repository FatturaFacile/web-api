<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Associations\Support\AssociationCapability;

/**
 * Reads the role of a user in the active tenant, never the one held in another company.
 */
final class AssociationAccess
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function allows(User $user, AssociationCapability $capability): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        $pivot = $tenantId === null ? null : $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if ($pivot === null) {
            return false;
        }

        return in_array($pivot->getAttribute('role'), $capability->roles(), true)
            || array_intersect($capability->grants(), $this->permissions($pivot->getAttribute('permissions'))) !== [];
    }

    /**
     * The pivot is not cast, and some writers encode the JSON twice.
     *
     * @return list<string>
     */
    private function permissions(mixed $permissions): array
    {
        while (is_string($permissions)) {
            $decoded = json_decode($permissions, true);

            if ($decoded === null) {
                return [];
            }

            $permissions = $decoded;
        }

        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }
}
