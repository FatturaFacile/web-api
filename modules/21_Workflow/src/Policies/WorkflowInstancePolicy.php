<?php

declare(strict_types=1);

namespace Modules\Workflow\Policies;

use App\Models\User;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

final class WorkflowInstancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowInstance $instance): bool
    {
        return (string) $user->tenant_id === (string) $instance->tenant_id;
    }

    public function cancel(User $user, WorkflowInstance $instance): bool
    {
        return (string) $user->tenant_id === (string) $instance->tenant_id
            && ((string) $instance->created_by === (string) $user->id || $user->hasRole('admin'));
    }

    public function decide(User $user, WorkflowInstanceStep $step): bool
    {
        if ((string) $user->tenant_id !== (string) $step->tenant_id) {
            return false;
        }

        // Direct assignee
        if ($step->assigned_user_id !== null && (string) $step->assigned_user_id === (string) $user->id) {
            return true;
        }

        // Assigned role
        if ($step->assigned_role !== null && $user->hasRole($step->assigned_role)) {
            return true;
        }

        // Active delegation
        if ($step->assigned_user_id !== null) {
            return $user->incomingDelegations()
                ->where('delegator_user_id', $step->assigned_user_id)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->exists();
        }

        return false;
    }
}
