<?php

declare(strict_types=1);

namespace Modules\Quality\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the quality records is decided by the module access rules (config/permissions.php, module
 * "quality"). The policy keeps every record inside the active company and reserves the approval of controlled
 * procedures to owners, administrators and members granted quality.approve.
 */
final class QualityPolicy
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
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin'], ['quality.approve', 'quality.*']);
    }
}
