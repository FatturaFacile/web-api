<?php

declare(strict_types=1);

namespace Modules\Workflow\Policies;

use App\Models\User;
use Modules\Workflow\Models\WorkflowDelegation;

final class WorkflowDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowDelegation $delegation): bool
    {
        return (string) $user->tenant_id === (string) $delegation->tenant_id
            && ((string) $delegation->delegator_user_id === (string) $user->id || (string) $delegation->delegate_user_id === (string) $user->id);
    }

    public function delete(User $user, WorkflowDelegation $delegation): bool
    {
        return (string) $user->tenant_id === (string) $delegation->tenant_id
            && (string) $delegation->delegator_user_id === (string) $user->id;
    }
}
