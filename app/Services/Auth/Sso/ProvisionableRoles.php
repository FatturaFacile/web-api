<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

/**
 * The roles an identity provider (SSO claim mapping) or a SCIM client may give to a member: every role named in
 * config/permissions.php and the base `member` role, minus `sso.forbidden_provisioned_roles` (the owner is never
 * granted from outside the application).
 */
final class ProvisionableRoles
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $roles = ['member'];

        foreach ((array) config('permissions.modules') as $module) {
            foreach (['read', 'write'] as $access) {
                foreach ((array) ($module[$access] ?? []) as $role) {
                    if (is_string($role) && $role !== '*') {
                        $roles[] = $role;
                    }
                }
            }
        }

        return array_values(array_diff(array_unique($roles), (array) config('sso.forbidden_provisioned_roles')));
    }

    public static function isAllowed(?string $role): bool
    {
        return $role !== null && in_array($role, self::all(), true);
    }
}
