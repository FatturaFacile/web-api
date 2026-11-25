<?php

declare(strict_types=1);

namespace Modules\Intercompany\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the intercompany module: finance roles read and act, the same roles that open the `ledger` module. The
 * access to the ledger of every entity of the group is checked again by the consolidation guard inside the services.
 */
final class IntercompanyPolicy
{
    private const array ManageRoles = ['owner', 'admin', 'accountant'];

    private const array ViewRoles = [...self::ManageRoles, 'accountant_readonly'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function view(User $user): bool
    {
        return $this->allows($user, self::ViewRoles, ['intercompany.read', 'intercompany.write', 'intercompany.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['intercompany.write', 'intercompany.*']);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $grants
     */
    private function allows(User $user, array $roles, array $grants): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        $membership = $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if ($membership === null) {
            return false;
        }

        if (in_array($membership->role, $roles, true)) {
            return true;
        }

        $permissions = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($permissions) ? $permissions : [], ['*', ...$grants]) !== [];
    }
}
