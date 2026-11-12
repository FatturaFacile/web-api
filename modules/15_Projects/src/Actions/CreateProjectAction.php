<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectPhase;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;

/**
 * Opens a project in draft with its initial phases and milestones and its empty financial summary, and announces it.
 */
final readonly class CreateProjectAction
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @throws ValidationException when the project number is already used in the company
     */
    public function execute(CreateProjectDTO $dto, string $tenantId): Project
    {
        return DB::transaction(function () use ($dto, $tenantId): Project {
            if (Project::query()->where('project_number', $dto->projectNumber)->exists()) {
                throw ValidationException::withMessages(['project_number' => __('projects_errors.project.number_already_used')]);
            }

            $project = Project::create([
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $tenantId,
                'portfolio_id' => $dto->portfolioId,
                'cost_center_id' => $dto->costCenterId,
                'parent_project_id' => $dto->parentProjectId,
                'contact_id' => $dto->contactId,
                'project_number' => $dto->projectNumber,
                'name' => $dto->name,
                'description' => $dto->description,
                'project_type' => $dto->projectType,
                'status' => 'draft',
                'billing_method' => $dto->billingMethod,
                'revenue_recognition_method' => $dto->revenueRecognitionMethod,
                'planned_start_date' => $dto->plannedStartDate,
                'planned_end_date' => $dto->plannedEndDate,
                'project_manager_user_id' => $dto->projectManagerUserId,
                'currency' => $dto->currency,
                'contract_value' => Money::amount($dto->contractValue),
                'progress_percentage' => '0.00',
                'health_status' => 'green',
                'created_by' => $dto->userId,
            ]);

            foreach (array_values($dto->phases) as $index => $phase) {
                ProjectPhase::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'sequence_order' => $phase['sequence_order'] ?? $index + 1,
                    'name' => $phase['name'],
                    'status' => 'pending',
                    'planned_start_date' => $phase['planned_start_date'] ?? $dto->plannedStartDate,
                    'planned_end_date' => $phase['planned_end_date'] ?? $dto->plannedEndDate,
                ]);
            }

            foreach ($dto->milestones as $milestone) {
                ProjectMilestone::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'name' => $milestone['name'],
                    'milestone_date' => $milestone['milestone_date'],
                    'billing_percentage' => Money::amount($milestone['billing_percentage'] ?? 0),
                    'billing_amount' => Money::amount($milestone['billing_amount'] ?? 0),
                ]);
            }

            ProjectFinancialSummary::create([
                'tenant_id' => $tenantId,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'total_contract_revenue' => $project->contract_value,
                'gross_profit_amount' => $project->contract_value,
                'gross_profit_percentage' => Money::isPositive($project->contract_value) ? '100.00' : '0.00',
            ]);

            $this->audit->record($project, $dto->userId ?? $dto->projectManagerUserId, 'project_created', "Created project {$project->project_number}: {$project->name}", [], $project->only(['project_number', 'name', 'contract_value', 'status']));

            $this->dispatcher->publishAndDispatch(new ProjectCreatedEvent(
                projectId: (string) $project->id,
                projectNumber: $project->project_number,
                projectName: $project->name,
                projectType: $project->project_type,
                contractValue: (float) $project->contract_value,
                tenantId: $tenantId,
                companyId: (string) ($project->company_id ?? $tenantId)
            ));

            return $project->load(['phases', 'milestones']);
        });
    }
}
