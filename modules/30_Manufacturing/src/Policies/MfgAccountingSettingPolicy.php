<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgAccountingSetting;
use Modules\Manufacturing\Policies\Concerns\ChecksManufacturingRoles;

/**
 * The ledger accounts of manufacturing decide where production is booked: only owners and administrators change them.
 */
final class MfgAccountingSettingPolicy
{
    use ChecksManufacturingRoles;

    /**
     * Owners and administrators of the active company.
     */
    public function update(?User $user, MfgAccountingSetting $setting): bool
    {
        return $user !== null && $this->isOwnRecord($setting) && $this->hasRoleOrPermission($user, ['owner', 'admin']);
    }
}
