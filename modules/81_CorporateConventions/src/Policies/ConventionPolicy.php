<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes conventions is decided by the module access rules (config/permissions.php, module "corporate_conventions").
 * The policy keeps every record inside the active company and reserves what moves money to finance: the B2B invoice of the employer
 * quota and the settlement of welfare vouchers belong to owners, administrators, accountants and members granted
 * corporate_conventions.bill.
 */
final class ConventionPolicy
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
     * Invoicing the employer quota and settling welfare vouchers.
     */
    public function bill(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['corporate_conventions.bill', 'corporate_conventions.*']);
    }
}
