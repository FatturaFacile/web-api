<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Any member of the company reads the published models and the configured instances and uses the guided configurator.
 * The definition of models, characteristics, rules, mappings and formulas belongs to owners, administrators, sales
 * managers and members granted `cpq_configurator.manage`. The industrial cost and the margin are visible to those
 * three roles, to accountants and to members granted `cpq_configurator.costs`; sales staff see prices and lead times only.
 */
final class CpqConfiguratorPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin', 'sales_manager'];

    /** @var list<string> */
    private const array CostRoles = ['owner', 'admin', 'sales_manager', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Evaluate, confirm and print a configuration.
     */
    public function configure(User $user, ?Model $record = null): bool
    {
        return $this->isMember($user) && ($record === null || $this->isOwnRecord($record));
    }

    public function manage(User $user, ?Model $record = null): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['cpq_configurator.manage']) && ($record === null || $this->isOwnRecord($record));
    }

    public function viewCosts(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::CostRoles, ['cpq_configurator.costs']);
    }
}
