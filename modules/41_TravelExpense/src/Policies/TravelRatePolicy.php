<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Every member reads the rate tables (to estimate a trip); owners, administrators, HR and accountants, or members
 * granted travel.rates, maintain them.
 */
final class TravelRatePolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $rate): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($rate);
    }

    public function create(User $user): bool
    {
        return $this->canMaintain($user);
    }

    public function update(User $user, Model $rate): bool
    {
        return $this->canMaintain($user) && $this->isOwnRecord($rate);
    }

    public function delete(User $user, Model $rate): bool
    {
        return $this->update($user, $rate);
    }

    private function canMaintain(User $user): bool
    {
        return $this->hasRoleOrPermission($user, TravelTripPolicy::ManagerRoles, ['travel.rates', 'travel.*']);
    }
}
