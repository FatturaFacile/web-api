<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Records of the active company, plus generic price lists and work cycles read-only. Billing is a fiscal act reserved to
 * the roles that write the invoicing module.
 */
final class TradesPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array BillingRoles = ['owner', 'admin', 'accountant', 'sales'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isVisibleRecord($record);
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

    public function bill(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::BillingRoles, ['invoicing.write']);
    }
}
