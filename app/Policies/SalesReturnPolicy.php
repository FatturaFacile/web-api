<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorising or refusing a return (and so a credit and a stock re-entry) is reserved to the sales managers of the
 * company; the other steps follow the module access of the sales cycle.
 */
class SalesReturnPolicy
{
    /**
     * Roles of the active company that authorise returns.
     */
    public const array ApproverRoles = ['owner', 'admin', 'sales_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function approve(User $user, SalesReturn $return): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null
            && (string) $return->tenant_id === (string) $tenantId
            && in_array($user->getRoleInTenant($tenantId), self::ApproverRoles, true);
    }
}
