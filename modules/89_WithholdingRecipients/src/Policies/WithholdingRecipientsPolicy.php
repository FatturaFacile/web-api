<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the withholding recipients module. Compensations are part of someone's pay and feed the F24 and the CU.
 *  - viewAny: owners, administrators, accountants (also read-only) and HR managers.
 *  - manage: recipient registry, declarations, INL communications: owners, administrators, accountants and HR managers.
 *  - settle: posting a payment, F24 / UniEmens exports and the annual CU: owners, administrators and accountants only.
 */
final class WithholdingRecipientsPolicy
{
    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'hr_manager', 'hr'];

    private const array ManageRoles = ['owner', 'admin', 'accountant', 'hr_manager', 'hr'];

    private const array SettleRoles = ['owner', 'admin', 'accountant'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, self::ReadRoles, ['withholding_recipients.read', 'withholding_recipients.write', 'withholding_recipients.settle', 'withholding_recipients.*']);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, self::ManageRoles, ['withholding_recipients.write', 'withholding_recipients.*']);
    }

    public function settle(User $user): bool
    {
        return $this->allows($user, self::SettleRoles, ['withholding_recipients.settle', 'withholding_recipients.*']);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $grants
     */
    private function allows(User $user, array $roles, array $grants): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

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

        $permissions = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($permissions) ? $permissions : [], ['*', ...$grants]) !== [];
    }
}
