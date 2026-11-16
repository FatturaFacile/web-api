<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Granular RBAC for delivery notes (DDT) and logistics. The permission catalog is seeded by PermissionSeeder.
 * A membership holds a permission through (a) the default matrix of its tenant role or (b) the tenant_user pivot
 * `permissions` JSON list (wildcards `delivery_notes.*`, `logistics.*`, `*` supported).
 */
final class LogisticsPermissions
{
    public const DDT_VIEW = 'delivery_notes.view';

    public const DDT_CREATE = 'delivery_notes.create';

    public const DDT_UPDATE = 'delivery_notes.update';

    public const DDT_DELETE = 'delivery_notes.delete';

    public const DDT_ISSUE = 'delivery_notes.issue';

    public const DDT_CANCEL = 'delivery_notes.cancel';

    public const DDT_INVOICE = 'delivery_notes.invoice';

    public const DDT_PDF = 'delivery_notes.pdf';

    public const TRANSFERS_MANAGE = 'logistics.transfers.manage';

    /** Manager sign-off: approving a transfer, resolving shortage/damage discrepancies and closing transfers. */
    public const TRANSFERS_APPROVE = 'logistics.transfers.approve';

    public const CONSIGNMENT_RECONCILE = 'logistics.consignment.reconcile';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DDT_VIEW, self::DDT_CREATE, self::DDT_UPDATE, self::DDT_DELETE, self::DDT_ISSUE, self::DDT_CANCEL,
            self::DDT_INVOICE, self::DDT_PDF, self::TRANSFERS_MANAGE, self::TRANSFERS_APPROVE, self::CONSIGNMENT_RECONCILE,
        ];
    }

    /**
     * Default permissions per tenant role.
     *
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        return [
            'owner' => self::all(),
            'admin' => self::all(),
            'logistics_manager' => self::all(),
            'sales_manager' => [self::DDT_VIEW, self::DDT_CREATE, self::DDT_UPDATE, self::DDT_DELETE, self::DDT_ISSUE, self::DDT_CANCEL, self::DDT_INVOICE, self::DDT_PDF],
            'warehouse_operator' => [self::DDT_VIEW, self::DDT_CREATE, self::DDT_UPDATE, self::DDT_ISSUE, self::DDT_PDF, self::TRANSFERS_MANAGE],
            'accountant' => [self::DDT_VIEW, self::DDT_INVOICE, self::DDT_PDF, self::CONSIGNMENT_RECONCILE],
            'viewer' => [self::DDT_VIEW, self::DDT_PDF],
            'technician' => [self::DDT_VIEW],
        ];
    }

    /**
     * Effective permissions of a user in the active tenant.
     *
     * @return list<string>
     */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $membership = $user->tenants()->when($tenantId !== null, fn ($q) => $q->where('tenants.id', $tenantId))->first();
        if ($membership === null) {
            return [];
        }

        $granted = $membership->pivot->permissions;
        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }
        $granted = is_array($granted) ? $granted : [];

        $effective = self::roleMatrix()[(string) $membership->pivot->role] ?? [];
        foreach ($granted as $permission) {
            if ($permission === '*') {
                $effective = array_merge($effective, self::all());
            } elseif ($permission === 'logistics.*') {
                $effective = array_merge($effective, array_filter(self::all(), fn (string $p): bool => str_starts_with($p, 'logistics.')));
            } elseif ($permission === 'delivery_notes.*') {
                $effective = array_merge($effective, array_filter(self::all(), fn (string $p): bool => str_starts_with($p, 'delivery_notes.')));
            } else {
                $effective[] = (string) $permission;
            }
        }

        return array_values(array_unique($effective));
    }

    public static function allows(?User $user, string $permission): bool
    {
        return in_array($permission, self::forUser($user), true);
    }
}
