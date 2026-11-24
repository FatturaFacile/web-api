<?php

declare(strict_types=1);

namespace Modules\SwissVat\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\SwissVat\Models\SwissVatReturn;

/**
 * Authorization policy for Swiss VAT compliance operations.
 * Matches config/permissions.php `swiss_vat` grants: finance writers mutate, finance readers view.
 */
final class SwissVatPolicy
{
    private const array WRITE_ROLES = ['owner', 'admin', 'accountant'];

    private const array READ_ROLES = ['owner', 'admin', 'accountant', 'accountant_readonly'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, $this->tenantContext->getTenantId(), self::READ_ROLES);
    }

    public function view(User $user, SwissVatReturn $vatReturn): bool
    {
        return $this->hasRole($user, (string) $vatReturn->tenant_id, self::READ_ROLES);
    }

    public function manage(User $user): bool
    {
        return $this->hasRole($user, $this->tenantContext->getTenantId(), self::WRITE_ROLES);
    }

    /**
     * @param  list<string>  $roles
     */
    private function hasRole(User $user, ?string $tenantId, array $roles): bool
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

        $grants = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($grants) ? $grants : [], ['*', 'swiss_vat.*', 'gl.*']) !== [];
    }
}
