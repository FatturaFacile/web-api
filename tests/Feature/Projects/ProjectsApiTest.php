<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array<string, mixed>
 */
function projectsPayload(object $test, array $overrides = []): array
{
    return [
        'contact_id' => $test->contact->id,
        'project_number' => 'PRJ-'.Str::upper(Str::random(6)),
        'name' => 'Nuovo impianto',
        'project_type' => 'construction',
        'billing_method' => 'milestone_based',
        'revenue_recognition_method' => 'percentage_of_completion',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addMonths(3)->toDateString(),
        'project_manager_user_id' => $test->manager->id,
        'contract_value' => 50000,
        'milestones' => [['name' => 'Consegna', 'milestone_date' => now()->addMonth()->toDateString(), 'billing_amount' => 20000]],
        ...$overrides,
    ];
}

test('a manager creates, reads, lists, updates and deletes a project', function (): void {
    $created = $this->postJson('/api/v1/projects', projectsPayload($this))->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.contract_value', '50000.00')
        ->assertJsonPath('data.revenue_recognition_method', 'percentage_of_completion');
    $id = $created->json('data.id');

    $this->getJson("/api/v1/projects/{$id}")->assertOk()->assertJsonCount(1, 'data.milestones');
    $this->getJson('/api/v1/projects?status=draft&per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100)->assertJsonPath('meta.total', 1);
    $this->putJson("/api/v1/projects/{$id}", ['name' => 'Impianto rinominato', 'contract_value' => 60000])->assertOk()->assertJsonPath('data.contract_value', '60000.00');
    $this->getJson("/api/v1/projects/{$id}/audit-trail")->assertOk()->assertJsonPath('meta.total', 2);
    $this->deleteJson("/api/v1/projects/{$id}")->assertOk();
    $this->getJson("/api/v1/projects/{$id}")->assertNotFound();
});

test('project creation validates its input and refuses duplicates and foreign references', function (): void {
    $this->postJson('/api/v1/projects', [])->assertUnprocessable()->assertJsonValidationErrors(['contact_id', 'project_number', 'name', 'contract_value']);
    $this->postJson('/api/v1/projects', projectsPayload($this, ['planned_end_date' => now()->subDay()->toDateString(), 'revenue_recognition_method' => 'invented']))
        ->assertUnprocessable()->assertJsonValidationErrors(['planned_end_date', 'revenue_recognition_method']);

    $foreignUser = projectsUser($this, 'owner', $this->otherTenant);
    $this->postJson('/api/v1/projects', projectsPayload($this, ['project_manager_user_id' => $foreignUser->id]))->assertUnprocessable()->assertJsonValidationErrors(['project_manager_user_id']);

    $this->postJson('/api/v1/projects', projectsPayload($this, ['project_number' => 'DUP-1']))->assertCreated();
    $this->postJson('/api/v1/projects', projectsPayload($this, ['project_number' => 'DUP-1']))->assertUnprocessable()->assertJsonValidationErrors(['project_number']);
});

test('read-only and plain members cannot manage projects, other companies cannot see them', function (): void {
    $project = projectsProject($this);

    projectsActAs($this, $this->reader);
    $this->getJson("/api/v1/projects/{$project->id}")->assertOk();
    $this->postJson('/api/v1/projects', projectsPayload($this))->assertForbidden();
    $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 2])->assertForbidden();

    projectsActAs($this, $this->member);
    $this->postJson('/api/v1/projects', projectsPayload($this))->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/pause")->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/budget", ['total_revenue_budget' => 1000])->assertForbidden();

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    $this->getJson("/api/v1/projects/{$project->id}/gantt")->assertNotFound();
    $this->postJson("/api/v1/projects/{$project->id}/close")->assertNotFound();
    $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
});

test('the lifecycle moves through its statuses and refuses invalid transitions', function (): void {
    $project = projectsProject($this, ['status' => 'draft']);
    projectsActAs($this, $this->manager);

    $this->postJson("/api/v1/projects/{$project->id}/pause")->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/start")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/projects/{$project->id}/pause", ['reason' => 'Attesa permessi'])->assertOk()->assertJsonPath('data.status', 'on_hold');
    $this->postJson("/api/v1/projects/{$project->id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
    $this->putJson("/api/v1/projects/{$project->id}", ['contract_value' => 1])->assertUnprocessable()->assertJsonValidationErrors(['contract_value']);
    $this->postJson("/api/v1/projects/{$project->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress_percentage', '100.00');
    $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
});

test('tasks, dependencies, assignments, critical path, gantt and schedule baseline', function (): void {
    $project = projectsProject($this, ['planned_start_date' => '2026-01-05', 'planned_end_date' => '2026-03-31']);
    projectsActAs($this, $this->manager);
    $start = now()->toDateString();

    $a = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['task_number' => 'T1', 'title' => 'Scavo', 'planned_start_date' => '2026-01-05', 'planned_end_date' => '2026-01-09', 'estimated_hours' => 40])->assertCreated()->json('data.id');
    $b = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['task_number' => 'T2', 'title' => 'Fondazioni', 'planned_start_date' => '2026-01-12', 'planned_end_date' => '2026-01-16', 'estimated_hours' => 40])->assertCreated()->json('data.id');
    $c = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['task_number' => 'T3', 'title' => 'Impianti', 'planned_start_date' => '2026-01-10', 'planned_end_date' => '2026-01-11', 'estimated_hours' => 8])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/tasks", ['task_number' => 'T4', 'title' => 'x', 'planned_start_date' => $start, 'planned_end_date' => now()->subDay()->toDateString()])->assertUnprocessable();

    $this->postJson("/api/v1/projects/tasks/{$b}/dependencies", ['predecessor_task_id' => $a])->assertCreated()->assertJsonPath('data.dependency_type', 'finish_to_start');
    $dependency = $this->postJson("/api/v1/projects/tasks/{$c}/dependencies", ['predecessor_task_id' => $a, 'lag_days' => 0])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/tasks/{$a}/dependencies", ['predecessor_task_id' => $b])->assertUnprocessable()->assertJsonValidationErrors(['predecessor_task_id']);
    $this->postJson("/api/v1/projects/tasks/{$a}/dependencies", ['predecessor_task_id' => $a])->assertUnprocessable();

    $path = $this->getJson("/api/v1/projects/{$project->id}/critical-path")->assertOk()->json('data');
    expect($path['critical_path'])->toBe([$a, $b])
        ->and($path['duration_days'])->toBe(10)
        ->and($path['tasks'][$c]['total_float_days'])->toBe(3)
        ->and($path['tasks'][$b]['early_start'])->toBe('2026-01-10');

    $assignment = $this->postJson("/api/v1/projects/tasks/{$a}/assignments", ['user_id' => $this->member->id, 'allocated_hours' => 30])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/schedule/baseline")->assertOk()->assertJsonPath('data.baseline_start_date', '2026-01-05');
    $this->putJson("/api/v1/projects/tasks/{$b}", ['planned_start_date' => '2026-01-14', 'planned_end_date' => '2026-01-20'])->assertOk();

    $gantt = $this->getJson("/api/v1/projects/{$project->id}/gantt")->assertOk()->json('data');
    $taskB = collect($gantt['tasks'])->firstWhere('id', $b);
    expect($taskB['baseline_start_date'])->toBe('2026-01-12')->and($taskB['start_variance_days'])->toBe(2)->and($taskB['finish_variance_days'])->toBe(4)->and($taskB['dependencies'])->toHaveCount(1);
    $this->getJson("/api/v1/projects/{$project->id}/tasks")->assertOk()->assertJsonPath('meta.total', 3);

    projectsActAs($this, $this->member);
    $this->putJson("/api/v1/projects/tasks/{$a}", ['status' => 'completed'])->assertOk()->assertJsonPath('data.progress_percentage', '100.00');
    $this->putJson("/api/v1/projects/tasks/{$b}", ['status' => 'in_progress'])->assertForbidden();
    $this->deleteJson("/api/v1/projects/tasks/{$c}")->assertForbidden();

    projectsActAs($this, $this->manager);
    $this->deleteJson("/api/v1/projects/task-dependencies/{$dependency}")->assertOk();
    $this->deleteJson("/api/v1/projects/task-assignments/{$assignment}")->assertOk();
    $this->deleteJson("/api/v1/projects/tasks/{$c}")->assertOk();
    expect(ProjectTask::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('milestones are planned, completed and billed once into a draft invoice', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);

    $milestone = $this->postJson("/api/v1/projects/{$project->id}/milestones", ['name' => 'SAL 1', 'milestone_date' => now()->toDateString(), 'billing_percentage' => 30])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/projects/milestones/{$milestone}", ['name' => 'SAL 1 - strutture'])->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone}", ['tax_rate' => 22])->assertConflict();
    $this->postJson("/api/v1/projects/milestones/{$milestone}/complete")->assertOk()->assertJsonPath('data.is_completed', true);
    $this->postJson("/api/v1/projects/milestones/{$milestone}/complete")->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone}", [])->assertUnprocessable()->assertJsonValidationErrors(['tax_rate']);

    $invoice = $this->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone}", ['tax_rate' => 22])->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_net', '30000.00')->assertJsonPath('data.project_id', $project->id)->json('data');
    expect($invoice['invoice_number'])->toStartWith('DRAFT-');

    $this->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone}", ['tax_rate' => 22])->assertConflict();
    $this->putJson("/api/v1/projects/milestones/{$milestone}", ['billing_amount' => 1])->assertConflict();
    $this->getJson("/api/v1/projects/{$project->id}/billing-events")->assertOk()->assertJsonPath('data.0.event_type', 'milestone')->assertJsonPath('data.0.billing_amount', '30000.00');
    $this->getJson("/api/v1/projects/{$project->id}/milestones")->assertOk()->assertJsonPath('data.0.is_billed', true);
});

test('the budget is saved as draft, approved into a baseline and reported by category', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);

    $this->postJson("/api/v1/projects/{$project->id}/budget", ['total_revenue_budget' => 100000, 'labour_budget' => 30000, 'material_budget' => 20000, 'supplier_budget' => 10000, 'contingency_budget' => 5000, 'lines' => [['cost_category' => 'labour', 'description' => 'Tecnici', 'planned_quantity' => 500, 'planned_unit_cost' => 60]]])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_cost_budget', '65000.00')->assertJsonPath('data.projected_margin_amount', '35000.00')->assertJsonPath('data.lines.0.planned_total_cost', '30000.00');
    $this->postJson("/api/v1/projects/{$project->id}/budget/baseline")->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/budget/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.current_version_number', 1);
    $this->postJson("/api/v1/projects/{$project->id}/budget/approve")->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/budget/baseline")->assertOk()->assertJsonPath('data.current_version_number', 2);

    $this->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'material', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 10, 'unit_cost' => 150, 'incurred_date' => now()->toDateString()])->assertCreated()->assertJsonPath('data.total_cost', '1500.00');

    $report = $this->getJson("/api/v1/projects/{$project->id}/budget")->assertOk()->json('data.category_report');
    $material = collect($report['categories'])->firstWhere('category', 'material');
    expect($material['actual'])->toBe('1500.00')->and($material['available'])->toBe('18500.00')->and($report['totals']['budget'])->toBe('65000.00');

    $this->postJson("/api/v1/projects/{$project->id}/budget", ['total_revenue_budget' => 100000, 'labour_budget' => 40000])->assertCreated()->assertJsonPath('data.status', 'draft');
    $this->postJson("/api/v1/projects/{$project->id}/budget/reject", [])->assertUnprocessable();
    $this->postJson("/api/v1/projects/{$project->id}/budget/reject", ['reason' => 'Manodopera sovrastimata'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/projects/{$project->id}/budget", ['total_revenue_budget' => -1])->assertUnprocessable();
});

test('manual costs validate their allocations and a source document is booked once', function (): void {
    $project = projectsProject($this);
    $task = ProjectTask::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    $otherTask = ProjectTask::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id]);
    projectsActAs($this, $this->manager);
    $source = (string) Str::uuid();
    $payload = ['cost_category' => 'supplier', 'cost_source_type' => 'manual_document', 'cost_source_id' => $source, 'quantity' => 1, 'unit_cost' => 1000, 'incurred_date' => now()->toDateString()];

    $this->postJson("/api/v1/projects/{$project->id}/costs", [...$payload, 'allocations' => [['task_id' => $task->id, 'percentage' => 50], ['task_id' => $otherTask->id, 'percentage' => 40]]])->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
    $first = $this->postJson("/api/v1/projects/{$project->id}/costs", [...$payload, 'allocations' => [['task_id' => $task->id, 'percentage' => 33.33], ['task_id' => $otherTask->id, 'percentage' => 66.67]]])->assertCreated();
    expect(collect($first->json('data.allocations'))->sum(fn (array $a) => (float) $a['allocated_amount']))->toBe(1000.0);
    $this->postJson("/api/v1/projects/{$project->id}/costs", $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson("/api/v1/projects/{$project->id}/costs", [...$payload, 'cost_source_id' => (string) Str::uuid(), 'cost_source_type' => 'Timesheet'])->assertUnprocessable();
    $this->getJson("/api/v1/projects/{$project->id}/costs?cost_category=supplier")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/projects/{$project->id}/financial-summary")->assertOk()->assertJsonPath('data.actual_cost.supplier', '1000.00');

    projectsActAs($this, $this->member);
    $this->postJson("/api/v1/projects/{$project->id}/costs", [...$payload, 'cost_source_id' => (string) Str::uuid()])->assertForbidden();
});

test('the timesheet flow: draft, submit, approve with labour cost, rejection and lock', function (): void {
    $project = projectsProject($this);
    projectsRateCard($this, $this->member, '40.00', '90.00');
    projectsActAs($this, $this->member);
    $today = now()->toDateString();

    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => $today, 'hours_worked' => 8, 'user_id' => $this->owner->id])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => $today, 'hours_worked' => 8, 'custom_cost_rate' => 1, 'custom_charge_rate' => 1])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => $today, 'hours_worked' => 25])->assertUnprocessable();

    $sheet = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => $today, 'hours_worked' => 7.5, 'overtime_hours' => 1.5])->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_cost', '300.00')->assertJsonPath('data.total_charge', '675.00')->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => $today, 'hours_worked' => 17])->assertUnprocessable()->assertJsonValidationErrors(['hours_worked']);
    $this->putJson("/api/v1/projects/timesheets/{$sheet}", ['hours_worked' => 8])->assertOk()->assertJsonPath('data.total_cost', '320.00');
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/approve")->assertForbidden();
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/reject", ['reason' => 'Manca il dettaglio'])->assertOk()->assertJsonPath('data.status', 'rejected');

    projectsActAs($this, $this->member);
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/submit")->assertOk();

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_locked', true);
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/approve")->assertConflict();
    $this->getJson("/api/v1/projects/{$project->id}/costs?cost_category=labour")->assertOk()->assertJsonPath('data.0.total_cost', '320.00')->assertJsonPath('data.0.cost_source_id', $sheet);

    projectsActAs($this, $this->member);
    $this->putJson("/api/v1/projects/timesheets/{$sheet}", ['hours_worked' => 1])->assertConflict();
    $this->deleteJson("/api/v1/projects/timesheets/{$sheet}")->assertConflict();
    $draft = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->subDay()->toDateString(), 'hours_worked' => 2])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/projects/timesheets/{$draft}")->assertOk();
    $this->getJson("/api/v1/projects/{$project->id}/timesheets?status=approved")->assertOk()->assertJsonPath('meta.total', 1);
});

test('nobody approves their own hours except owners and admins, and time needs a rate', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 4, 'submit' => true])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);

    projectsRateCard($this, $this->manager);
    $own = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 4, 'submit' => true])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/timesheets/{$own}/approve")->assertForbidden();

    projectsActAs($this, $this->owner);
    projectsRateCard($this, $this->owner);
    $ownerSheet = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 4, 'submit' => true])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/timesheets/{$ownerSheet}/approve")->assertOk();
    $this->postJson("/api/v1/projects/timesheets/{$own}/approve")->assertOk();
});

test('expenses wait for approval and only approved ones count', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->member);

    $expense = $this->postJson("/api/v1/projects/{$project->id}/expenses", ['expense_category' => 'travel_lodging', 'description' => 'Trasferta cantiere', 'amount' => 245.5, 'incurred_date' => now()->toDateString()])
        ->assertCreated()->assertJsonPath('data.approval_status', 'pending')->assertJsonPath('data.cost_category', 'expense')->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/expenses", ['expense_category' => 'yacht', 'description' => 'x', 'amount' => 1, 'incurred_date' => now()->toDateString()])->assertUnprocessable();
    $this->postJson("/api/v1/projects/expenses/{$expense}/approve")->assertForbidden();
    $this->getJson("/api/v1/projects/{$project->id}/profitability")->assertOk()->assertJsonPath('data.cost.actual', '0.00');

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/expenses/{$expense}/approve")->assertOk()->assertJsonPath('data.approval_status', 'approved');
    $this->postJson("/api/v1/projects/expenses/{$expense}/approve")->assertConflict();
    $this->getJson("/api/v1/projects/{$project->id}/financial-summary")->assertOk()->assertJsonPath('data.actual_cost.expense', '245.50');
    expect(projectsLedgerBalance($this->tenant->id, $project->id, '5200'))->toBe('245.50');

    $rejected = $this->postJson("/api/v1/projects/{$project->id}/expenses", ['expense_category' => 'meals_subsistence', 'description' => 'Cena', 'amount' => 80, 'incurred_date' => now()->toDateString()])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/expenses/{$rejected}/reject", ['reason' => 'Non rimborsabile'])->assertOk()->assertJsonPath('data.approval_status', 'rejected');
    $this->getJson("/api/v1/projects/{$project->id}/profitability")->assertOk()->assertJsonPath('data.cost.actual', '245.50');
});

test('change orders move contract value, budget and schedule once approved', function (): void {
    $project = projectsProject($this, ['planned_end_date' => '2027-01-31']);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '60000.00', 'total_revenue_budget' => '100000.00', 'labour_budget' => '30000.00', 'supplier_budget' => '30000.00', 'material_budget' => '0.00', 'fleet_budget' => '0.00', 'expense_budget' => '0.00', 'contingency_budget' => '0.00']);
    projectsActAs($this, $this->manager);
    $lines = [['cost_category' => 'supplier', 'description' => 'Consolidamento', 'quantity_delta' => 1, 'unit_cost_delta' => 8000, 'total_cost_delta' => 8000, 'revenue_delta' => 12000]];

    $this->postJson("/api/v1/projects/{$project->id}/change-orders", ['order_number' => 'CO-1', 'title' => 'Variante', 'scope_description' => 'Opere extra', 'cost_impact_amount' => 9000, 'revenue_impact_amount' => 12000, 'lines' => $lines])->assertUnprocessable()->assertJsonValidationErrors(['cost_impact_amount']);
    $order = $this->postJson("/api/v1/projects/{$project->id}/change-orders", ['order_number' => 'CO-1', 'title' => 'Variante', 'scope_description' => 'Opere extra', 'cost_impact_amount' => 8000, 'revenue_impact_amount' => 12000, 'schedule_impact_days' => 10, 'lines' => $lines])
        ->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/change-orders", ['order_number' => 'CO-1', 'title' => 'Doppia', 'scope_description' => 'x', 'cost_impact_amount' => 1])->assertUnprocessable()->assertJsonValidationErrors(['order_number']);

    projectsActAs($this, $this->member);
    $this->postJson("/api/v1/projects/change-orders/{$order}/approve")->assertForbidden();

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/change-orders/{$order}/approve")->assertOk()->assertJsonPath('data.status', 'customer_approved');
    $this->postJson("/api/v1/projects/change-orders/{$order}/approve")->assertConflict();

    $project->refresh();
    $budget = ProjectBudget::query()->where('project_id', $project->id)->firstOrFail();
    expect($project->contract_value)->toBe('112000.00')
        ->and($project->planned_end_date?->toDateString())->toBe('2027-02-10')
        ->and($budget->total_cost_budget)->toBe('68000.00')
        ->and($budget->supplier_budget)->toBe('38000.00')
        ->and($budget->total_revenue_budget)->toBe('112000.00');

    $second = $this->postJson("/api/v1/projects/{$project->id}/change-orders", ['order_number' => 'CO-2', 'title' => 'Riduzione', 'scope_description' => 'Meno opere', 'cost_impact_amount' => -2000])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/change-orders/{$second}/reject", ['reason' => 'Cliente contrario'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/projects/change-orders/{$second}/approve")->assertConflict();
    $this->getJson("/api/v1/projects/{$project->id}/change-orders?status=customer_approved")->assertOk()->assertJsonPath('meta.total', 1);
    expect(ProjectChangeOrder::query()->count())->toBe(2);
});

test('rate cards, person resources and weekly capacity with over-allocation', function (): void {
    $project = projectsProject($this);
    $this->putJson("/api/v1/projects/resources/{$this->member->id}", ['internal_hourly_cost' => 35, 'billing_hourly_rate' => 70, 'weekly_capacity_hours' => 20])->assertOk()->assertJsonPath('data.weekly_capacity_hours', '20.00');
    $foreign = projectsUser($this, 'member', $this->otherTenant);
    $this->putJson("/api/v1/projects/resources/{$foreign->id}", ['internal_hourly_cost' => 1, 'billing_hourly_rate' => 1])->assertNotFound();
    $this->getJson('/api/v1/projects/resources')->assertOk()->assertJsonPath('meta.total', 1);

    $card = $this->postJson('/api/v1/projects/rate-cards', ['role' => 'senior', 'cost_rate' => 50, 'bill_rate' => 110, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/projects/rate-cards', ['role' => 'senior', 'cost_rate' => 55, 'bill_rate' => 120, 'valid_from' => '2026-06-01'])->assertUnprocessable();
    $this->postJson('/api/v1/projects/rate-cards', ['cost_rate' => 1, 'bill_rate' => 1])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'role']);
    $this->postJson('/api/v1/projects/rate-cards', ['project_id' => $project->id, 'user_id' => $this->member->id, 'cost_rate' => 45, 'bill_rate' => 100])->assertCreated();
    $this->getJson("/api/v1/projects/rate-cards?project_id={$project->id}")->assertOk()->assertJsonPath('meta.total', 1);

    projectsActAs($this, $this->member);
    $this->postJson('/api/v1/projects/rate-cards', ['role' => 'junior', 'cost_rate' => 20, 'bill_rate' => 40])->assertForbidden();
    $this->deleteJson("/api/v1/projects/rate-cards/{$card}")->assertForbidden();
    $this->putJson("/api/v1/projects/resources/{$this->member->id}", ['internal_hourly_cost' => 1, 'billing_hourly_rate' => 1])->assertForbidden();

    $sheet = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 2])->assertCreated();
    expect($sheet->json('data.cost_rate'))->toBe('45.00');

    projectsActAs($this, $this->owner);
    $this->deleteJson("/api/v1/projects/rate-cards/{$card}")->assertOk();

    $monday = now()->startOfWeek()->addWeek();
    $task = ProjectTask::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'todo', 'planned_start_date' => $monday->toDateString(), 'planned_end_date' => $monday->copy()->addDays(4)->toDateString()]);
    $this->postJson("/api/v1/projects/tasks/{$task->id}/assignments", ['user_id' => $this->member->id, 'allocated_hours' => 30])->assertCreated();

    $report = $this->getJson('/api/v1/projects/resource-capacity?from='.$monday->toDateString().'&to='.$monday->copy()->addDays(13)->toDateString())->assertOk()->json('data');
    $person = collect($report)->firstWhere('user_id', $this->member->id);
    expect($person['capacity_source'])->toBe('resource_profile')
        ->and($person['over_allocated_weeks'])->toBe(1)
        ->and($person['weeks'][0]['allocated_hours'])->toBe('30.00')
        ->and($person['weeks'][0]['is_over_allocated'])->toBeTrue()
        ->and($person['weeks'][1]['allocated_hours'])->toBe('0.00');

    $this->getJson('/api/v1/projects/resource-capacity?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
});

test('time and materials billing invoices approved billable hours and costs once', function (): void {
    $project = projectsProject($this, ['billing_method' => 'time_and_materials']);
    projectsRateCard($this, $this->member, '40.00', '80.00');
    projectsActAs($this, $this->manager);

    $this->postJson("/api/v1/projects/{$project->id}/billing/time-and-materials", ['tax_rate' => 22])->assertUnprocessable()->assertJsonValidationErrors(['up_to_date']);

    $sheet = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['user_id' => $this->member->id, 'work_date' => now()->toDateString(), 'hours_worked' => 5, 'submit' => true])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['user_id' => $this->member->id, 'work_date' => now()->subDay()->toDateString(), 'hours_worked' => 3, 'is_billable' => false, 'submit' => true])->assertCreated();
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/approve")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'material', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 2, 'unit_cost' => 60, 'incurred_date' => now()->toDateString(), 'description' => 'Cavi'])->assertCreated();

    $this->getJson("/api/v1/projects/{$project->id}/closure-checks")->assertOk()->assertJsonPath('data.can_close', false);
    $this->postJson("/api/v1/projects/{$project->id}/billing/time-and-materials", ['tax_rate' => 22])->assertCreated()->assertJsonPath('data.total_net', '520.00');
    $this->postJson("/api/v1/projects/{$project->id}/billing/time-and-materials", ['tax_rate' => 22])->assertUnprocessable();
    expect(ProjectTimesheet::query()->findOrFail($sheet)->invoice_id)->not->toBeNull();
    $this->getJson("/api/v1/projects/{$project->id}/profitability")->assertOk()->assertJsonPath('data.revenue.draft_billing', '520.00')->assertJsonPath('data.revenue.unbilled_time_and_materials', '0.00');
});

test('reports: EVM recalculation and snapshots, forecast, profitability', function (): void {
    $project = projectsProject($this, ['planned_start_date' => now()->subDays(9)->toDateString(), 'planned_end_date' => now()->addDays(10)->toDateString()]);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '10000.00', 'total_revenue_budget' => '100000.00']);
    ProjectTask::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'estimated_hours' => 100, 'progress_percentage' => 40, 'planned_start_date' => now()->subDays(9)->toDateString(), 'planned_end_date' => now()->addDays(10)->toDateString()]);
    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'labour', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_cost' => 5000, 'incurred_date' => now()->toDateString()])->assertCreated();

    $summary = $this->postJson("/api/v1/projects/{$project->id}/recalculate-evm")->assertOk()->json('data.evm');
    expect($summary['budget_at_completion'])->toBe('10000.00')
        ->and($summary['earned_value'])->toBe('4000.00')
        ->and($summary['planned_value'])->toBe('5000.00')
        ->and($summary['cost_performance_index'])->toBe('0.80')
        ->and($summary['estimate_at_completion'])->toBe('12500.00')
        ->and($summary['estimate_to_complete'])->toBe('7500.00');

    $this->postJson("/api/v1/projects/{$project->id}/recalculate-evm")->assertOk();
    $this->getJson("/api/v1/projects/{$project->id}/evm-snapshots")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.schedule_performance_index', '0.8000')->assertJsonPath('data.0.health_status', 'red');
    expect(Project::query()->findOrFail($project->id)->health_status)->toBe('red');

    $this->getJson("/api/v1/projects/{$project->id}/forecast")->assertOk()->assertJsonPath('data.evm.estimate_at_completion', '12500.00')->assertJsonPath('data.projected_margin_amount', '87500.00');
    $this->getJson("/api/v1/projects/{$project->id}/profitability")->assertOk()->assertJsonPath('data.cost.actual', '5000.00')->assertJsonPath('data.forecast.is_loss_forecast', false);

    projectsActAs($this, $this->reader);
    $this->getJson("/api/v1/projects/{$project->id}/forecast")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/recalculate-evm")->assertForbidden();
});

test('closure is blocked by open work and closes the project once clean', function (): void {
    $project = projectsProject($this);
    projectsRateCard($this, $this->member);
    projectsActAs($this, $this->member);
    $sheet = $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 2, 'is_billable' => false])->assertCreated()->json('data.id');

    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/close")->assertUnprocessable()->assertJsonValidationErrors(['closure']);
    $checks = $this->getJson("/api/v1/projects/{$project->id}/closure-checks")->assertOk()->json('data');
    expect(collect($checks['blockers'])->pluck('code')->all())->toBe(['open_timesheets']);

    $this->postJson("/api/v1/projects/timesheets/{$sheet}/submit")->assertOk();
    $this->postJson("/api/v1/projects/timesheets/{$sheet}/approve")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/close", ['reason' => 'Collaudo superato'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/projects/{$project->id}/close")->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/timesheets", ['work_date' => now()->toDateString(), 'hours_worked' => 1])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/tasks", ['task_number' => 'X', 'title' => 'x', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->assertConflict();
    expect(ProjectMilestone::query()->count())->toBe(0);
});
