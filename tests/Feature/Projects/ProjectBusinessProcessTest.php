<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\Projects\Actions\ApproveProjectTimesheetAction;
use Modules\Projects\Actions\CloseProjectAction;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\Actions\ExecuteChangeOrderAction;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectTask;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Project Lifecycle Test Tenant',
        'legal_name' => 'Project Enterprise SpA',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);
    // Project cost and revenue events now reach the General Ledger listeners.
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->year);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Lead Project Architect',
        'email' => 'architect@project-process.test',
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
        'company_name' => 'Major EPC Client SpA',
        'email' => 'client@major-epc.it',
        'vat_number' => 'IT99887766554',
    ]);
});

test('complete end-to-end project lifecycle from inception to closure with EVM and IFRS 15 PoC', function (): void {
    $createAction = app(CreateProjectAction::class);
    $budgetService = app(ProjectBudgetServiceInterface::class);
    $timesheetService = app(ProjectTimesheetServiceInterface::class);
    $timesheetAction = app(ApproveProjectTimesheetAction::class);
    $costingService = app(JobCostingAccumulationServiceInterface::class);
    $evmService = app(EarnedValueCalculationServiceInterface::class);
    $revenueService = app(ProjectRevenueRecognitionServiceInterface::class);
    $changeOrderService = app(ProjectChangeOrderServiceInterface::class);
    $changeOrderAction = app(ExecuteChangeOrderAction::class);
    $closeAction = app(CloseProjectAction::class);

    // 1. Create Project
    $project = $createAction->execute(new CreateProjectDTO(
        contactId: $this->contact->id,
        projectNumber: 'PRJ-E2E-'.Str::upper(Str::random(6)),
        name: 'Smart Energy Microgrid & BESS Facility',
        projectType: 'construction',
        billingMethod: 'milestone_based',
        plannedStartDate: now()->toDateString(),
        plannedEndDate: now()->addMonths(8)->toDateString(),
        projectManagerUserId: $this->user->id,
        contractValue: '500000.00',
        phases: [
            ['name' => 'Phase 1: Civil Works', 'sequence_order' => 10],
            ['name' => 'Phase 2: Battery Energy Storage System', 'sequence_order' => 20],
        ],
        milestones: [
            ['name' => 'Civil Handover', 'billing_amount' => '200000.00', 'milestone_date' => now()->addMonths(3)->toDateString()],
            ['name' => 'Microgrid Energization', 'billing_amount' => '300000.00', 'milestone_date' => now()->addMonths(8)->toDateString()],
        ],
        userId: $this->user->id,
        companyId: $this->tenant->id,
        revenueRecognitionMethod: 'percentage_of_completion',
    ), $this->tenant->id);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->phases)->toHaveCount(2)
        ->and($project->milestones)->toHaveCount(2);

    $phase1 = $project->phases->first();
    $task1 = ProjectTask::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $project->id,
        'project_phase_id' => $phase1->id,
        'task_number' => 'TSK-001',
        'title' => 'Excavation and Slab Concrete Pouring',
        'status' => 'in_progress',
        'priority' => 'urgent',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addWeeks(4)->toDateString(),
        'estimated_hours' => 200.0,
        'progress_percentage' => 50.0,
    ]);
    $task2 = ProjectTask::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'project_id' => $project->id,
        'project_phase_id' => $phase1->id,
        'task_number' => 'TSK-002',
        'title' => 'Earthing & Underground Conduit Laying',
        'status' => 'todo',
        'priority' => 'high',
        'planned_start_date' => now()->addWeeks(2)->toDateString(),
        'planned_end_date' => now()->addWeeks(6)->toDateString(),
        'estimated_hours' => 150.0,
    ]);

    // 2. Budget by category: the total cost is the sum of the categories, approval freezes the baseline.
    $budget = $budgetService->createOrUpdateBudget(new ProjectBudgetDTO(
        projectId: $project->id,
        totalRevenueBudget: '500000.00',
        labourBudget: '150000.00',
        materialBudget: '150000.00',
        supplierBudget: '50000.00',
        fleetBudget: '20000.00',
        expenseBudget: '5000.00',
        contingencyBudget: '5000.00',
        lines: [
            ['cost_category' => 'labour', 'description' => 'Site engineers and operators', 'planned_quantity' => 1000, 'planned_unit_cost' => 150],
            ['cost_category' => 'material', 'description' => 'Concrete, rebar and conduits', 'planned_quantity' => 1, 'planned_unit_cost' => 150000],
        ],
        userId: $this->user->id
    ));
    expect($budget->total_cost_budget)->toBe('380000.00')->and($budget->status)->toBe('draft');

    $baseline = $budgetService->approveBudget($budget, $this->user->id);
    expect($baseline->current_version_number)->toBe(1)->and($baseline->status)->toBe('approved');

    // 3. Log, submit and approve a timesheet: the labour cost reaches the project.
    $timesheet = $timesheetService->logTime(new TimesheetEntryDTO(
        projectId: $project->id,
        userId: $this->user->id,
        workDate: now()->toDateString(),
        hoursWorked: '8',
        taskId: $task1->id,
        customCostRate: '65.00',
        customChargeRate: '120.00',
        workSummary: 'Foundation inspection and pour coordination',
        submit: true,
    ));
    expect($timesheet->total_cost)->toBe('520.00');

    $approvedTimesheet = $timesheetAction->execute($timesheet, $this->user->id);
    expect($approvedTimesheet->status)->toBe('approved');

    $labourEntry = ProjectCostEntry::where('project_id', $project->id)->where('cost_category', 'labour')->first();
    expect($labourEntry)->not->toBeNull()->and($labourEntry->total_cost)->toBe('520.00');

    // 4. Material cost split over two tasks.
    $materialEntry = $costingService->recordCost(new RecordJobCostDTO(
        projectId: $project->id,
        costCategory: 'material',
        costSourceType: 'inventory_movement',
        costSourceId: (string) Str::uuid7(),
        quantity: '100',
        unitCost: '250',
        totalCost: '25000.00',
        incurredDate: now()->toDateString(),
        phaseId: $phase1->id,
        taskId: $task1->id,
        allocations: [
            ['task_id' => $task1->id, 'percentage' => 70],
            ['task_id' => $task2->id, 'percentage' => 30],
        ],
        userId: $this->user->id,
        companyId: $this->tenant->id
    ));
    expect($materialEntry->total_cost)->toBe('25000.00')->and($materialEntry->allocations)->toHaveCount(2);

    // 5. Earned value: actual cost 520 + 25000.
    $summary = $evmService->recalculateEVM($project);
    expect($summary)->toBeInstanceOf(ProjectFinancialSummary::class)
        ->and($summary->total_actual_cost)->toBe('25520.00')
        ->and($summary->actual_labour_cost)->toBe('520.00')
        ->and($summary->actual_material_cost)->toBe('25000.00')
        ->and($summary->budget_at_completion_bac)->toBe('380000.00');

    // 6. Revenue recognition with a stated completion percentage (SAL).
    $recognition = $revenueService->calculateAndRecognize(new RevenueRecognitionDTO(
        projectId: $project->id,
        accountingPeriodDate: now()->toDateString(),
        overrideCompletionPercentage: '10',
        userId: $this->user->id
    ));
    expect($recognition)->toBeInstanceOf(ProjectRevenueRecognition::class)
        ->and($recognition->cumulative_recognized_revenue)->toBe('50000.00')
        ->and($recognition->period_revenue_adjustment)->toBe('50000.00');

    // 7. Change order approval moves the contract value.
    $changeOrder = $changeOrderService->createChangeOrder(new ChangeOrderDTO(
        projectId: $project->id,
        orderNumber: 'CO-E2E-001',
        title: 'Additional Geotechnical Grouting',
        scopeDescription: 'Extra pressure grouting due to karst sinkhole void',
        costImpactAmount: '18000.00',
        revenueImpactAmount: '24000.00',
        scheduleImpactDays: 10,
        lines: [
            ['cost_category' => 'supplier', 'description' => 'Drilling contractor', 'quantity_delta' => 1, 'unit_cost_delta' => 18000, 'total_cost_delta' => 18000, 'revenue_delta' => 24000],
        ],
        userId: $this->user->id
    ));
    $approvedCO = $changeOrderAction->execute($changeOrder, $this->user->id);
    expect($approvedCO->status)->toBe('customer_approved');

    $project->refresh();
    expect($project->contract_value)->toBe('524000.00');

    // 8. Closure is refused while recognised revenue is not invoiced.
    expect(fn () => $closeAction->execute($project, $this->user->id, 'Project accepted'))->toThrow(ValidationException::class);
});

test('handles database transaction rollback when multi-task cost allocation fails', function (): void {
    $costingService = app(JobCostingAccumulationServiceInterface::class);

    $project = Project::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
    ]);

    $initialCostCount = ProjectCostEntry::where('project_id', $project->id)->count();

    try {
        DB::transaction(function () use ($costingService, $project): void {
            $costDto = new RecordJobCostDTO(
                projectId: $project->id,
                costCategory: 'material',
                costSourceType: 'inventory_movement',
                costSourceId: (string) Str::uuid7(),
                quantity: '10',
                unitCost: '100',
                totalCost: '1000.00',
                incurredDate: now()->toDateString(),
                userId: $this->user->id,
                companyId: $this->tenant->id
            );

            $costingService->recordCost($costDto);

            // Trigger forced rollback
            throw new \RuntimeException('Simulated integration failure triggering atomic rollback');
        });
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('Simulated integration failure');
    }

    expect(ProjectCostEntry::where('project_id', $project->id)->count())->toBe($initialCostCount);
});
