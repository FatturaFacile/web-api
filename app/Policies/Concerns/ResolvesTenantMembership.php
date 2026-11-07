<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership checks shared by the policies of the vertical modules: the role and the granted permissions of the user in
 * the active tenant, and whether a record belongs to that tenant.
 */
trait ResolvesTenantMembership
{
    protected function isMember(User $user): bool
    {
        return $this->membershipOf($user) !== null;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    protected function hasRoleOrPermission(User $user, array $roles, array $permissions = []): bool
    {
        $pivot = $this->membershipOf($user);

        if ($pivot === null) {
            return false;
        }

        return in_array($pivot->getAttribute('role'), $roles, true)
            || array_intersect(['*', ...$permissions], $this->grantedPermissions($pivot->getAttribute('permissions'))) !== [];
    }

    protected function isOwnRecord(Model $record): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && (string) $record->getAttribute('tenant_id') === (string) $tenantId;
    }

    protected function isVisibleRecord(Model $record): bool
    {
        return $record->getAttribute('tenant_id') === null || $this->isOwnRecord($record);
    }

    private function membershipOf(User $user): ?object
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId === null ? null : $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
    }

    /**
     * The pivot is not cast, and some writers encode the JSON twice.
     *
     * @return list<string>
     */
    private function grantedPermissions(mixed $permissions): array
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
