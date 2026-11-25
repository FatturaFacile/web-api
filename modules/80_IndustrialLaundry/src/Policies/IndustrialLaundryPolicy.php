<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the laundry data is decided by the module access rules (config/permissions.php, module
 * "industrial_laundry"). The policy keeps every record inside the active company and reserves what moves money to finance:
 * consolidating the monthly bill and drafting its invoice belong to owners, administrators, accountants and members granted
 * industrial_laundry.bill.
 */
final class IndustrialLaundryPolicy
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
     * Consolidating the monthly bill and drafting the invoice.
     */
    public function bill(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['industrial_laundry.bill', 'industrial_laundry.*']);
    }
}
