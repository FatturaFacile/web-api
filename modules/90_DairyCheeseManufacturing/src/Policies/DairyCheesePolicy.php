<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the dairy and cheese manufacturing module.
 *  - viewAny: owners, administrators, accountants (also read-only) and production / warehouse / quality staff.
 *  - manage: milk collection, laboratory, vat batches, aging cellar and care operations.
 *  - settle: approving a monthly milk settlement and generating its billing document (owners, administrators, accountants).
 *  - regulatory: generating and marking DBMilch.ch / SIAN declarations and applying a recall (owners, administrators, quality managers).
 */
final class DairyCheesePolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'production', 'warehouse', 'quality', 'technician', 'member'];

    private const array ManageRoles = ['owner', 'admin', 'production', 'warehouse', 'quality', 'technician'];

    private const array SettleRoles = ['owner', 'admin', 'accountant'];

    private const array RegulatoryRoles = ['owner', 'admin', 'quality'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['dairy_cheese.read', 'dairy_cheese.write', 'dairy_cheese.settle', 'dairy_cheese.regulatory', 'dairy_cheese.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['dairy_cheese.write', 'dairy_cheese.*']);
    }

    public function settle(User $user): bool
    {
        return $this->allows($user, self::SettleRoles, ['dairy_cheese.settle', 'dairy_cheese.*']);
    }

    public function regulatory(User $user): bool
    {
        return $this->allows($user, self::RegulatoryRoles, ['dairy_cheese.regulatory', 'dairy_cheese.*']);
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

        if (in_array((string) $membership->role, $roles, true)) {
            return true;
        }

        $permissions = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($permissions) ? $permissions : [], ['*', ...$grants]) !== [];
    }
}
