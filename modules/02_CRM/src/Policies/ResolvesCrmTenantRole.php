<?php

declare(strict_types=1);

namespace Modules\CRM\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Lead management configuration (scoring, routing, sources, settings) is reserved to tenant managers;
 * day-to-day lead work (events, duplicates) is open to every member of the active tenant.
 */
trait ResolvesCrmTenantRole
{
    /**
     * Tenant membership roles allowed to change lead management configuration.
     *
     * @var list<string>
     */
    private static array $managerRoles = ['owner', 'admin', 'manager', 'sales_manager'];

    protected function isTenantMember(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && $user->belongsToTenant($tenantId);
    }

    protected function isLeadManager(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null || ! $user->belongsToTenant($tenantId)) {
            return false;
        }

        if (in_array($user->getRoleInTenant($tenantId), self::$managerRoles, true)) {
            return true;
        }

        return $user->hasRole('admin');
    }
}
