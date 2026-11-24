<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies\Concerns;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Record and role checks of the manufacturing policies. Read and write access per role is decided by the
 * module.access middleware; the policies add the record's tenant and, for sensitive actions (releasing a BOM revision,
 * deciding an engineering change, closing an order into the ledger), the manager roles or the manufacturing.approve
 * grant.
 */
trait ChecksManufacturingRoles
{
    use ResolvesTenantMembership;

    /** Roles that may decide sensitive manufacturing actions. */
    private const array ManagerRoles = ['owner', 'admin', 'production'];

    /** Grants that open the sensitive manufacturing actions to another role. */
    private const array ManagerGrants = ['manufacturing.approve', 'manufacturing.*'];

    /**
     * The record belongs to the active company.
     */
    protected function ownsRecord(?User $user, Model $record): bool
    {
        return $user !== null && $this->isOwnRecord($record);
    }

    /**
     * The record belongs to the active company and the user manages manufacturing there.
     */
    protected function managesRecord(?User $user, Model $record): bool
    {
        return $this->ownsRecord($user, $record) && $user !== null && $this->hasRoleOrPermission($user, self::ManagerRoles, self::ManagerGrants);
    }
}
