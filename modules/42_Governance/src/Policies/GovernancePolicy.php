<?php

declare(strict_types=1);

namespace Modules\Governance\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\Governance\Models\GovAction;

/**
 * Every member reads the governance records of the company. Owners, administrators, accountants and members granted
 * `governance.manage` keep the register, the library, the audit plan and the calendar. The owner of a remediation action
 * works on it (starts, completes) even without the manage right. Accepting a risk, approving a plan, verifying an action
 * and closing a high or critical finding are decisions of owners, administrators and members granted `governance.approve`.
 */
final class GovernancePolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ManagerRoles = ['owner', 'admin', 'accountant'];

    /** @var list<string> */
    private const array ApproverRoles = ['owner', 'admin'];

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
        return $this->isManager($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->isManager($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->isManager($user);
    }

    /**
     * Moves a record through its state machine: a manager, or the owner of the action.
     */
    public function progress(User $user, Model $record): bool
    {
        if (! $this->isOwnRecord($record)) {
            return false;
        }

        return $this->isManager($user) || ($record instanceof GovAction && $record->owner_id === (string) $user->id);
    }

    /**
     * Management decisions: accepting a risk, approving a plan, verifying an action, closing a serious finding.
     */
    public function approve(User $user, ?Model $record = null): bool
    {
        return ($record === null || $this->isOwnRecord($record))
            && $this->hasRoleOrPermission($user, self::ApproverRoles, ['governance.approve']);
    }

    public function isManager(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['governance.manage']);
    }
}
