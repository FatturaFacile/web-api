<?php

declare(strict_types=1);

namespace Modules\Workflow\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Workflow\Models\WorkflowDelegation;
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
            && ((string) $instance->created_by === (string) $user->id || $this->holdsRole($user, (string) $instance->tenant_id, 'admin'));
    }

    /**
     * The audit trail shows every decision and comment: the initiator, the approvers of the instance and the owners and
     * administrators of the tenant read it.
     */
    public function viewAuditTrail(User $user, WorkflowInstance $instance): bool
    {
        if ((string) $user->tenant_id !== (string) $instance->tenant_id) {
            return false;
        }

        if ((string) $instance->created_by === (string) $user->id || $this->holdsRole($user, (string) $instance->tenant_id, 'admin')) {
            return true;
        }

        return $instance->steps()->where('assigned_user_id', $user->id)->exists()
            || $instance->steps()->whereHas('decisions', static fn ($query) => $query->where('user_id', $user->id)->orWhere('effective_user_id', $user->id))->exists();
    }

    /**
     * The SLA report compares how fast people decide: owners and administrators of the company only.
     */
    public function viewSla(User $user): bool
    {
        return in_array($user->getRoleInTenant((string) app(TenantContext::class)->getTenantId()), ['owner', 'admin'], true);
    }

    /**
     * Roles live on the tenant membership. Owners and administrators hold every role, as in the engine.
     */
    private function holdsRole(User $user, string $tenantId, string $role): bool
    {
        return in_array($user->getRoleInTenant($tenantId), [$role, 'owner', 'admin'], true);
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
        if ($step->assigned_role !== null && $this->holdsRole($user, (string) $step->tenant_id, (string) $step->assigned_role)) {
            return true;
        }

        // Active delegation
        if ($step->assigned_user_id !== null) {
            $approvableType = (string) $step->instance?->approvable_type;

            return WorkflowDelegation::query()
                ->where('delegate_user_id', $user->id)
                ->where('delegator_user_id', $step->assigned_user_id)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->get()
                ->contains(static fn ($delegation): bool => $delegation->coversApprovable($approvableType));
        }

        return false;
    }
}
