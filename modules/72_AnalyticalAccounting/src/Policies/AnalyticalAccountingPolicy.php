<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Module access rules (config/permissions.php, module "analytical_accounting") decide who reads and writes; the policy keeps
 * every record inside the active company and reserves running, consolidating and reversing apportionment cycles to
 * owners, administrators, accountants and members granted analytical.apportionment.run.
 */
final class AnalyticalAccountingPolicy
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

    public function runApportionment(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['analytical.apportionment.run', 'analytical.*']);
    }
}
