<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Services;

use Modules\Platform\Identity\Domain\Enums\SystemRole;

/** Mandatory 2FA is fixed for owner/admin; for every other role the Mod 114 tenant policy decides. */
final class MfaRequirementResolver
{
    /**
     * @param  list<SystemRole>  $roles
     */
    public function isRequired(array $roles, bool $tenantRequiresMfaForNonAdmins): bool
    {
        foreach ($roles as $role) {
            if ($role->requiresMandatoryMfa()) {
                return true;
            }
        }

        return $tenantRequiresMfaForNonAdmins;
    }

    /**
     * @param  list<SystemRole>  $roles
     */
    public function canDisable(array $roles, bool $tenantRequiresMfaForNonAdmins): bool
    {
        return ! $this->isRequired($roles, $tenantRequiresMfaForNonAdmins);
    }
}
