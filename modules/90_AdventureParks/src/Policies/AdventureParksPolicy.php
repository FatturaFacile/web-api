<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the adventure parks module.
 *  - viewAny: owners, administrators, accountants (also read-only), managers and the dressing desk operators.
 *  - operate: the dressing desk (tickets, assign and return the PPE, waiver, briefing, daily opening check, inspections): owners,
 *    administrators, accountants, managers and operators.
 *  - manage: park configuration, slots, PPE register, workshops and school bookings: owners, administrators, accountants and managers.
 *  - invoice: the electronic invoice of a school trip: owners, administrators and accountants.
 */
final class AdventureParksPolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'manager', 'operator'];

    private const array OperateRoles = ['owner', 'admin', 'accountant', 'manager', 'operator'];

    private const array ManageRoles = ['owner', 'admin', 'accountant', 'manager'];

    private const array InvoiceRoles = ['owner', 'admin', 'accountant'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['adventure_parks.read', 'adventure_parks.write', 'adventure_parks.*']);
    }

    public function operate(User $user): bool
    {
        return $this->allows($user, self::OperateRoles, ['adventure_parks.write', 'adventure_parks.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['adventure_parks.write', 'adventure_parks.*']);
    }

    public function invoice(User $user): bool
    {
        return $this->allows($user, self::InvoiceRoles, ['adventure_parks.invoice', 'adventure_parks.*']);
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
