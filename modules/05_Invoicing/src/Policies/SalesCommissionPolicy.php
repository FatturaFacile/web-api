<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Commissions are part of someone's pay: owners, administrators, accountants and sales managers see all of them, an agent
 * only the own ones. The role is the one of the active tenant.
 */
final class SalesCommissionPolicy
{
    private const array ManagerRoles = ['owner', 'admin', 'accountant', 'sales_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function manage(User $user): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant($tenantId), self::ManagerRoles, true);
    }
}
