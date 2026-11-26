<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;

/**
 * Who reads and who writes rates. Every member of the company reads the rates (documents need them); owners, administrators and
 * accountants, or members granted currencies.write, enter manual and contract rates and trigger the synchronisation. Official
 * rates are never written by a company: a company's own rate sits beside them, tenant-scoped.
 */
final class ExchangeRatePolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array FinanceRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['currencies.write', 'currencies.*']);
    }

    public function sync(User $user): bool
    {
        return $this->create($user);
    }

    public function updateSettings(User $user): bool
    {
        return $this->create($user);
    }
}
