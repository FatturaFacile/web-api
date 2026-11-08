<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectBudgetLine;
use Modules\Projects\Models\ProjectBudgetVersion;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Project budget by cost category, its approval and its baselines.
 *
 * A saved budget is a draft (or waits for the approval workflow when the company configured PROJECT_BUDGET_APPROVAL).
 * Approval freezes a baseline version; the budget at completion used by the earned value analysis is the approved
 * budget, or the last baseline while a revision waits for approval. The total cost budget is always the sum of the
 * categories, contingency included, so the categories and the total can never disagree.
 */
final readonly class ProjectBudgetService implements ProjectBudgetServiceInterface
{
    public function __construct(
        private ProjectAuditTrail $audit,
        private ProjectApprovalRouter $approvals,
        private ProjectCommitmentService $commitments,
        private JobCostingAccumulationServiceInterface $costing,
    ) {}

    /**
     * Saves the budget of the project as a new draft revision and routes it to the workflow when configured.
     *
     * @throws ConflictHttpException when the project is closed or the budget waits for a workflow decision
     */
    public function createOrUpdateBudget(ProjectBudgetDTO $dto): ProjectBudget
    {
        return DB::transaction(function () use ($dto): ProjectBudget {
            $project = Project::query()->whereKey($dto->projectId)->lockForUpdate()->firstOrFail();

            if (! $project->isOpen()) {
                throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: its budget cannot change.");
            }

            $budget = ProjectBudget::query()->where('project_id', $project->id)->lockForUpdate()->first();

            if ($budget?->status === 'pending_approval') {
                throw new ConflictHttpException('The budget is waiting for the approval workflow and cannot be changed now.');
            }

            $categories = [
                'labour_budget' => Money::amount($dto->labourBudget),
                'material_budget' => Money::amount($dto->materialBudget),
                'supplier_budget' => Money::amount($dto->supplierBudget),
                'fleet_budget' => Money::amount($dto->fleetBudget),
                'expense_budget' => Money::amount($dto->expenseBudget),
                'contingency_budget' => Money::amount($dto->contingencyBudget),
            ];
            $totalCost = Money::add(...array_values($categories));
            $before = $budget?->only(['status', 'total_cost_budget', 'total_revenue_budget']) ?? [];

            $budget ??= new ProjectBudget(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'current_version_number' => 0]);
            $budget->fill([
                'company_id' => $project->company_id,
                'status' => 'draft',
                'total_revenue_budget' => Money::amount($dto->totalRevenueBudget),
                'total_cost_budget' => Money::amount($totalCost),
                ...$categories,
                ...$this->margin($dto->totalRevenueBudget, $totalCost),
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $budget->save();

            if ($dto->lines !== []) {
                $budget->lines()->delete();

                foreach ($dto->lines as $line) {
                    $total = isset($line['planned_total_cost']) ? Money::of($line['planned_total_cost']) : Money::mul($line['planned_quantity'], $line['planned_unit_cost']);

                    ProjectBudgetLine::create([
                        'tenant_id' => $project->tenant_id,
                        'project_budget_id' => $budget->id,
                        'project_phase_id' => $line['phase_id'] ?? null,
                        'cost_category' => $line['cost_category'],
                        'description' => $line['description'],
                        'planned_quantity' => Money::round($line['planned_quantity'], 4),
                        'planned_unit_cost' => Money::round($line['planned_unit_cost'], 4),
                        'planned_total_cost' => Money::amount($total),
                        'planned_revenue_amount' => Money::amount($line['planned_revenue_amount'] ?? 0),
                    ]);
                }
            }

            $actor = $dto->userId ?? (string) $project->project_manager_user_id;
            $routed = $this->approvals->routeIfConfigured(
                ProjectApprovalRouter::BudgetWorkflow,
                $budget,
                ['project_id' => $project->id, 'project_number' => $project->project_number, 'cost_budget' => $budget->total_cost_budget, 'revenue_budget' => $budget->total_revenue_budget],
                (string) $project->tenant_id,
                $project->company_id,
                $actor
            );

            if ($routed) {
                $budget->update(['status' => 'pending_approval']);
            }

            $this->audit->record($project, $actor, 'budget_defined', "Budget revised: cost {$budget->total_cost_budget}, revenue {$budget->total_revenue_budget} ({$budget->status})", $before, $budget->only(['status', 'total_cost_budget', 'total_revenue_budget']), $budget);

            return $budget;
        });
    }

    /**
     * Approves a draft or pending budget and freezes a baseline version of it.
     *
     * @throws ConflictHttpException when the budget is already approved
     */
    public function approveBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget
    {
        return DB::transaction(function () use ($budget, $approvedBy): ProjectBudget {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'pending_approval'], true)) {
                throw new ConflictHttpException("The budget is {$locked->status}: only a draft or pending budget can be approved.");
            }

            return $this->freeze($locked, $approvedBy, 'Baseline frozen upon approval');
        });
    }

    /**
     * Freezes the approved budget as a new baseline (re-baselining, for instance after approved change orders).
     *
     * @throws ConflictHttpException when the budget is not approved
     */
    public function baselineBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget
    {
        return DB::transaction(function () use ($budget, $approvedBy): ProjectBudget {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new ConflictHttpException('Only an approved budget can be re-baselined: approve it first.');
            }

            return $this->freeze($locked, $approvedBy, 'Re-baseline of the approved budget');
        });
    }

    /**
     * Rejects a draft or pending budget (manager decision or workflow rejection).
     */
    public function rejectBudget(ProjectBudget $budget, string $rejectedBy, ?string $reason = null): ProjectBudget
    {
        return DB::transaction(function () use ($budget, $rejectedBy, $reason): ProjectBudget {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'pending_approval'], true)) {
                throw new ConflictHttpException("The budget is {$locked->status}: only a draft or pending budget can be rejected.");
            }

            $locked->update(['status' => 'rejected']);
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->audit->record($project, $rejectedBy, 'budget_rejected', 'Budget rejected'.($reason ? ": {$reason}" : ''), ['status' => 'pending_approval'], ['status' => 'rejected'], $locked);

            return $locked;
        });
    }

    /**
     * The budget at completion (BAC): the approved cost budget, or the last baseline while a revision is pending.
     */
    public function budgetAtCompletion(Project $project): string
    {
        $budget = ProjectBudget::query()->where('project_id', $project->id)->first();

        if ($budget === null) {
            return Money::amount(0);
        }

        if ($budget->status === 'approved') {
            return Money::amount($budget->total_cost_budget);
        }

        $baseline = ProjectBudgetVersion::query()->where('project_budget_id', $budget->id)->where('is_baseline', true)->orderByDesc('version_number')->first();

        return Money::amount($baseline->total_cost_budget ?? 0);
    }

    /**
     * Budget, open commitments, actual cost and remaining budget per category, with totals.
     *
     * @return array{categories: list<array{category: string, budget: string, committed: string, actual: string, available: string, consumed_percentage: string|null}>, totals: array{budget: string, committed: string, actual: string, available: string, consumed_percentage: string|null}}
     */
    public function categoryReport(Project $project): array
    {
        $budget = ProjectBudget::query()->where('project_id', $project->id)->first();
        $actual = $this->costing->actualCostByCategory($project);
        $actual['fleet'] = Money::add($actual['fleet'], $actual['equipment']);
        $actual['contingency'] = Money::of(0);
        $committed = $this->commitments->openCommitments($project);

        $rows = [];
        $totals = ['budget' => '0', 'committed' => '0', 'actual' => '0'];

        foreach (ProjectBudget::CategoryColumns as $category => $column) {
            $planned = Money::of($budget?->getAttribute($column) ?? 0);
            $categoryCommitted = $category === 'supplier' ? $committed : '0';
            $available = Money::sub(Money::sub($planned, $actual[$category]), $categoryCommitted);

            $rows[] = [
                'category' => $category,
                'budget' => Money::amount($planned),
                'committed' => Money::amount($categoryCommitted),
                'actual' => Money::amount($actual[$category]),
                'available' => Money::amount($available),
                'consumed_percentage' => Money::percent(Money::div(Money::add($actual[$category], $categoryCommitted), $planned)),
            ];

            $totals['budget'] = Money::add($totals['budget'], $planned);
            $totals['committed'] = Money::add($totals['committed'], $categoryCommitted);
            $totals['actual'] = Money::add($totals['actual'], $actual[$category]);
        }

        return [
            'categories' => $rows,
            'totals' => [
                'budget' => Money::amount($totals['budget']),
                'committed' => Money::amount($totals['committed']),
                'actual' => Money::amount($totals['actual']),
                'available' => Money::amount(Money::sub(Money::sub($totals['budget'], $totals['actual']), $totals['committed'])),
                'consumed_percentage' => Money::percent(Money::div(Money::add($totals['actual'], $totals['committed']), $totals['budget'])),
            ],
        ];
    }

    /**
     * Projected margin of a budget; the percentage is null-safe and bounded to the decimal(5,2) column.
     *
     * @return array{projected_margin_amount: string, projected_margin_percentage: string}
     */
    public function margin(string $revenue, string $cost): array
    {
        $margin = Money::sub($revenue, $cost);
        $percent = Money::percent(Money::div($margin, $revenue)) ?? '0';

        return [
            'projected_margin_amount' => Money::amount($margin),
            'projected_margin_percentage' => Money::amount(Money::max(Money::min($percent, '999.99'), '-999.99')),
        ];
    }

    private function freeze(ProjectBudget $budget, ?string $approvedBy, string $reason): ProjectBudget
    {
        $budget->loadMissing(['lines', 'project']);
        $project = $budget->project;
        $approver = $approvedBy ?: (string) $project?->project_manager_user_id;
        $nextVersion = (int) ProjectBudgetVersion::query()->where('project_budget_id', $budget->id)->max('version_number') + 1;

        $budget->update([
            'status' => 'approved',
            'current_version_number' => $nextVersion,
            'approved_by' => $approver,
            'approved_at' => now(),
        ]);

        ProjectBudgetVersion::create([
            'tenant_id' => $budget->tenant_id,
            'project_budget_id' => $budget->id,
            'version_number' => $nextVersion,
            'revision_reason' => $reason,
            'snapshot_payload' => ['budget' => $budget->toArray(), 'lines' => $budget->lines->toArray()],
            'total_revenue_budget' => $budget->total_revenue_budget,
            'total_cost_budget' => $budget->total_cost_budget,
            'is_baseline' => true,
            'approved_by' => $approver,
        ]);

        if ($project instanceof Project) {
            $this->audit->record($project, $approver, 'budget_baselined', "Budget version {$nextVersion} frozen: cost {$budget->total_cost_budget}", [], ['version' => $nextVersion, 'total_cost_budget' => $budget->total_cost_budget], $budget);
        }

        return $budget;
    }
}
