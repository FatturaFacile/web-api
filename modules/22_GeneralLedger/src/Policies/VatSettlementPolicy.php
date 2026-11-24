<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\GeneralLedger\Models\VatSettlement;

/**
 * VAT returns are reserved to the owner, the administrators and whoever holds the accounting permission in the tenant:
 * the `accountant` role, or a grant in the permissions of the tenant membership. The read-only accountant can view the
 * registers and returns but not change anything.
 */
final class VatSettlementPolicy
{
    private const array ACCOUNTING_ROLES = ['owner', 'admin', 'accountant'];

    private const array READ_ONLY_ROLES = ['accountant_readonly'];

    private const array MEMBERSHIP_GRANTS = ['*', 'gl.*', 'gl.journals.post'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->hasAccountingAccess($user, $this->tenantContext->getTenantId());
    }

    public function view(User $user, VatSettlement $settlement): bool
    {
        return $this->hasAccountingAccess($user, (string) $settlement->tenant_id);
    }

    public function calculate(User $user): bool
    {
        return $this->hasAccountingAccess($user, $this->tenantContext->getTenantId());
    }

    public function post(User $user, VatSettlement $settlement): bool
    {
        return $this->hasAccountingAccess($user, (string) $settlement->tenant_id);
    }

    public function pay(User $user, VatSettlement $settlement): bool
    {
        return $this->hasAccountingAccess($user, (string) $settlement->tenant_id);
    }

    public function export(User $user, VatSettlement $settlement): bool
    {
        return $this->hasAccountingAccess($user, (string) $settlement->tenant_id);
    }

    public function viewRegisters(User $user): bool
    {
        return $this->hasAccountingAccess($user, $this->tenantContext->getTenantId());
    }

    /**
     * The VAT returns of the country packs (CA3, modelo 303 and 390, UStVA) can also be read by the read-only accountant.
     */
    public function viewReturns(User $user): bool
    {
        return $this->hasAccountingAccess($user, $this->tenantContext->getTenantId(), true);
    }

    public function manageSettings(User $user): bool
    {
        return $this->hasAccountingAccess($user, $this->tenantContext->getTenantId());
    }

    private function hasAccountingAccess(User $user, ?string $tenantId, bool $readOnly = false): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        $membership = $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if ($membership === null) {
            return false;
        }

        if (in_array($membership->role, self::ACCOUNTING_ROLES, true) || ($readOnly && in_array($membership->role, self::READ_ONLY_ROLES, true))) {
            return true;
        }

        $grants = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($grants) ? $grants : [], self::MEMBERSHIP_GRANTS) !== [];
    }
}
