<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Events\ProjectBudgetExceededEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectCostVarianceDetectedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectCostAllocation;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final class JobCostingAccumulationService implements JobCostingAccumulationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly WorkflowEngineInterface $workflowEngine
    ) {}

    public function recordCost(RecordJobCostDTO $dto): ProjectCostEntry
    {
        return DB::transaction(function () use ($dto): ProjectCostEntry {
            /** @var Project $project */
            $project = Project::with('budget')->findOrFail($dto->projectId);

            $costEntry = ProjectCostEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $dto->companyId ?? $project->company_id,
                'project_id' => $project->id,
                'project_phase_id' => $dto->phaseId,
                'project_task_id' => $dto->taskId,
                'cost_source_type' => $dto->costSourceType,
                'cost_source_id' => $dto->costSourceId,
                'cost_category' => $dto->costCategory,
                'quantity' => $dto->quantity,
                'uom' => $dto->uom,
                'unit_cost' => $dto->unitCost,
                'total_cost' => $dto->totalCost,
                'is_billable' => $dto->isBillable,
                'is_invoiced' => false,
                'incurred_date' => $dto->incurredDate,
            ]);

            // Multi-task allocations if specified
            if (! empty($dto->allocations)) {
                foreach ($dto->allocations as $alloc) {
                    $allocAmount = round(($dto->totalCost * (float) $alloc['percentage']) / 100.0, 2);
                    ProjectCostAllocation::create([
                        'tenant_id' => $project->tenant_id,
                        'project_cost_entry_id' => $costEntry->id,
                        'project_task_id' => $alloc['task_id'],
                        'allocation_percentage' => $alloc['percentage'],
                        'allocated_amount' => $allocAmount,
                    ]);
                }
            }

            // Real-time roll up into ProjectFinancialSummary
            $summary = $this->rollupProjectCosts($project);

            // Forensic audit trail
            $actorUserId = $dto->userId ?? auth()->id() ?? DB::table('tenant_user')->where('tenant_id', $project->tenant_id)->value('user_id') ?? $project->project_manager_user_id;

            ProjectAuditEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'cost_entry_recorded',
                'description' => "Recorded {$dto->costCategory} cost of €{$dto->totalCost} from {$dto->costSourceType}",
                'before_state' => [],
                'after_state' => $costEntry->toArray(),
            ]);

            $this->auditLedger->record(
                action: 'created',
                model: $costEntry,
                payloadBefore: null,
                payloadAfter: $costEntry->toArray(),
                moduleCode: 'MODULE_15_PROJECTS'
            );

            // Accounting readiness event
            $this->dispatcher->publish(new ProjectCostReadyEvent(
                costEntryId: (string) $costEntry->id,
                projectId: (string) $project->id,
                costCategory: $dto->costCategory,
                totalCost: (float) $dto->totalCost,
                debitAccountCode: $this->mapDebitAccount($dto->costCategory),
                creditAccountCode: $this->mapCreditAccount($dto->costCategory),
                incurredDate: $dto->incurredDate,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            return $costEntry;
        });
    }

    public function rollupProjectCosts(Project $project): ProjectFinancialSummary
    {
        $summary = ProjectFinancialSummary::query()
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();

        if (! $summary) {
            $summary = ProjectFinancialSummary::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'total_contract_revenue' => $project->contract_value,
            ]);
        }

        // Aggregate actual direct costs by category
        $costAggregates = ProjectCostEntry::query()
            ->where('project_id', $project->id)
            ->selectRaw('cost_category, SUM(total_cost) as cat_total')
            ->groupBy('cost_category')
            ->pluck('cat_total', 'cost_category');

        $materialCost = (float) ($costAggregates['material'] ?? 0.0);
        $labourCost = (float) ($costAggregates['labour'] ?? 0.0);
        $supplierCost = (float) ($costAggregates['supplier'] ?? 0.0);
        $fleetCost = (float) ($costAggregates['fleet'] ?? 0.0);
        $expenseCost = (float) ($costAggregates['expense'] ?? 0.0);
        $equipmentCost = (float) ($costAggregates['equipment'] ?? 0.0);

        $totalActualCost = $materialCost + $labourCost + $supplierCost + $fleetCost + $expenseCost + $equipmentCost;
        $contractRevenue = (float) $project->contract_value;
        $grossProfit = $contractRevenue - $totalActualCost;
        $grossMarginPct = $contractRevenue > 0.0 ? round(($grossProfit / $contractRevenue) * 100.0, 2) : 0.0;

        $summary->update([
            'total_contract_revenue' => $contractRevenue,
            'actual_material_cost' => $materialCost,
            'actual_labour_cost' => $labourCost,
            'actual_supplier_cost' => $supplierCost,
            'actual_fleet_cost' => $fleetCost,
            'actual_expense_cost' => $expenseCost + $equipmentCost,
            'total_actual_cost' => $totalActualCost,
            'gross_profit_amount' => $grossProfit,
            'gross_profit_percentage' => $grossMarginPct,
            'last_recalculated_at' => now(),
        ]);

        // Budget Consumption & Burn Alert
        $budget = $project->budget;
        if ($budget && (float) $budget->total_cost_budget > 0.0) {
            $budgetCost = (float) $budget->total_cost_budget;
            $consumptionPct = round(($totalActualCost / $budgetCost) * 100.0, 2);

            if ($totalActualCost > $budgetCost) {
                $variance = round($totalActualCost - $budgetCost, 2);

                $this->dispatcher->publish(new ProjectBudgetExceededEvent(
                    projectId: (string) $project->id,
                    projectNumber: (string) $project->project_number,
                    budgetCost: $budgetCost,
                    actualCost: $totalActualCost,
                    varianceAmount: $variance,
                    consumptionPercentage: $consumptionPct,
                    tenantId: (string) $project->tenant_id,
                    companyId: (string) ($project->company_id ?? $project->tenant_id)
                ));

                // Escalate to Module 21 Workflow Engine if variance exceeds €1,000
                if ($variance >= 1000.00) {
                    $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: 'PROJECT_COST_VARIANCE_APPROVAL',
                        approvable: $project,
                        contextSnapshot: [
                            'project_number' => $project->project_number,
                            'budget_cost' => $budgetCost,
                            'actual_cost' => $totalActualCost,
                            'variance_amount' => $variance,
                        ],
                        tenantId: (string) $project->tenant_id,
                        companyId: (string) ($project->company_id ?? $project->tenant_id),
                        initiatorUserId: (string) $project->project_manager_user_id
                    ));
                }
            }
        }

        return $summary;
    }

    public function analyzeCostVariance(Project $project): array
    {
        $budget = $project->budget;
        $summary = $project->financialSummary ?? $this->rollupProjectCosts($project);

        $categories = ['material', 'labour', 'supplier', 'fleet', 'expense'];
        $variances = [];

        foreach ($categories as $cat) {
            $budgetCol = "{$cat}_budget";
            $actualCol = "actual_{$cat}_cost";

            $planned = $budget ? (float) $budget->{$budgetCol} : 0.0;
            $actual = (float) $summary->{$actualCol};
            $delta = $actual - $planned;
            $deltaPct = $planned > 0.0 ? round(($delta / $planned) * 100.0, 2) : 0.0;

            if ($delta > 0 && $deltaPct > 10.0) {
                $this->dispatcher->publish(new ProjectCostVarianceDetectedEvent(
                    projectId: (string) $project->id,
                    costCategory: $cat,
                    plannedCost: $planned,
                    actualCost: $actual,
                    variancePercentage: $deltaPct,
                    tenantId: (string) $project->tenant_id,
                    companyId: (string) ($project->company_id ?? $project->tenant_id)
                ));
            }

            $variances[$cat] = [
                'planned' => $planned,
                'actual' => $actual,
                'delta' => $delta,
                'delta_percentage' => $deltaPct,
            ];
        }

        return $variances;
    }

    private function mapDebitAccount(string $category): string
    {
        return match ($category) {
            'material' => '3100-WIP-MATERIALS',
            'labour' => '3200-WIP-DIRECT-LABOUR',
            'supplier' => '3300-WIP-SUBCONTRACTORS',
            'fleet', 'equipment' => '3400-WIP-EQUIPMENT-FLEET',
            'expense' => '3500-WIP-PROJECT-EXPENSES',
            default => '3000-WIP-GENERAL',
        };
    }

    private function mapCreditAccount(string $category): string
    {
        return match ($category) {
            'material' => '1200-INVENTORY-ASSET',
            'labour' => '5100-PAYROLL-CLEARING',
            'supplier' => '2100-ACCOUNTS-PAYABLE',
            'fleet', 'equipment' => '5400-FLEET-COST-RECOVERY',
            'expense' => '2150-EXPENSE-REIMBURSEMENTS',
            default => '2000-ACCOUNTS-PAYABLE-GENERAL',
        };
    }
}
