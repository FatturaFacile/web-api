<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the dunning module.
 *  - viewAny: owners, administrators, accountants (also read-only) and sales managers.
 *  - manage: policies, rates, templates, exclusions, payment plans, generating and sending notices: owners,
 *    administrators and accountants.
 *  - escalate: the legal steps (Betreibung dossier, messa in mora by PEC, releasing an exclusion): the same roles as
 *    manage, but a member of another role can be granted debt_collection.escalate on its own.
 */
final class DebtCollectionPolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'sales_manager'];

    private const array ManageRoles = ['owner', 'admin', 'accountant'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['debt_collection.read', 'debt_collection.write', 'debt_collection.escalate', 'debt_collection.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['debt_collection.write', 'debt_collection.escalate', 'debt_collection.*']);
    }

    public function escalate(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['debt_collection.escalate', 'debt_collection.*']);
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
