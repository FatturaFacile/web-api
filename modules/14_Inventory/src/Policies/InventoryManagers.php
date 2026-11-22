<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Who takes the manager decisions of the warehouse in the active company: approving inventory adjustments and the
 * differences of physical counts, and changing putaway rules, cycle count plans and the bin master. The warehouse
 * role runs the floor (receipts, picks, counts) but does not approve its own stock differences.
 */
final class InventoryManagers
{
    /** Roles of the active company with manager rights on inventory. */
    public const array ROLES = ['owner', 'admin'];

    /** Roles of the active company that take the finance approval (level 2) of large count differences. */
    public const array FINANCE_ROLES = ['owner', 'accountant'];

    /**
     * The finance approver (CFO role): the owner or the accountant of the active company.
     */
    public static function isFinanceApprover(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant((string) $tenantId), self::FINANCE_ROLES, true);
    }

    public static function includes(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant((string) $tenantId), self::ROLES, true);
    }
}
