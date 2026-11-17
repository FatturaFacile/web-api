<?php

declare(strict_types=1);

namespace Modules\Pricing\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes pricing records is decided by the module access rules (config/permissions.php, module "pricing").
 * The policy keeps every record inside the active company.
 */
final class PricingPolicy
{
    use ResolvesTenantMembership;

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
     * Whether the user may authorize a below-margin document: owners and administrators, or the role the margin policy asks for.
     */
    public function approveMargin(User $user, ?string $requiredRole): bool
    {
        return $this->hasRoleOrPermission($user, array_values(array_filter(['owner', 'admin', $requiredRole])), ['pricing.approve']);
    }
}
