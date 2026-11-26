<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

/*
|--------------------------------------------------------------------------
| Shared fixtures of the approval engine feature tests (Mod 006)
|--------------------------------------------------------------------------
|
| Plain functions, loaded with require_once by every test file of the module, so that a test reads as the scenario it
| describes: a tenant, members with roles, a one-node workflow and an instance of it.
|
*/

/**
 * A tenant, made the active one.
 */
function wfTenant(string $name = 'Approvazioni AG', string $currency = 'CHF', string $country = 'CH'): Tenant
{
    $tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => $name,
        'legal_name' => $name,
        'country' => $country,
        'default_currency' => $currency,
        'status' => 'active',
    ]);

    app(TenantContext::class)->setTenant($tenant);

    return $tenant;
}

/**
 * A member of the tenant with a role.
 */
function wfMember(Tenant $tenant, string $role = 'member', ?string $name = null): User
{
    $user = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'name' => $name ?? ucfirst($role).' '.Str::random(4),
        'email' => Str::lower(Str::random(10)).'@approvals.test',
        'password' => bcrypt('Secret-passw0rd!'),
    ]);

    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * start -> approval -> end. The approval node takes the given attributes (assignee, voting rule, timeout...).
 *
 * @param  array<string, mixed>  $approval
 * @param  array<string, mixed>  $configuration
 */
function wfDefinition(Tenant $tenant, string $code, array $approval, array $configuration = [], string $target = 'purchase_order'): WorkflowDefinition
{
    $definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'code' => $code,
        'name' => $code,
        'target_entity_type' => $target,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
        'configuration' => $configuration,
        'published_at' => now(),
    ]);

    $node = static fn (string $key, string $type, array $extra = []): WorkflowNode => WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'workflow_definition_id' => $definition->id,
        'node_key' => $key,
        'name' => ucfirst($key),
        'node_type' => $type,
        ...$extra,
    ]);

    $start = $node('start', 'start');
    $step = $node('approval', 'approval', ['approval_type' => 'single', 'timeout_hours' => 48, ...$approval]);
    $end = $node('end', 'end');

    foreach ([[$start, $step, 'start'], [$step, $end, 'approved']] as [$from, $to, $trigger]) {
        WorkflowTransition::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'workflow_definition_id' => $definition->id,
            'from_node_id' => $from->id,
            'to_node_id' => $to->id,
            'action_trigger' => $trigger,
            'priority' => 1,
        ]);
    }

    return $definition;
}

/**
 * Starts an instance of a workflow for a document.
 *
 * @param  array<string, mixed>  $context
 */
function wfStart(Tenant $tenant, string $code, Model $approvable, ?User $initiator = null, array $context = []): WorkflowInstance
{
    return app(WorkflowEngineInterface::class)->start(new StartWorkflowDTO(
        workflowCode: $code,
        approvable: $approvable,
        contextSnapshot: $context,
        tenantId: (string) $tenant->id,
        companyId: null,
        initiatorUserId: $initiator !== null ? (string) $initiator->id : null,
    ));
}

/**
 * start -> parallel split -> (two approval branches) -> parallel join -> end.
 *
 * @param  list<User>  $approvers  one approver per branch
 */
function wfParallelDefinition(Tenant $tenant, string $code, array $approvers): WorkflowDefinition
{
    $definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'code' => $code, 'name' => $code, 'target_entity_type' => 'purchase_order',
        'version' => 1, 'status' => 'published', 'is_active' => true, 'configuration' => [], 'published_at' => now(),
    ]);
    $node = static fn (string $key, string $type, array $extra = []): WorkflowNode => WorkflowNode::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'node_key' => $key, 'name' => ucfirst($key), 'node_type' => $type, ...$extra,
    ]);
    $link = static fn (WorkflowNode $from, WorkflowNode $to, string $trigger): WorkflowTransition => WorkflowTransition::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'workflow_definition_id' => $definition->id, 'from_node_id' => $from->id, 'to_node_id' => $to->id, 'action_trigger' => $trigger, 'priority' => 1,
    ]);

    $start = $node('start', 'start');
    $split = $node('split', 'parallel_split');
    $join = $node('join', 'parallel_join');
    $end = $node('end', 'end');
    $link($start, $split, 'start');

    foreach ($approvers as $i => $approver) {
        $branch = $node('branch_'.$i, 'approval', ['approval_type' => 'single', 'assignee_type' => 'direct_user', 'assignee_id' => (string) $approver->id, 'timeout_hours' => 48]);
        $link($split, $branch, 'branch');
        $link($branch, $join, 'approved');
    }

    $link($join, $end, 'approved');

    return $definition;
}
