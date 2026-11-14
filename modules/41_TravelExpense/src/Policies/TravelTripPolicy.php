<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * Every member requests and follows their own trips. Owners, administrators, HR and accountants (or members granted
 * travel.manage) see every trip of the company. Approving is reserved to them and to members granted travel.approve,
 * and never applies to one's own trip, except for the owner of the company. Settling the advance (paid, reconcile) is
 * reserved to owners, administrators, accountants and members granted travel.reconcile.
 */
final class TravelTripPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ManagerRoles = ['owner', 'admin', 'hr', 'accountant'];

    /** @var list<string> */
    public const array FinanceRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function isManager(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['travel.manage', 'travel.*']);
    }

    public function view(User $user, TravelTrip $trip): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($trip) && ($this->isTravelling($user, $trip) || $this->isManager($user));
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, TravelTrip $trip): bool
    {
        return $this->view($user, $trip);
    }

    public function delete(User $user, TravelTrip $trip): bool
    {
        return $this->view($user, $trip);
    }

    public function approve(User $user, TravelTrip $trip): bool
    {
        if (! $this->isOwnRecord($trip) || ! $this->hasRoleOrPermission($user, self::ManagerRoles, ['travel.approve', 'travel.*'])) {
            return false;
        }

        return ! $this->isTravelling($user, $trip) || $this->hasRoleOrPermission($user, ['owner']);
    }

    public function settle(User $user, TravelTrip $trip): bool
    {
        return $this->isOwnRecord($trip) && $this->hasRoleOrPermission($user, self::FinanceRoles, ['travel.reconcile', 'travel.*']);
    }

    private function isTravelling(User $user, TravelTrip $trip): bool
    {
        return (string) $trip->user_id === (string) $user->getKey();
    }
}
