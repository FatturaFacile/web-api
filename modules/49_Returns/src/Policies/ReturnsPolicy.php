<?php

declare(strict_types=1);

namespace Modules\Returns\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Who reads and writes the returns records is decided by the module access rules (config/permissions.php, module
 * "returns"). The policy keeps every record inside the active company and reserves authorising a return, overriding the
 * return window and deciding a warranty claim to owners, administrators and members granted returns.approve.
 */
final class ReturnsPolicy
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
     * Whether the member is an approver of the company, for rules that need no record (overriding the return window).
     */
    public function mayApprove(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin'], ['returns.approve', 'returns.*']);
    }
}
