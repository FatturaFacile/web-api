<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization of the electronic signature module.
 *  - viewAny: every member follows the signature requests of the company.
 *  - send: members that can act on business documents start, remind and void a signature request.
 *  - configure: provider credentials, certificates and the compliance templates are owners and administrators only, because
 *    the credentials sign in the name of the company.
 */
final class ElectronicSignaturePolicy
{
    private const array SendRoles = ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'purchasing', 'procurement_manager', 'hr', 'member'];

    private const array ConfigureRoles = ['owner', 'admin'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->member($user) !== null;
    }

    public function send(User $user): bool
    {
        return $this->allows($user, self::SendRoles, ['electronic_signature.write', 'electronic_signature.*']);
    }

    public function configure(User $user): bool
    {
        return $this->allows($user, self::ConfigureRoles, ['electronic_signature.configure', 'electronic_signature.*']);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $grants
     */
    private function allows(User $user, array $roles, array $grants): bool
    {
        $membership = $this->member($user);

        if ($membership === null) {
            return false;
        }

        if (in_array($membership->role, $roles, true)) {
            return true;
        }

        $permissions = is_string($membership->permissions) ? json_decode($membership->permissions, true) : $membership->permissions;

        return array_intersect(is_array($permissions) ? $permissions : [], ['*', ...$grants]) !== [];
    }

    private function member(User $user): ?object
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
    }
}
