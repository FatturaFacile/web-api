<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectBudgetLine;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectChangeOrderLine;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class ProjectChangeOrderService implements ProjectChangeOrderServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly WorkflowEngineInterface $workflowEngine,
        private readonly JobCostingAccumulationServiceInterface $costingService
    ) {}

    public function createChangeOrder(ChangeOrderDTO $dto): ProjectChangeOrder
    {
        return DB::transaction(function () use ($dto): ProjectChangeOrder {
            /** @var Project $project */
            $project = Project::findOrFail($dto->projectId);

            $requiresApproval = abs($dto->costImpactAmount) > 1000.00;
            $status = $requiresApproval ? 'pending_approval' : 'customer_approved';

            $changeOrder = ProjectChangeOrder::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'order_number' => $dto->orderNumber,
                'title' => $dto->title,
                'scope_description' => $dto->scopeDescription,
                'cost_impact_amount' => $dto->costImpactAmount,
                'revenue_impact_amount' => $dto->revenueImpactAmount,
                'schedule_impact_days' => $dto->scheduleImpactDays,
                'status' => $status,
                'created_by' => $dto->userId ?? auth()->id(),
                'approved_by_client_at' => $requiresApproval ? null : now(),
            ]);

            foreach ($dto->lines as $line) {
                ProjectChangeOrderLine::create([
                    'tenant_id' => $project->tenant_id,
                    'project_change_order_id' => $changeOrder->id,
                    'cost_category' => $line['cost_category'],
                    'description' => $line['description'],
                    'quantity_delta' => $line['quantity_delta'],
                    'unit_cost_delta' => $line['unit_cost_delta'],
                    'total_cost_delta' => $line['total_cost_delta'],
                    'revenue_delta' => $line['revenue_delta'] ?? 0.00,
                ]);
            }

            if ($requiresApproval) {
                try {
                    $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: 'PROJECT_CHANGE_REQUEST_APPROVAL',
                        approvable: $changeOrder,
                        contextSnapshot: [
                            'order_number' => $changeOrder->order_number,
                            'project_id' => $project->id,
                            'cost_impact' => $dto->costImpactAmount,
                            'revenue_impact' => $dto->revenueImpactAmount,
                            'schedule_impact_days' => $dto->scheduleImpactDays,
                        ],
                        tenantId: (string) $project->tenant_id,
                        companyId: (string) ($project->company_id ?? $project->tenant_id),
                        initiatorUserId: $dto->userId ?? (string) $project->project_manager_user_id
                    ));
                } catch (\Throwable $e) {
                    logger()->warning('Workflow engine could not start PROJECT_CHANGE_REQUEST_APPROVAL: '.$e->getMessage());
                }
            } else {
                $this->applyChangeOrderDeltas($project, $changeOrder);
            }

            // Forensic audit trail
            $actorUserId = $dto->userId ?? auth()->id() ?? DB::table('tenant_user')->where('tenant_id', $project->tenant_id)->value('user_id') ?? $project->project_manager_user_id;

            ProjectAuditEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'change_order_created',
                'description' => "Created Change Order {$changeOrder->order_number} (Cost impact: €{$dto->costImpactAmount})",
                'before_state' => [],
                'after_state' => $changeOrder->toArray(),
            ]);

            return $changeOrder;
        });
    }

    public function approveChangeOrder(ProjectChangeOrder $changeOrder, string $approvedBy): ProjectChangeOrder
    {
        return DB::transaction(function () use ($changeOrder): ProjectChangeOrder {
            $changeOrder->loadMissing('project');
            $project = $changeOrder->project;

            $changeOrder->update([
                'status' => 'customer_approved',
                'approved_by_client_at' => now(),
            ]);

            $this->applyChangeOrderDeltas($project, $changeOrder);

            return $changeOrder;
        });
    }

    private function applyChangeOrderDeltas(Project $project, ProjectChangeOrder $changeOrder): void
    {
        // 1. Update Project Contract Value & Schedule
        $newContractValue = (float) $project->contract_value + (float) $changeOrder->revenue_impact_amount;
        $updates = ['contract_value' => $newContractValue];

        if ($changeOrder->schedule_impact_days > 0) {
            $currentEnd = Carbon::parse($project->planned_end_date);
            $updates['planned_end_date'] = $currentEnd->addDays($changeOrder->schedule_impact_days)->toDateString();
        }

        $project->update($updates);

        // 2. Update Project Budget
        $budget = ProjectBudget::where('project_id', $project->id)->first();
        if ($budget) {
            $newTotalCost = (float) $budget->total_cost_budget + (float) $changeOrder->cost_impact_amount;
            $newTotalRev = (float) $budget->total_revenue_budget + (float) $changeOrder->revenue_impact_amount;

            $budgetUpdates = [
                'total_cost_budget' => $newTotalCost,
                'total_revenue_budget' => $newTotalRev,
                'projected_margin_amount' => $newTotalRev - $newTotalCost,
                'projected_margin_percentage' => $newTotalRev > 0 ? round((($newTotalRev - $newTotalCost) / $newTotalRev) * 100, 2) : 0.0,
            ];

            // Category adjustments from change order lines
            foreach ($changeOrder->lines as $line) {
                $catCol = "{$line->cost_category}_budget";
                if (Schema::hasColumn('project_budgets', $catCol)) {
                    $budgetUpdates[$catCol] = (float) ($budgetUpdates[$catCol] ?? $budget->{$catCol}) + (float) $line->total_cost_delta;
                }

                ProjectBudgetLine::create([
                    'tenant_id' => $budget->tenant_id,
                    'project_budget_id' => $budget->id,
                    'cost_category' => $line->cost_category,
                    'description' => "[CO: {$changeOrder->order_number}] {$line->description}",
                    'planned_quantity' => $line->quantity_delta,
                    'planned_unit_cost' => $line->unit_cost_delta,
                    'planned_total_cost' => $line->total_cost_delta,
                    'planned_revenue_amount' => $line->revenue_delta,
                ]);
            }

            $budget->update($budgetUpdates);
        }

        // 3. Rollup financial summary
        $this->costingService->rollupProjectCosts($project);
    }
}
