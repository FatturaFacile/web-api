<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes passenger transport is decided by the module access rules (config/permissions.php, module
 * "passenger_transport"). The policy keeps every record inside the active company and reserves what moves money to finance:
 * invoicing bookings (single or month-end) and settling the service exchange belong to owners, administrators, accountants and
 * members granted passenger_transport.bill.
 */
final class PassengerTransportPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array FinanceRoles = ['owner', 'admin', 'accountant'];

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

    /**
     * Invoicing bookings and settling the service exchange.
     */
    public function bill(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['passenger_transport.bill', 'passenger_transport.*']);
    }
}
