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

    public static function includes(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant((string) $tenantId), self::ROLES, true);
    }
}
