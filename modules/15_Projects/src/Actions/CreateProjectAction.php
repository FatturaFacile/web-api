<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectPhase;

final readonly class CreateProjectAction
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function execute(CreateProjectDTO $dto, string $tenantId): Project
    {
        return DB::transaction(function () use ($dto, $tenantId): Project {
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
                'planned_start_date' => $dto->plannedStartDate,
                'planned_end_date' => $dto->plannedEndDate,
                'project_manager_user_id' => $dto->projectManagerUserId,
                'contract_value' => $dto->contractValue,
                'progress_percentage' => 0.00,
                'health_status' => 'green',
                'created_by' => $dto->userId,
            ]);

            // Create initial phases if provided
            foreach ($dto->phases as $phase) {
                ProjectPhase::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'sequence_order' => $phase['sequence_order'] ?? $phase['sort_order'] ?? 1,
                    'name' => $phase['name'],
                    'status' => 'pending',
                    'planned_start_date' => $phase['planned_start_date'] ?? $dto->plannedStartDate,
                    'planned_end_date' => $phase['planned_end_date'] ?? $dto->plannedEndDate,
                ]);
            }

            // Create initial milestones if provided
            foreach ($dto->milestones as $milestone) {
                ProjectMilestone::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'name' => $milestone['name'] ?? $milestone['title'] ?? 'Milestone',
                    'milestone_date' => $milestone['milestone_date'] ?? $milestone['date'] ?? $dto->plannedEndDate,
                    'billing_percentage' => $milestone['billing_percentage'] ?? 0.00,
                    'billing_amount' => $milestone['billing_amount'] ?? $milestone['amount'] ?? 0.00,
                ]);
            }

            // Initialize financial summary
            ProjectFinancialSummary::create([
                'tenant_id' => $tenantId,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'total_contract_revenue' => $project->contract_value,
                'gross_profit_amount' => $project->contract_value,
                'gross_profit_percentage' => 100.00,
            ]);

            // Audit
            $actorUserId = $dto->userId ?? auth()->id() ?? DB::table('tenant_user')->where('tenant_id', $tenantId)->value('user_id') ?? $dto->projectManagerUserId;

            ProjectAuditEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'project_created',
                'description' => "Created project {$project->project_number}: {$project->name}",
                'before_state' => [],
                'after_state' => $project->toArray(),
            ]);

            $this->auditLedger->record(
                action: 'created',
                model: $project,
                payloadBefore: null,
                payloadAfter: $project->toArray(),
                moduleCode: 'MODULE_15_PROJECTS'
            );

            // Dispatch Domain Event
            $this->dispatcher->publish(new ProjectCreatedEvent(
                projectId: (string) $project->id,
                projectNumber: $project->project_number,
                projectName: $project->name,
                projectType: $project->project_type,
                contractValue: (float) $project->contract_value,
                tenantId: $tenantId,
                companyId: (string) ($project->company_id ?? $tenantId)
            ));

            return $project;
        });
    }
}
