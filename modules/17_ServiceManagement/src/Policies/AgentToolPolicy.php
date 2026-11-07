<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Canned responses, macros and saved views: an agent sees the shared ones and their own, changes their own, and only a
 * service manager shares one with everybody or changes a shared one.
 */
final class AgentToolPolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $tool): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($tool) && ((bool) $tool->getAttribute('is_shared') || $tool->getAttribute('owner_user_id') === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Sharing a tool with every agent of the tenant.
     */
    public function share(User $user): bool
    {
        return $this->hasRoleOrPermission($user, HelpdeskConfigurationPolicy::ManagerRoles, ['service.manage']);
    }

    public function update(User $user, Model $tool): bool
    {
        if (! $this->isOwnRecord($tool)) {
            return false;
        }

        return (bool) $tool->getAttribute('is_shared') ? $this->share($user) : $tool->getAttribute('owner_user_id') === $user->id;
    }

    public function delete(User $user, Model $tool): bool
    {
        return $this->update($user, $tool);
    }
}
