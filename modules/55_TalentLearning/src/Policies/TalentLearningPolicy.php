<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Module access (config/permissions.php, modules "talent_learning" and "talent_planning") decides who reads and writes.
 * The policy keeps every record inside the active company and reserves the lists that show other employees (enrolments,
 * certificates, compliance, survey results) to people managers: owners, administrators, HR and members granted
 * talent_learning.manage. Approving a merit proposal is reserved to owners, administrators and members granted
 * talent_planning.approve.
 */
final class TalentLearningPolicy
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

    public function manage(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin', 'hr'], ['talent_learning.manage', 'talent_learning.*']);
    }

    /**
     * Pickers of employees and positions, for everybody who plans talent or manages learning.
     */
    public function lookup(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin', 'hr', 'payroll'], ['talent_learning.manage', 'talent_learning.*', 'talent_planning.*']);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin'], ['talent_planning.approve', 'talent_planning.*']);
    }
}
