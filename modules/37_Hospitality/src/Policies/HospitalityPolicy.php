<?php

declare(strict_types=1);

namespace Modules\Hospitality\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Records of the active company. The room plan and voiding sent items are reserved to managers.
 */
final class HospitalityPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin'];

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

    public function manage(User $user, ?Model $record = null): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['hospitality.manage']) && ($record === null || $this->isOwnRecord($record));
    }
}
