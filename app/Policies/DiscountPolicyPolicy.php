<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Promotions change the prices of every sales document: only the sales managers of the company maintain them; every
 * member with access to the sales cycle reads them.
 */
class DiscountPolicyPolicy
{
    /**
     * Roles of the active company that maintain the discount policies.
     */
    public const array ManagerRoles = ['owner', 'admin', 'sales_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function manage(User $user): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant($tenantId), self::ManagerRoles, true);
    }
}
