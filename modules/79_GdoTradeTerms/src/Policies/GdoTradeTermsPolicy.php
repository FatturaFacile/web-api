<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the GDO trade terms is decided by the module access rules (config/permissions.php, module
 * "gdo_trade_terms"). The policy keeps every record inside the active company and reserves what moves money to finance:
 * posting or reversing the monthly accrual and settling a contract (credit note, promotional invoice, dispute) belong to
 * owners, administrators, accountants and members granted gdo_trade_terms.settle.
 */
final class GdoTradeTermsPolicy
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
     * Posting and reversing the accrual runs, proposing and settling a contract.
     */
    public function settle(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['gdo_trade_terms.settle', 'gdo_trade_terms.*']);
    }
}
