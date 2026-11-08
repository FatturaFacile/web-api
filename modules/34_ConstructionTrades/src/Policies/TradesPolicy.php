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

    /**
     * Any member allowed by module.access may list bids, price lists and work cycles.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * A record is visible inside its company; generic price lists and work cycles are visible to all.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isVisibleRecord($record);
    }

    /**
     * Any member allowed by module.access may create records of the company.
     */
    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Only records of the company change; generic records are read-only.
     */
    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Only records of the company are removed; generic records are read-only.
     */
    public function delete(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Billing is a fiscal act reserved to the invoicing roles.
     */
    public function bill(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::BillingRoles, ['invoicing.write']);
    }
}
