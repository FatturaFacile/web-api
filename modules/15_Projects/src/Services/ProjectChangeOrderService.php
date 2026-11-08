<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectBudgetLine;
use Modules\Projects\Models\ProjectBudgetVersion;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectChangeOrderLine;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Change orders (varianti): registered as submitted, then approved by a manager or by the PROJECT_CHANGE_REQUEST_APPROVAL
 * workflow when configured, or rejected. Approval applies the change once, under a lock of the project: the contract
 * value moves by the revenue impact, the cost budget by category by the line deltas (or the contingency when there are
 * no lines), the planned end date by the schedule impact, and a budget version records the change.
 */
final readonly class ProjectChangeOrderService implements ProjectChangeOrderServiceInterface
{
    public function __construct(
        private ProjectAuditTrail $audit,
        private ProjectApprovalRouter $approvals,
        private JobCostingAccumulationServiceInterface $costingService,
        private ProjectBudgetService $budgets,
    ) {}

    /**
     * @throws ValidationException when the lines do not add up to the stated impacts or the number is taken
     * @throws ConflictHttpException when the project is closed
     */
    public function createChangeOrder(ChangeOrderDTO $dto): ProjectChangeOrder
    {
        return DB::transaction(function () use ($dto): ProjectChangeOrder {
            $project = Project::query()->whereKey($dto->projectId)->lockForUpdate()->firstOrFail();

            if (! $project->isOpen()) {
                throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: no change orders can be added.");
            }

            if (ProjectChangeOrder::query()->where('project_id', $project->id)->where('order_number', $dto->orderNumber)->exists()) {
                throw ValidationException::withMessages(['order_number' => 'This change order number is already used on the project.']);
            }

            $this->assertLinesMatch($dto);

            $changeOrder = ProjectChangeOrder::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'order_number' => $dto->orderNumber,
                'title' => $dto->title,
                'scope_description' => $dto->scopeDescription,
                'cost_impact_amount' => Money::amount($dto->costImpactAmount),
                'revenue_impact_amount' => Money::amount($dto->revenueImpactAmount),
                'schedule_impact_days' => $dto->scheduleImpactDays,
                'status' => 'submitted',
                'created_by' => $dto->userId,
            ]);

            foreach ($dto->lines as $line) {
                ProjectChangeOrderLine::create([
                    'tenant_id' => $project->tenant_id,
                    'project_change_order_id' => $changeOrder->id,
                    'cost_category' => $line['cost_category'],
                    'description' => $line['description'],
                    'quantity_delta' => Money::round($line['quantity_delta'], 4),
                    'unit_cost_delta' => Money::round($line['unit_cost_delta'], 4),
                    'total_cost_delta' => Money::amount($line['total_cost_delta']),
                    'revenue_delta' => Money::amount($line['revenue_delta'] ?? 0),
                ]);
            }

            $actor = $dto->userId ?? (string) $project->project_manager_user_id;
            $routed = $this->approvals->routeIfConfigured(
                ProjectApprovalRouter::ChangeOrderWorkflow,
                $changeOrder,
                ['order_number' => $changeOrder->order_number, 'project_id' => $project->id, 'cost_impact' => $changeOrder->cost_impact_amount, 'revenue_impact' => $changeOrder->revenue_impact_amount, 'schedule_impact_days' => $dto->scheduleImpactDays],
                (string) $project->tenant_id,
                $project->company_id,
                $actor
            );

            if ($routed) {
                $changeOrder->update(['status' => 'pending_approval']);
            }

            $this->audit->record($project, $actor, 'change_order_created', "Change order {$changeOrder->order_number}: cost {$changeOrder->cost_impact_amount}, revenue {$changeOrder->revenue_impact_amount}", [], $changeOrder->only(['order_number', 'status']), $changeOrder);

            return $changeOrder->load('lines');
        });
    }

    /**
     * Approves the change order and applies it to the project and its budget, exactly once.
     *
     * @throws ConflictHttpException when the change order was already decided or the project is closed
     */
    public function approveChangeOrder(ProjectChangeOrder $changeOrder, string $approvedBy): ProjectChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $approvedBy): ProjectChangeOrder {
            $project = Project::query()->whereKey($changeOrder->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['submitted', 'pending_approval', 'draft'], true)) {
                throw new ConflictHttpException("Change order is {$locked->status}: it was already decided.");
            }

            if (! $project->isOpen()) {
                throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: change orders can no longer be applied.");
            }

            $locked->update(['status' => 'customer_approved', 'approved_by_client_at' => now(), 'approved_by' => $approvedBy, 'applied_at' => now()]);
            $this->apply($project, $locked->load('lines'), $approvedBy);

            return $locked;
        });
    }

    /**
     * Rejects a change order that was not decided yet; nothing is applied.
     */
    public function rejectChangeOrder(ProjectChangeOrder $changeOrder, string $rejectedBy, ?string $reason = null): ProjectChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $rejectedBy, $reason): ProjectChangeOrder {
            $locked = ProjectChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['submitted', 'pending_approval', 'draft'], true)) {
                throw new ConflictHttpException("Change order is {$locked->status}: it was already decided.");
            }

            $locked->update(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->audit->record($project, $rejectedBy, 'change_order_rejected', "Change order {$locked->order_number} rejected".($reason ? ": {$reason}" : ''), ['status' => 'submitted'], ['status' => 'rejected'], $locked);

            return $locked;
        });
    }

    private function apply(Project $project, ProjectChangeOrder $changeOrder, string $approvedBy): void
    {
        $before = ['contract_value' => $project->contract_value, 'planned_end_date' => $project->planned_end_date?->toDateString()];
        $updates = ['contract_value' => Money::amount(Money::add($project->contract_value, $changeOrder->revenue_impact_amount))];

        if ($changeOrder->schedule_impact_days !== 0 && $project->planned_end_date !== null) {
            $updates['planned_end_date'] = $project->planned_end_date->copy()->addDays($changeOrder->schedule_impact_days)->toDateString();
        }

        $project->update($updates);

        $budget = ProjectBudget::query()->where('project_id', $project->id)->lockForUpdate()->first();

        if ($budget !== null) {
            $columns = [];

            if ($changeOrder->lines->isEmpty()) {
                $columns['contingency_budget'] = Money::add($budget->contingency_budget, $changeOrder->cost_impact_amount);
            }

            foreach ($changeOrder->lines as $line) {
                $column = ProjectBudget::CategoryColumns[$line->cost_category === 'equipment' ? 'fleet' : $line->cost_category] ?? 'contingency_budget';
                $columns[$column] = Money::add($columns[$column] ?? $budget->getAttribute($column), $line->total_cost_delta);

                ProjectBudgetLine::create([
                    'tenant_id' => $budget->tenant_id,
                    'project_budget_id' => $budget->id,
                    'cost_category' => $line->cost_category,
                    'description' => "[CO {$changeOrder->order_number}] {$line->description}",
                    'planned_quantity' => $line->quantity_delta,
                    'planned_unit_cost' => $line->unit_cost_delta,
                    'planned_total_cost' => $line->total_cost_delta,
                    'planned_revenue_amount' => $line->revenue_delta,
                ]);
            }

            $columns = array_map(static fn (string $value): string => Money::amount($value), $columns);
            $totalCost = Money::add($budget->total_cost_budget, $changeOrder->cost_impact_amount);
            $totalRevenue = Money::add($budget->total_revenue_budget, $changeOrder->revenue_impact_amount);
            $budget->update([...$columns, 'total_cost_budget' => Money::amount($totalCost), 'total_revenue_budget' => Money::amount($totalRevenue), ...$this->budgets->margin($totalRevenue, $totalCost)]);

            $nextVersion = (int) ProjectBudgetVersion::query()->where('project_budget_id', $budget->id)->max('version_number') + 1;
            ProjectBudgetVersion::create([
                'tenant_id' => $budget->tenant_id,
                'project_budget_id' => $budget->id,
                'version_number' => $nextVersion,
                'revision_reason' => "Change order {$changeOrder->order_number}",
                'snapshot_payload' => ['budget' => $budget->toArray(), 'change_order_id' => $changeOrder->id],
                'total_revenue_budget' => $budget->total_revenue_budget,
                'total_cost_budget' => $budget->total_cost_budget,
                'is_baseline' => false,
                'approved_by' => $approvedBy,
            ]);
            $budget->update(['current_version_number' => $nextVersion]);
        }

        $this->costingService->rollupProjectCosts($project);
        $this->audit->record($project, $approvedBy, 'change_order_applied', "Change order {$changeOrder->order_number} applied", $before, ['contract_value' => $project->contract_value, 'planned_end_date' => $project->planned_end_date?->toDateString()], $changeOrder);
    }

    /**
     * @throws ValidationException
     */
    private function assertLinesMatch(ChangeOrderDTO $dto): void
    {
        if ($dto->lines === []) {
            return;
        }

        $costDeltas = array_map(static fn (array $line): string => Money::of($line['total_cost_delta']), $dto->lines);
        $revenueDeltas = array_map(static fn (array $line): string => Money::of($line['revenue_delta'] ?? 0), $dto->lines);

        if (Money::compare(Money::add(...$costDeltas), $dto->costImpactAmount) !== 0) {
            throw ValidationException::withMessages(['cost_impact_amount' => 'The cost impact must equal the sum of the cost deltas of the lines.']);
        }

        $revenueTotal = Money::add(...$revenueDeltas);

        if (! Money::isZero($revenueTotal) && Money::compare($revenueTotal, $dto->revenueImpactAmount) !== 0) {
            throw ValidationException::withMessages(['revenue_impact_amount' => 'The revenue impact must equal the sum of the revenue deltas of the lines.']);
        }
    }
}
