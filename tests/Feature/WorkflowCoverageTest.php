<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\WorkflowEngineService;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['name' => 'tenant_wf_coverage'],
        [
            'legal_name' => 'Enterprise Workflow Coverage Corp',
            'fiscal_code' => 'IT55443322110',
            'vat_number' => 'IT55443322110',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );

    app(TenantContext::class)->setTenant($this->tenant);

    $this->approver = User::firstOrCreate(
        ['email' => 'approver_coverage@workflow.test'],
        [
            'name' => 'Coverage Approver',
            'password' => bcrypt('password'),
        ]
    );

    $code = 'WF_COV_'.Str::upper(Str::random(6));

    $this->definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'code' => $code,
        'name' => 'Multi-Tier Enterprise Approval Process',
        'target_entity_type' => Tenant::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);

    $this->startNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'start',
        'name' => 'Initiation Node',
        'node_type' => 'start',
    ]);

    $this->approvalNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'executive_approval',
        'name' => 'Executive Approval Node',
        'node_type' => 'approval',
        'approval_type' => 'single',
        'assignee_type' => 'direct_user',
        'assignee_id' => $this->approver->id,
        'timeout_hours' => 48,
    ]);

    $this->endNode = WorkflowNode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'node_key' => 'end',
        'name' => 'Workflow Completed',
        'node_type' => 'end',
    ]);

    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $this->startNode->id,
        'to_node_id' => $this->approvalNode->id,
        'action_trigger' => 'start',
    ]);

    WorkflowTransition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'from_node_id' => $this->approvalNode->id,
        'to_node_id' => $this->endNode->id,
        'action_trigger' => 'approved',
    ]);
});

test('Workflow Engine: executes end-to-end lifecycle from start to approval completion', function () {
    $engine = app(WorkflowEngineService::class);

    // 1. Start Workflow
    $instance = $engine->start(new StartWorkflowDTO(
        workflowCode: $this->definition->code,
        approvable: $this->tenant,
        initiatorUserId: $this->approver->id,
        tenantId: $this->tenant->id
    ));

    expect($instance)->toBeInstanceOf(WorkflowInstance::class)
        ->and($instance->status)->toBe('pending')
        ->and($instance->current_node_id)->toBe($this->approvalNode->id);

    $step = $instance->steps()->where('status', 'pending')->first();
    expect($step)->not->toBeNull()
        ->and($step->assigned_user_id)->toBe($this->approver->id);

    // 2. Record Decision
    $updatedInstance = $engine->recordDecision(new RecordDecisionDTO(
        workflowInstanceStepId: $step->id,
        decision: 'approved',
        userId: $this->approver->id,
        comment: 'Certified and approved according to enterprise guidelines.'
    ));

    $instance->refresh();
    $step->refresh();

    expect($step->status)->toBe('approved')
        ->and($instance->status)->toBe('approved')
        ->and($instance->completed_at)->not->toBeNull();
});
