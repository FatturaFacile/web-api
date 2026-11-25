<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the statutory financial statements is decided by the module access rules (config/permissions.php, module
 * "statutory_financials"). The policy keeps every record inside the active company and reserves what freezes or files the statements
 * (locking, reopening, filing the dossier) to owners, administrators, accountants and members granted statutory_financials.file.
 */
final class StatutoryFinancialsPolicy
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
     * Locking, reopening and filing a dossier.
     */
    public function file(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::FinanceRoles, ['statutory_financials.file', 'statutory_financials.*']);
    }
}
