<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

/**
 * The duties a membership holds: the read and write access its role gives on every module (config/permissions.php) plus
 * the permissions granted on the membership, minus what is denied. Owners and administrators hold wide access by design
 * and are reviewed by the governance function, not by the rules. A duty is a string like `treasury.write` or
 * `supplier_portal.bank_approve`; a granted `*` or `gl.*` holds every duty it covers.
 */
final class SodDuties
{
    /** @var list<string> */
    public const array ExemptRoles = ['owner', 'admin'];

    /**
     * @param  list<string>  $permissions
     * @return array{duties: list<string>, patterns: list<string>, denied: list<string>}
     */
    public function of(string $role, array $permissions): array
    {
        $duties = [];

        foreach ((array) config('permissions.modules') as $module => $access) {
            foreach (['read', 'write'] as $level) {
                $roles = (array) ($access[$level] ?? []);

                if (in_array($role, $roles, true) || in_array('*', $roles, true)) {
                    $duties[] = "{$module}.{$level}";
                }
            }
        }

        $patterns = [];
        $denied = [];

        foreach ($permissions as $permission) {
            if (str_starts_with($permission, 'deny:')) {
                $denied[] = substr($permission, 5);
            } elseif ($permission === '*' || str_ends_with($permission, '.*')) {
                $patterns[] = $permission;
            } else {
                $duties[] = $permission;
            }
        }

        return ['duties' => array_values(array_unique($duties)), 'patterns' => $patterns, 'denied' => $denied];
    }

    /**
     * @param  array{duties: list<string>, patterns: list<string>, denied: list<string>}  $held
     */
    public function holds(array $held, string $duty): bool
    {
        foreach ($held['denied'] as $denied) {
            if ($duty === $denied || str_starts_with($duty, $denied.'.')) {
                return false;
            }
        }

        if (in_array($duty, $held['duties'], true)) {
            return true;
        }

        if (str_ends_with($duty, '.*')) {
            $prefix = substr($duty, 0, -1);

            foreach ($held['duties'] as $heldDuty) {
                if (str_starts_with($heldDuty, $prefix)) {
                    return true;
                }
            }
        }

        foreach ($held['patterns'] as $pattern) {
            if ($pattern === '*' || str_starts_with($duty, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
