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

    public function reopen(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin']);
    }
}
