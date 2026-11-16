<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Granular RBAC for the logistics area. The permission catalog is seeded by PermissionSeeder; a membership grants a
 * permission through the tenant_user pivot (`permissions` JSON list, wildcard `logistics.*` supported) or by holding a
 * privileged role.
 */
final class LogisticsPermissions
{
    public const DDT_VIEW = 'logistics.ddt.view';

    public const DDT_CREATE = 'logistics.ddt.create';

    public const DDT_EMIT = 'logistics.ddt.emit';

    public const DDT_CANCEL = 'logistics.ddt.cancel';

    public const TRANSFERS_MANAGE = 'logistics.transfers.manage';

    public const CONSIGNMENT_RECONCILE = 'logistics.consignment.reconcile';

    /** Roles that implicitly hold every logistics permission. */
    private const PRIVILEGED_ROLES = ['owner', 'admin', 'logistics_manager'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DDT_VIEW, self::DDT_CREATE, self::DDT_EMIT, self::DDT_CANCEL, self::TRANSFERS_MANAGE, self::CONSIGNMENT_RECONCILE];
    }

    public static function allows(?User $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $membership = $user->tenants()->when($tenantId !== null, fn ($q) => $q->where('tenants.id', $tenantId))->first();
        if ($membership === null) {
            return false;
        }

        if (in_array((string) $membership->pivot->role, self::PRIVILEGED_ROLES, true)) {
            return true;
        }

        $granted = $membership->pivot->permissions;
        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }
        if (! is_array($granted)) {
            return false;
        }

        return in_array($permission, $granted, true) || in_array('logistics.*', $granted, true) || in_array('*', $granted, true);
    }
}
