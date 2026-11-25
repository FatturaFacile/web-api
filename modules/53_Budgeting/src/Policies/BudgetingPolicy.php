<?php

declare(strict_types=1);

namespace Modules\Budgeting\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the budgeting records is decided by the module access rules (config/permissions.php, module
 * "budgeting"). The policy keeps every record inside the active company and reserves the approval decisions (approve,
 * reject, lock) to owners, administrators and members granted budgeting.approve.
 */
final class BudgetingPolicy
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

    public function approve(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin'], ['budgeting.approve', 'budgeting.*']);
    }

    /**
     * Spend rules, thresholds and actual volumes: owners, administrators, accountants and members granted
     * budgeting.control.manage.
     */
    public function manageControl(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['budgeting.control.manage', 'budgeting.*']);
    }

    /**
     * Deciding an override (deroga) is the CFO's call: owners, administrators and members granted
     * budgeting.override.approve.
     */
    public function decideOverride(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin'], ['budgeting.override.approve', 'budgeting.*']);
    }
}
