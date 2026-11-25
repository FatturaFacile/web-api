<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Roles of the franchise network. On the franchisor side the franchise director (owner, administrator, franchise_director) governs
 * agreements, formulas, pushes and billing; the area manager only runs audits, on the stores assigned to them. On the franchisee
 * side the owner and the manager of the store read the portal and acknowledge what they receive. The module access rules
 * (config/permissions.php, module "franchise_network") decide who may read and write at all; this policy keeps every franchisor
 * record inside the active company and reserves each action to its role.
 */
final class FranchiseNetworkPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array DirectorRoles = ['owner', 'admin', 'franchise_director'];

    /** @var list<string> */
    public const array AuditorRoles = ['area_manager'];

    /** @var list<string> */
    public const array FranchiseeRoles = ['owner', 'admin', 'franchisee_owner', 'franchisee_manager'];

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
        return $this->manage($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->manage($user) && $this->isOwnRecord($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->manage($user) && $this->isOwnRecord($record);
    }

    /**
     * Agreements, stores, formulas, pushes, sell-out, loyalty, billing runs and invoicing.
     */
    public function manage(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::DirectorRoles, ['franchise_network.manage', 'franchise_network.*']);
    }

    /**
     * Store audits: the franchise director and the area managers.
     */
    public function audit(User $user): bool
    {
        return $this->hasRoleOrPermission($user, [...self::DirectorRoles, ...self::AuditorRoles], ['franchise_network.audit', 'franchise_network.manage', 'franchise_network.*']);
    }

    /**
     * Read access to the franchisee portal (the store itself is resolved from the company link).
     */
    public function portal(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * What the franchisee acts on: redeem the link code, acknowledge a push.
     */
    public function portalWrite(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FranchiseeRoles, ['franchise_network.portal', 'franchise_network.*']);
    }
}
