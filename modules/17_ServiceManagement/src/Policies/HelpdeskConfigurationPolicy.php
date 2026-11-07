<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuration of the helpdesk (support mailboxes, routing rules, settings, business hours, shared knowledge base
 * articles): every member reads it, only the service managers of the tenant change it.
 */
final class HelpdeskConfigurationPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ManagerRoles = ['owner', 'admin', 'service_manager'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isVisibleRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['service.manage']);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::ManagerRoles, ['service.manage']);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
