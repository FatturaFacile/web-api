<?php

declare(strict_types=1);

namespace Modules\Collaboration\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\Collaboration\Models\CollabComment;

/**
 * Who reads and writes comments and tags is decided by the module access rules (config/permissions.php, module
 * "collaboration") and, per record, by read access to the module of the record. The policy keeps every row inside the
 * active company, lets a member edit only their own comments, and lets them delete their own comments (owners and
 * administrators may also remove any comment).
 */
final class CollaborationPolicy
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
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        return ! $record instanceof CollabComment || (string) $record->user_id === (string) $user->getKey();
    }

    public function delete(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        return ! $record instanceof CollabComment
            || (string) $record->user_id === (string) $user->getKey()
            || $this->hasRoleOrPermission($user, ['owner', 'admin']);
    }
}
