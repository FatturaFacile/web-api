<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Closings of the active company. Reopening a closed period is reserved to owners and administrators.
 */
final class PracticePolicy
{
    use ResolvesTenantMembership;

    /**
     * Any member allowed by module.access may read the practice data.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * A record is visible only inside the company it belongs to.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Any member allowed by module.access may create fee notes and closings.
     */
    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * A closing can be changed only inside the company it belongs to.
     */
    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Reopening a closed period is reserved to owners and administrators.
     */
    public function reopen(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin']);
    }
}
