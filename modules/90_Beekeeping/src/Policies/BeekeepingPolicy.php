<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the beekeeping module.
 *  - viewAny: owners, administrators, accountants (also read-only) and members.
 *  - manage: apiaries, hives, inspections, veterinary register, movements, census and honey lots: owners, administrators, accountants and members.
 *  - configureTax: the agricultural tax profile: owners, administrators and accountants only.
 */
final class BeekeepingPolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'member'];

    private const array ManageRoles = ['owner', 'admin', 'accountant', 'member'];

    private const array TaxRoles = ['owner', 'admin', 'accountant'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['beekeeping.read', 'beekeeping.write', 'beekeeping.tax', 'beekeeping.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['beekeeping.write', 'beekeeping.tax', 'beekeeping.*']);
    }

    public function configureTax(User $user): bool
    {
        return $this->allows($user, self::TaxRoles, ['beekeeping.tax', 'beekeeping.*']);
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
