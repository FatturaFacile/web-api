<?php

declare(strict_types=1);

namespace Modules\Controlling\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Module access rules (config/permissions.php, module "controlling") decide who reads and writes; the policy keeps every
 * record inside the active company, reserves running allocations to controlling.allocations.run and the approval of
 * budgets (approve, lock) to owners, administrators and members granted budgeting.approve.
 */
final class ControllingPolicy
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

    public function runAllocations(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['controlling.allocations.run', 'controlling.*']);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin'], ['budgeting.approve', 'budgeting.*']);
    }
}
