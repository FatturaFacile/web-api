<?php

declare(strict_types=1);

namespace Modules\Fleet\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the credit management records is decided by the module access rules (config/permissions.php, module
 * "credit-management"). The policy keeps every record inside the active company and reserves deciding a credit limit
 * request and writing a case off to owners, administrators and members granted credit-management.approve.
 */
final class CreditManagementPolicy
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

    public function approve(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->mayApprove($user);
    }

    /**
     * Whether the member decides credit limits and write-offs: owners, administrators and members granted
     * credit-management.approve.
     */
    public function mayApprove(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin'], ['credit-management.approve', 'credit-management.*']);
    }
}
