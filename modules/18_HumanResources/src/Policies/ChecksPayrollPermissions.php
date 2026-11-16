<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Payroll data is more sensitive than the rest of HR: access requires the tenant membership to grant
 * `hr.payroll.view` or `hr.payroll.manage` (directly or through a wildcard such as `hr.*` or `*`). Tenant owners
 * and admins hold every permission.
 */
trait ChecksPayrollPermissions
{
    public const PERMISSION_VIEW = 'hr.payroll.view';

    public const PERMISSION_MANAGE = 'hr.payroll.manage';

    /**
     * @var list<string>
     */
    private static array $privilegedRoles = ['owner', 'admin'];

    protected function canViewPayroll(User $user, ?string $tenantId = null): bool
    {
        return $this->hasPayrollPermission($user, self::PERMISSION_VIEW, $tenantId)
            || $this->hasPayrollPermission($user, self::PERMISSION_MANAGE, $tenantId);
    }

    protected function canManagePayroll(User $user, ?string $tenantId = null): bool
    {
        return $this->hasPayrollPermission($user, self::PERMISSION_MANAGE, $tenantId);
    }

    /**
     * The record must belong to the active tenant; system defaults (null tenant) are readable by everyone
     * allowed to view payroll data.
     */
    protected function isInActiveTenant(?string $recordTenantId, bool $allowSystemRecord = false): bool
    {
        if ($recordTenantId === null) {
            return $allowSystemRecord;
        }

        $activeTenantId = app(TenantContext::class)->getTenantId();

        return $activeTenantId !== null && (string) $activeTenantId === $recordTenantId;
    }

    private function hasPayrollPermission(User $user, string $permission, ?string $tenantId): bool
    {
        $tenantId ??= app(TenantContext::class)->getTenantId();

        if ($tenantId === null) {
            return false;
        }

        $membership = $user->tenants()->where('tenants.id', $tenantId)->first();

        if ($membership === null) {
            return false;
        }

        /** @var object{role?: string|null, permissions?: mixed} $pivot */
        $pivot = $membership->getRelation('pivot');

        if (in_array((string) ($pivot->role ?? ''), self::$privilegedRoles, true)) {
            return true;
        }

        $granted = $pivot->permissions ?? [];

        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }

        if (! is_array($granted)) {
            return false;
        }

        foreach ($granted as $grantedPermission) {
            if (is_string($grantedPermission) && $this->permissionMatches($grantedPermission, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function permissionMatches(string $granted, string $required): bool
    {
        if ($granted === '*' || $granted === $required) {
            return true;
        }

        return str_ends_with($granted, '.*') && str_starts_with($required, substr($granted, 0, -1));
    }
}
