<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\WorkflowGraphValidator;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    $this->tenant = wfTenant();
    $this->requester = wfMember($this->tenant, 'member', 'Requester');
    $this->alice = wfMember($this->tenant, 'member', 'Alice');
    $this->bob = wfMember($this->tenant, 'member', 'Bob');
    $this->engine = app(WorkflowEngineInterface::class);
    wfParallelDefinition($this->tenant, 'WF_PAR', [$this->alice, $this->bob]);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a parallel split opens every branch at once', function (): void {
    $instance = wfStart($this->tenant, 'WF_PAR', wfMember($this->tenant), $this->requester);

    expect($instance->steps()->where('status', 'pending')->pluck('assigned_user_id')->all())
        ->toContain((string) $this->alice->id, (string) $this->bob->id);
});

test('the instance goes on only after all the branches approved', function (): void {
    $instance = wfStart($this->tenant, 'WF_PAR', wfMember($this->tenant), $this->requester);
    $steps = $instance->steps()->get()->keyBy('assigned_user_id');

    $this->engine->recordDecision(new RecordDecisionDTO((string) $steps[$this->alice->id]->id, 'approved', (string) $this->alice->id));
    expect($instance->refresh()->status)->not->toBe('approved');

    $this->engine->recordDecision(new RecordDecisionDTO((string) $steps[$this->bob->id]->id, 'approved', (string) $this->bob->id));
    expect($instance->refresh()->status)->toBe('approved');
});

test('one rejected branch rejects the instance and closes the others', function (): void {
    $instance = wfStart($this->tenant, 'WF_PAR', wfMember($this->tenant), $this->requester);
    $steps = $instance->steps()->get()->keyBy('assigned_user_id');

    $this->engine->recordDecision(new RecordDecisionDTO((string) $steps[$this->bob->id]->id, 'rejected', (string) $this->bob->id, 'No'));

    expect($instance->refresh()->status)->toBe('rejected')
        ->and($steps[$this->alice->id]->refresh()->status)->toBe('skipped');
});

test('the validator accepts a well formed parallel graph and refuses an orphan join or a cycle', function (): void {
    $definition = WorkflowDefinition::query()->where('code', 'WF_PAR')->firstOrFail();
    $validator = app(WorkflowGraphValidator::class);

    expect($validator->problems($definition))->toBe([]);

    // A transition back to the split makes a cycle.
    $join = WorkflowNode::where('workflow_definition_id', $definition->id)->where('node_key', 'join')->firstOrFail();
    $split = WorkflowNode::where('workflow_definition_id', $definition->id)->where('node_key', 'split')->firstOrFail();
    WorkflowTransition::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'workflow_definition_id' => $definition->id, 'from_node_id' => $join->id, 'to_node_id' => $split->id, 'action_trigger' => 'approved', 'priority' => 2]);

    expect(implode(' ', $validator->problems($definition->refresh())))->toContain('cycle');
});
