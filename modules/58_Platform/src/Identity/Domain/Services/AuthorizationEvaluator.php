<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Services;

use Modules\Platform\Identity\Domain\ValueObjects\AbacRule;

/**
 * Deny-by-default evaluation: access is granted when at least one role grants the permission (`*` and `module.*`
 * wildcards allowed) and no matching ABAC deny rule exists. Allow rules, when present for the permission, must match.
 */
final class AuthorizationEvaluator
{
    /**
     * @param  list<string>  $grantedPermissions  union of the permissions of every assigned role
     * @param  list<AbacRule>  $abacRules  rules of the assigned roles
     * @param  array<string, mixed>  $context  attributes of the record and request
     */
    public function allows(string $permission, array $grantedPermissions, array $abacRules = [], array $context = []): bool
    {
        if (! $this->isGranted($permission, $grantedPermissions)) {
            return false;
        }

        $restricting = false;
        $restrictionMet = false;

        foreach ($abacRules as $rule) {
            if ($rule->permission !== $permission) {
                continue;
            }

            if ($rule->isDeny()) {
                if ($rule->matches($context)) {
                    return false;
                }

                continue;
            }

            $restricting = true;
            $restrictionMet = $restrictionMet || $rule->matches($context);
        }

        return ! $restricting || $restrictionMet;
    }

    /**
     * @param  list<string>  $granted
     */
    private function isGranted(string $permission, array $granted): bool
    {
        if (in_array($permission, $granted, true) || in_array('*', $granted, true)) {
            return true;
        }

        $module = strstr($permission, '.', true);

        return $module !== false && in_array($module.'.*', $granted, true);
    }
}
