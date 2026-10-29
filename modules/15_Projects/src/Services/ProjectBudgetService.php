<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectBudgetLine;
use Modules\Projects\Models\ProjectBudgetVersion;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class ProjectBudgetService implements ProjectBudgetServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly WorkflowEngineInterface $workflowEngine
    ) {}

    public function createOrUpdateBudget(ProjectBudgetDTO $dto): ProjectBudget
    {
        return DB::transaction(function () use ($dto): ProjectBudget {
            /** @var Project $project */
            $project = Project::findOrFail($dto->projectId);

            $marginAmount = round($dto->totalRevenueBudget - $dto->totalCostBudget, 2);
            $marginPct = $dto->totalRevenueBudget > 0.0
                ? round(($marginAmount / $dto->totalRevenueBudget) * 100.0, 2)
                : 0.0;

            // Threshold: budgets exceeding €10,000 require formal Workflow Engine V2 approval
            $requiresApproval = $dto->totalCostBudget > 10000.00;
            $status = $requiresApproval ? 'pending_approval' : 'approved';

            $budget = ProjectBudget::updateOrCreate(
                [
                    'tenant_id' => $project->tenant_id,
                    'project_id' => $project->id,
                ],
                [
                    'company_id' => $project->company_id,
                    'status' => $status,
                    'total_revenue_budget' => $dto->totalRevenueBudget,
                    'total_cost_budget' => $dto->totalCostBudget,
                    'labour_budget' => $dto->labourBudget,
                    'material_budget' => $dto->materialBudget,
                    'supplier_budget' => $dto->supplierBudget,
                    'fleet_budget' => $dto->fleetBudget,
                    'expense_budget' => $dto->expenseBudget,
                    'contingency_budget' => $dto->contingencyBudget,
                    'projected_margin_amount' => $marginAmount,
                    'projected_margin_percentage' => $marginPct,
                    'approved_by' => $requiresApproval ? null : ($dto->userId ?? auth()->id()),
                    'approved_at' => $requiresApproval ? null : now(),
                ]
            );

            // Rebuild budget lines if provided
            if (! empty($dto->lines)) {
                $budget->lines()->delete();
                foreach ($dto->lines as $line) {
                    ProjectBudgetLine::create([
                        'tenant_id' => $project->tenant_id,
                        'project_budget_id' => $budget->id,
                        'project_phase_id' => $line['phase_id'] ?? null,
                        'cost_category' => $line['cost_category'],
                        'description' => $line['description'],
                        'planned_quantity' => $line['planned_quantity'],
                        'planned_unit_cost' => $line['planned_unit_cost'],
                        'planned_total_cost' => $line['planned_total_cost'],
                        'planned_revenue_amount' => $line['planned_revenue_amount'] ?? 0.00,
                    ]);
                }
            }

            if ($requiresApproval) {
                try {
                    $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: 'PROJECT_BUDGET_APPROVAL',
                        approvable: $budget,
                        contextSnapshot: [
                            'project_id' => $project->id,
                            'project_number' => $project->project_number,
                            'cost_budget' => $dto->totalCostBudget,
                            'revenue_budget' => $dto->totalRevenueBudget,
                            'margin_pct' => $marginPct,
                        ],
                        tenantId: (string) $project->tenant_id,
                        companyId: (string) ($project->company_id ?? $project->tenant_id),
                        initiatorUserId: $dto->userId ?? (string) $project->project_manager_user_id
                    ));
                } catch (\Throwable $e) {
                    logger()->warning('Workflow engine could not start PROJECT_BUDGET_APPROVAL: '.$e->getMessage());
                }
            } else {
                $this->baselineBudget($budget, (string) ($dto->userId ?? $project->project_manager_user_id));
            }

            // Forensic audit trail
            $actorUserId = $dto->userId ?? auth()->id() ?? DB::table('tenant_user')->where('tenant_id', $project->tenant_id)->value('user_id') ?? $project->project_manager_user_id;

            ProjectAuditEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'budget_defined',
                'description' => "Defined budget of €{$dto->totalCostBudget} with status {$status}",
                'before_state' => [],
                'after_state' => $budget->toArray(),
            ]);

            return $budget;
        });
    }

    public function baselineBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget
    {
        return DB::transaction(function () use ($budget, $approvedBy): ProjectBudget {
            $budget->loadMissing(['lines', 'project']);
            $nextVersion = $budget->current_version_number + 1;
            $approverUuid = ! empty($approvedBy) ? $approvedBy : ($budget->project?->project_manager_user_id ?: null);

            $budget->update([
                'status' => 'approved',
                'current_version_number' => $nextVersion,
                'approved_by' => $approverUuid,
                'approved_at' => now(),
            ]);

            ProjectBudgetVersion::create([
                'tenant_id' => $budget->tenant_id,
                'project_budget_id' => $budget->id,
                'version_number' => $nextVersion,
                'revision_reason' => 'Baseline frozen upon approval',
                'snapshot_payload' => [
                    'budget' => $budget->toArray(),
                    'lines' => $budget->lines->toArray(),
                ],
                'total_revenue_budget' => $budget->total_revenue_budget,
                'total_cost_budget' => $budget->total_cost_budget,
                'is_baseline' => true,
                'approved_by' => $approverUuid,
            ]);

            return $budget;
        });
    }

    public function approveBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget
    {
        return $this->baselineBudget($budget, $approvedBy);
    }
}
