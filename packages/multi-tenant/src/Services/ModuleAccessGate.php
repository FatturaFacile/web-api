<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Services;

use App\Models\User;
use InvalidArgumentException;

/**
 * The rule of config/permissions.php: the role of the user in the tenant, or a granular grant on the membership.
 * A `deny:<module>` (or `deny:<module>.<access>`) permission on the membership closes the module to that member, whatever
 * the role says; the owner is never locked out.
 */
final class ModuleAccessGate
{
    /**
     * @param  string  $access  read or write
     *
     * @throws InvalidArgumentException when the module is not in the configuration
     */
    public function allows(?User $user, ?string $tenantId, string $module, string $access): bool
    {
        $allowedRoles = config("permissions.modules.{$module}.{$access}");

        if (! is_array($allowedRoles)) {
            throw new InvalidArgumentException("Unknown module [{$module}] in the access configuration.");
        }

        $membership = $user !== null && $tenantId !== null ? $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot : null;

        if ($membership === null) {
            return false;
        }

        if ($this->isDenied((string) $membership->role, $membership->permissions, $module, $access)) {
            return false;
        }

        $acceptedPermissions = ["{$module}.{$access}", '*', ...config("permissions.modules.{$module}.grants.{$access}", [])];

        return $this->isAllowed((string) $membership->role, $membership->permissions, $allowedRoles, $acceptedPermissions);
    }

    private function isDenied(string $role, mixed $permissions, string $module, string $access): bool
    {
        if ($role === 'owner') {
            return false;
        }

        $granted = is_string($permissions) ? json_decode($permissions, true) : $permissions;

        return is_array($granted) && array_intersect(["deny:{$module}", "deny:{$module}.{$access}"], $granted) !== [];
    }

    /**
     * @param  array<int, string>  $allowedRoles
     * @param  array<int, string>  $acceptedPermissions
     */
    private function isAllowed(string $role, mixed $permissions, array $allowedRoles, array $acceptedPermissions): bool
    {
        if (in_array('*', $allowedRoles, true) || in_array($role, $allowedRoles, true)) {
            return true;
        }

        $granted = is_string($permissions) ? json_decode($permissions, true) : $permissions;

        return is_array($granted) && array_intersect($acceptedPermissions, $granted) !== [];
    }
}
