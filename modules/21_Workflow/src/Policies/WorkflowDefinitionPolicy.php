<?php

declare(strict_types=1);

namespace Modules\Workflow\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Workflow\Models\WorkflowDefinition;

/**
 * Roles and permissions come from the membership of the active tenant (tenant_user), never from global roles: the
 * administrator of one company must not manage the workflows of another. Owners and administrators manage them; a
 * member may be granted "workflows.view" or "workflows.manage" on the membership.
 */
final class WorkflowDefinitionPolicy
{
    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, ['workflows.view', 'workflows.manage']);
    }

    public function view(User $user, WorkflowDefinition $definition): bool
    {
        return $this->viewAny($user) && $this->isOwn($definition);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, ['workflows.manage']);
    }

    public function update(User $user, WorkflowDefinition $definition): bool
    {
        return $this->create($user) && $this->isOwn($definition);
    }

    public function delete(User $user, WorkflowDefinition $definition): bool
    {
        return $this->create($user) && $this->isOwn($definition);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holds(User $user, array $permissions): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        $pivot = $tenantId === null ? null : $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if ($pivot === null) {
            return false;
        }

        if (in_array($pivot->getAttribute('role'), self::ManagerRoles, true)) {
            return true;
        }

        $granted = $pivot->getAttribute('permissions');
        $granted = is_string($granted) ? json_decode($granted, true) : $granted;

        return is_array($granted) && array_intersect(['*', ...$permissions], $granted) !== [];
    }

    private function isOwn(WorkflowDefinition $definition): bool
    {
        return (string) $definition->tenant_id === (string) $this->tenantContext->getTenantId();
    }
}
