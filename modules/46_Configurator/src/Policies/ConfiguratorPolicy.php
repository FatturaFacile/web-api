<?php

declare(strict_types=1);

namespace Modules\Configurator\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\Configurator\Models\ProductConfiguration;

/**
 * Any member of the company reads the models and the configurations. Sales staff configure and own their
 * configurations (a manager can change anyone's). The definition of models, groups, options and rules belongs to owners,
 * administrators, sales managers and members granted `configurator.manage`; approving a configuration whose margin is
 * below the minimum belongs to owners, administrators, sales managers and members granted `configurator.approve`.
 */
final class ConfiguratorPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin', 'sales_manager'];

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

    /**
     * Change or delete a configuration: its author, or a manager.
     */
    public function update(User $user, ProductConfiguration $configuration): bool
    {
        return $this->isOwnRecord($configuration) && ((string) $configuration->created_by === (string) $user->id || $this->isManager($user));
    }

    public function delete(User $user, ProductConfiguration $configuration): bool
    {
        return $this->update($user, $configuration);
    }

    /**
     * Define models, groups, options and rules, publish and archive models.
     */
    public function manage(User $user, ?Model $record = null): bool
    {
        return $this->isManager($user, 'configurator.manage') && ($record === null || $this->isOwnRecord($record));
    }

    /**
     * Approve or reject a discount below the minimum margin.
     */
    public function approve(User $user, ?Model $record = null): bool
    {
        return $this->isManager($user, 'configurator.approve') && ($record === null || $this->isOwnRecord($record));
    }

    private function isManager(User $user, string $permission = 'configurator.manage'): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, [$permission]);
    }
}
