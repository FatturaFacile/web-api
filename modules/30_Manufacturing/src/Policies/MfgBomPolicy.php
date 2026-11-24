<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Policies\Concerns\ChecksManufacturingRoles;

/**
 * Record-level rules of BOM revisions: drafts of the company can be edited by writers, releasing a revision for
 * production needs a manufacturing manager.
 */
final class MfgBomPolicy
{
    use ChecksManufacturingRoles;

    /**
     * The revision belongs to the active company.
     */
    public function view(?User $user, MfgBom $bom): bool
    {
        return $this->ownsRecord($user, $bom);
    }

    /**
     * Editing and revising a revision of the company that is not locked.
     */
    public function update(?User $user, MfgBom $bom): bool
    {
        return $this->ownsRecord($user, $bom) && ! $bom->is_locked;
    }

    /**
     * Releasing a draft for production: a manufacturing manager.
     */
    public function activate(?User $user, MfgBom $bom): bool
    {
        return $this->managesRecord($user, $bom) && ! $bom->is_locked;
    }
}
