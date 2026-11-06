<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Access rules of the working time data (personal data, LPD): everyone sees their own records, the owners and the
 * administrators of the active tenant see and correct those of the others. The role is the one on the tenant_user
 * pivot of the active tenant, never a role the user holds in another company.
 */
final class TimeTrackingPolicy
{
    private const MANAGER_ROLES = ['owner', 'admin'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewRecords(User $user, string $employeeId): bool
    {
        return (string) $user->id === $employeeId || $this->manage($user);
    }

    public function saveDailyRecord(User $user, string $employeeId): bool
    {
        return (string) $user->id === $employeeId || $this->manage($user);
    }

    /**
     * The history of an employee, or of the whole company (null) with the entries of the retention purge.
     */
    public function viewAudit(User $user, ?string $employeeId): bool
    {
        return $employeeId !== null && (string) $user->id === $employeeId || $this->manage($user);
    }

    /**
     * Entering records on behalf of others or in the past, correcting them, deleting them and changing a profile.
     */
    public function manage(User $user): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant($tenantId), self::MANAGER_ROLES, true);
    }
}
