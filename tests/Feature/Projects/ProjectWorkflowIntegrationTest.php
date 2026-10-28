<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Events\ProjectClosedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Listeners\WorkflowApprovalProjectListener;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Project Workflow Tenant',
        'legal_name' => 'Project Workflow Enterprise SpA',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Project Director & Governance Lead',
        'email' => 'director@project-workflow.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->contact = Contact::where('tenant_id', $this->tenant->id)->first() ?? Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'type' => 'customer',
        'country' => 'IT',
        'company_name' => 'Client Infrastructure Holding',
        'email' => 'client@holding.it',
        'vat_number' => 'IT10293847561',
    ]);

    $this->project = Project::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'contract_value' => 300000.00,
    ]);

    $this->budgetDefinition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'code' => 'PROJECT_BUDGET_APPROVAL',
        'name' => 'Project Budget Approval Governance',
        'target_entity_type' => ProjectBudget::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);

    $this->changeOrderDefinition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'code' => 'PROJECT_CHANGE_REQUEST_APPROVAL',
        'name' => 'Project Scope Change Request Approval',
        'target_entity_type' => ProjectChangeOrder::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);
});

test('high value budget over 10k triggers workflow engine and enters pending approval', function (): void {
    $budgetService = app(ProjectBudgetServiceInterface::class);

    $budgetDto = new ProjectBudgetDTO(
        projectId: $this->project->id,
        totalRevenueBudget: 300000.00,
        totalCostBudget: 220000.00, // > €10,000 threshold
        labourBudget: 100000.00,
        materialBudget: 80000.00,
        supplierBudget: 40000.00,
        userId: $this->user->id
    );

    $budget = $budgetService->createOrUpdateBudget($budgetDto);

    expect($budget)->toBeInstanceOf(ProjectBudget::class)
        ->and($budget->status)->toBe('pending_approval');
});

test('budget workflow completion listener baseline freezes and approves budget', function (): void {
    $budget = ProjectBudget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $this->project->id,
        'status' => 'pending_approval',
        'total_cost_budget' => 50000.00,
        'total_revenue_budget' => 70000.00,
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->budgetDefinition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => ProjectBudget::class,
        'approvable_id' => $budget->id,
        'status' => 'completed',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = app(WorkflowApprovalProjectListener::class);
    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) $instance->id,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleCompleted($event);

    $budget->refresh();
    expect($budget->status)->toBe('approved')
        ->and($budget->current_version_number)->toBeGreaterThanOrEqual(1);
});

test('budget workflow rejection listener marks budget as superseded', function (): void {
    $budget = ProjectBudget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $this->project->id,
        'status' => 'pending_approval',
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->budgetDefinition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => ProjectBudget::class,
        'approvable_id' => $budget->id,
        'status' => 'rejected',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = app(WorkflowApprovalProjectListener::class);
    $event = new WorkflowInstanceRejectedEvent(
        instanceId: (string) $instance->id,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleRejected($event);

    $budget->refresh();
    expect($budget->status)->toBe('superseded');
});

test('change order workflow approval updates status to customer approved and adjusts contract value', function (): void {
    $changeOrder = ProjectChangeOrder::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $this->project->id,
        'order_number' => 'CO-WF-001',
        'title' => 'Structural steel frame reinforcement',
        'scope_description' => 'Load bearing modification',
        'cost_impact_amount' => 20000.00,
        'revenue_impact_amount' => 35000.00,
        'schedule_impact_days' => 14,
        'status' => 'pending_approval',
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->changeOrderDefinition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => ProjectChangeOrder::class,
        'approvable_id' => $changeOrder->id,
        'status' => 'completed',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = app(WorkflowApprovalProjectListener::class);
    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) $instance->id,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleCompleted($event);

    $changeOrder->refresh();
    expect($changeOrder->status)->toBe('customer_approved');

    $this->project->refresh();
    expect((float) $this->project->contract_value)->toBe(335000.00); // 300k + 35k
});

test('project closure workflow approval marks project closed and emits accounting readiness events', function (): void {
    Event::fake([
        ProjectClosedEvent::class,
        ProjectClosureReadyEvent::class,
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->budgetDefinition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => Project::class,
        'approvable_id' => $this->project->id,
        'status' => 'completed',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = app(WorkflowApprovalProjectListener::class);
    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) $instance->id,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleCompleted($event);

    $this->project->refresh();
    expect($this->project->status)->toBe('closed')
        ->and($this->project->actual_end_date)->not->toBeNull();

    Event::assertDispatched(ProjectClosedEvent::class);
    Event::assertDispatched(ProjectClosureReadyEvent::class);
});
