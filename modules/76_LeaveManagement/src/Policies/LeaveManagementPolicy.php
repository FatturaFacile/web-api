<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Models\LeaveRequest;

/**
 * Authorization policy for Mod 074 Leave, Permits & Absences Management.
 * - Any tenant member can read their own ledgers/requests, view holiday catalogs, and submit/cancel own requests.
 * - HR, Payroll, Admins, Owners, and Department Managers can inspect cross-employee data, approve/reject requests,
 *   manage holiday calendars, entitlement policies, staffing thresholds, and run monthly accruals.
 */
final class LeaveManagementPolicy
{
    private const array ADMIN_ROLES = ['owner', 'admin', 'hr', 'payroll', 'department_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->isTenantMember($user, $this->tenantContext->getTenantId());
    }

    public function manageConfiguration(User $user): bool
    {
        return $this->hasAnyRoleOrGrant($user, $this->tenantContext->getTenantId(), self::ADMIN_ROLES, [
            '*',
            'leave_management.*',
            'leave_management.write',
            'hr.write',
        ]);
    }

    public function decide(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->hasAnyRoleOrGrant($user, (string) $leaveRequest->tenant_id, self::ADMIN_ROLES, [
            '*',
            'leave_management.*',
            'leave_management.approve',
            'hr.write',
        ]);
    }

    public function submitForEmployee(User $user, string $employeeId): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($this->manageConfiguration($user)) {
            return true;
        }

        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return Employee::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $employeeId)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->decide($user, $leaveRequest)) {
            return true;
        }

        return Employee::query()
            ->where('tenant_id', $leaveRequest->tenant_id)
            ->where('id', $leaveRequest->employee_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function isTenantMember(User $user, ?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return $user->tenants()->where('tenants.id', $tenantId)->exists();
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $grants
     */
    private function hasAnyRoleOrGrant(User $user, ?string $tenantId, array $roles, array $grants): bool
    {
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

        $permissions = is_string($membership->permissions)
            ? json_decode($membership->permissions, true)
            : $membership->permissions;

        return array_intersect(is_array($permissions) ? $permissions : [], $grants) !== [];
    }
}
