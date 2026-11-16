<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes lease records is decided by the module access rules (config/permissions.php, module "leases":
 * finance roles). The policy keeps every record inside the active company and reserves the measurement of a lease and
 * the posting of its journals, which move the ledger, to owners, administrators, accountants and members granted
 * leases.post.
 */
final class LeaseAccountingPolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Activating, remeasuring and posting a lease writes journals to the general ledger.
     */
    public function post(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['leases.post', 'leases.*']);
    }

    /**
     * Posting the periods of every lease of the company at once.
     */
    public function postAny(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['leases.post', 'leases.*']);
    }
}
