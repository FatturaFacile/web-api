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

    /**
     * Any member allowed by module.access may list hospitality records.
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
     * Any member allowed by module.access (waiting staff included) may open orders.
     */
    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Orders and tickets can be worked only inside the company they belong to.
     */
    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Voiding items, cancelling orders and similar corrections are reserved to owners, administrators and holders of
     * hospitality.manage.
     */
    public function manage(User $user, ?Model $record = null): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['hospitality.manage']) && ($record === null || $this->isOwnRecord($record));
    }
}
