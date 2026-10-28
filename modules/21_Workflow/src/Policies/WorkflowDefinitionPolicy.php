<?php

declare(strict_types=1);

namespace Modules\Workflow\Policies;

use App\Models\User;
use Modules\Workflow\Models\WorkflowDefinition;

final class WorkflowDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('workflows.view') || $user->hasRole('admin');
    }

    public function view(User $user, WorkflowDefinition $definition): bool
    {
        return ($user->hasPermissionTo('workflows.view') || $user->hasRole('admin'))
            && (string) $user->tenant_id === (string) $definition->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('workflows.manage') || $user->hasRole('admin');
    }

    public function update(User $user, WorkflowDefinition $definition): bool
    {
        return ($user->hasPermissionTo('workflows.manage') || $user->hasRole('admin'))
            && (string) $user->tenant_id === (string) $definition->tenant_id;
    }

    public function delete(User $user, WorkflowDefinition $definition): bool
    {
        return ($user->hasPermissionTo('workflows.manage') || $user->hasRole('admin'))
            && (string) $user->tenant_id === (string) $definition->tenant_id;
    }
}
