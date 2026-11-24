<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the agent commission module. Commissions are part of someone's pay.
 *  - viewAny: owners, administrators, accountants (also read-only) and sales managers.
 *  - manage: contract conditions, rules, zones and the ledger synchronisation: owners, administrators, accountants and
 *    sales managers.
 *  - settle: approval, voiding, invoicing and payment of a statement, fiscal parameters and the compliance exports:
 *    owners, administrators and accountants only (sales managers set conditions but never release the money).
 */
final class AgentCommissionPolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'sales_manager'];

    private const array ManageRoles = ['owner', 'admin', 'accountant', 'sales_manager'];

    private const array SettleRoles = ['owner', 'admin', 'accountant'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['agent_commissions.read', 'agent_commissions.write', 'agent_commissions.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['agent_commissions.write', 'agent_commissions.*']);
    }

    public function settle(User $user): bool
    {
        return $this->allows($user, self::SettleRoles, ['agent_commissions.settle', 'agent_commissions.*']);
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
