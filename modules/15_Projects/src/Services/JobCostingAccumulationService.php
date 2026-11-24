<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Events\ProjectBudgetExceededEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectCostAllocation;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectPhase;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Job costing (contabilità di commessa): books the costs of a project and keeps its financial summary in step.
 *
 * Every cost entry is serialised on the project row, so two postings of the same project never interleave and a source
 * document (timesheet, receipt, stock issue) is booked at most once. Approved entries are rolled up into the summary
 * under a row lock; for projects that recognise revenue over time or at completion the cost is also capitalised into
 * work in progress in the General Ledger (ProjectCostReadyEvent, debit 1250 / credit 5900).
 */
final readonly class JobCostingAccumulationService implements JobCostingAccumulationServiceInterface
{
    public const string WipAccountCode = '1250';

    public const string CapitalisedCostOffsetAccountCode = '5900';

    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
        private ProjectCommitmentService $commitments,
        private ProjectInvoiceReader $invoices,
    ) {}

    /**
     * Books a cost on the project. A cost already booked for the same source document is returned unchanged.
     *
     * @throws ValidationException when the category, task, phase or allocations do not fit the project
     * @throws ConflictHttpException when the project is closed or cancelled
     */
    public function recordCost(RecordJobCostDTO $dto): ProjectCostEntry
    {
        return DB::transaction(function () use ($dto): ProjectCostEntry {
            $project = Project::query()->whereKey($dto->projectId)->lockForUpdate()->firstOrFail();

            $existing = ProjectCostEntry::query()
                ->where('project_id', $project->id)
                ->where('cost_source_type', $dto->costSourceType)
                ->where('cost_source_id', $dto->costSourceId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if (! $project->isOpen()) {
                throw new ConflictHttpException(__('projects_errors.cost.project_closed', ['number' => $project->project_number, 'status' => $project->status]));
            }

            $this->assertFitsProject($project, $dto);

            $entry = ProjectCostEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'project_phase_id' => $dto->phaseId,
                'project_task_id' => $dto->taskId,
                'cost_source_type' => $dto->costSourceType,
                'cost_source_id' => $dto->costSourceId,
                'cost_category' => $dto->costCategory,
                'description' => $dto->description,
                'quantity' => Money::round($dto->quantity, 4),
                'uom' => $dto->uom,
                'unit_cost' => Money::round($dto->unitCost, 4),
                'total_cost' => Money::amount($dto->totalCost),
                'is_billable' => $dto->isBillable,
                'is_invoiced' => false,
                'incurred_date' => $dto->incurredDate,
                'approval_status' => $dto->approvalStatus,
                'approved_by' => $dto->approvalStatus === 'approved' ? $dto->userId : null,
                'approved_at' => $dto->approvalStatus === 'approved' ? now() : null,
            ]);

            $this->allocate($entry, $dto->allocations);

            $actor = $dto->userId ?? (string) $project->project_manager_user_id;
            $this->audit->record(
                $project,
                $actor,
                'cost_entry_recorded',
                "Recorded {$dto->costCategory} cost of {$entry->total_cost} from {$dto->costSourceType} ({$dto->approvalStatus})",
                [],
                ['cost_entry_id' => $entry->id, 'total_cost' => $entry->total_cost, 'approval_status' => $entry->approval_status],
                $entry
            );

            if ($entry->approval_status === 'approved') {
                $this->onApproved($project, $entry);
            }

            return $entry->load('allocations');
        });
    }

    /**
     * Approves a pending cost entry (an expense): from now on it counts in the actual cost.
     */
    public function approveEntry(ProjectCostEntry $entry, string $approverUserId): ProjectCostEntry
    {
        return DB::transaction(function () use ($entry, $approverUserId): ProjectCostEntry {
            $project = Project::query()->whereKey($entry->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectCostEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'pending') {
                throw new ConflictHttpException(__('projects_errors.cost.approve_only_pending', ['status' => $locked->approval_status]));
            }

            $locked->update(['approval_status' => 'approved', 'approved_by' => $approverUserId, 'approved_at' => now(), 'rejection_reason' => null]);
            $this->audit->record($project, $approverUserId, 'cost_entry_approved', "Approved {$locked->cost_category} cost of {$locked->total_cost}", ['approval_status' => 'pending'], ['approval_status' => 'approved'], $locked);
            $this->onApproved($project, $locked);

            return $locked;
        });
    }

    /**
     * Rejects a pending cost entry: it stays on record but never counts in the actual cost.
     */
    public function rejectEntry(ProjectCostEntry $entry, string $approverUserId, string $reason): ProjectCostEntry
    {
        return DB::transaction(function () use ($entry, $approverUserId, $reason): ProjectCostEntry {
            $project = Project::query()->whereKey($entry->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectCostEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'pending') {
                throw new ConflictHttpException(__('projects_errors.cost.reject_only_pending', ['status' => $locked->approval_status]));
            }

            $locked->update(['approval_status' => 'rejected', 'approved_by' => $approverUserId, 'approved_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->record($project, $approverUserId, 'cost_entry_rejected', "Rejected {$locked->cost_category} cost of {$locked->total_cost}: {$reason}", ['approval_status' => 'pending'], ['approval_status' => 'rejected'], $locked);

            return $locked;
        });
    }

    /**
     * Rebuilds the financial summary of the project from its approved cost entries, open commitments and issued
     * invoices, under a lock of the summary row. Announces a budget overrun the first time the actual cost exceeds
     * the approved cost budget.
     */
    public function rollupProjectCosts(Project $project): ProjectFinancialSummary
    {
        return DB::transaction(function () use ($project): ProjectFinancialSummary {
            ProjectFinancialSummary::query()->firstOrCreate(
                ['project_id' => $project->id],
                ['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'total_contract_revenue' => $project->contract_value]
            );

            $summary = ProjectFinancialSummary::query()->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $previousActual = Money::of($summary->total_actual_cost);

            $byCategory = $this->actualCostByCategory($project);
            $totalActual = Money::add(...array_values($byCategory));
            $contractValue = Money::of($project->contract_value);
            $grossProfit = Money::sub($contractValue, $totalActual);
            $marginRatio = Money::div($grossProfit, $contractValue);

            $summary->update([
                'total_contract_revenue' => Money::amount($contractValue),
                'total_invoiced_revenue' => $this->invoices->billedRevenue($project),
                'actual_material_cost' => Money::amount($byCategory['material']),
                'actual_labour_cost' => Money::amount($byCategory['labour']),
                'actual_supplier_cost' => Money::amount($byCategory['supplier']),
                'actual_fleet_cost' => Money::amount(Money::add($byCategory['fleet'], $byCategory['equipment'])),
                'actual_expense_cost' => Money::amount($byCategory['expense']),
                'total_actual_cost' => Money::amount($totalActual),
                'committed_po_cost' => $this->commitments->openCommitments($project),
                'gross_profit_amount' => Money::amount($grossProfit),
                'gross_profit_percentage' => $this->boundedPercent($marginRatio),
                'last_recalculated_at' => now(),
            ]);

            $this->announceBudgetOverrun($project, $previousActual, $totalActual);

            return $summary;
        });
    }

    /**
     * Budget against actual cost by category, without side effects.
     *
     * @return array<string, array{planned: string, actual: string, delta: string, delta_percentage: string|null}>
     */
    public function analyzeCostVariance(Project $project): array
    {
        $budget = ProjectBudget::query()->where('project_id', $project->id)->first();
        $actual = $this->actualCostByCategory($project);
        $actual['fleet'] = Money::add($actual['fleet'], $actual['equipment']);
        $variances = [];

        foreach (['labour', 'material', 'supplier', 'fleet', 'expense'] as $category) {
            $planned = $budget !== null ? Money::of($budget->getAttribute(ProjectBudget::CategoryColumns[$category])) : Money::of(0);
            $delta = Money::sub($actual[$category], $planned);

            $variances[$category] = [
                'planned' => Money::amount($planned),
                'actual' => Money::amount($actual[$category]),
                'delta' => Money::amount($delta),
                'delta_percentage' => Money::percent(Money::div($delta, $planned)),
            ];
        }

        return $variances;
    }

    /**
     * Approved actual cost of the project by category, as decimal strings.
     *
     * @return array<string, string>
     */
    public function actualCostByCategory(Project $project): array
    {
        $totals = array_fill_keys(ProjectCostEntry::Categories, Money::of(0));

        ProjectCostEntry::query()
            ->approved()
            ->where('project_id', $project->id)
            ->selectRaw('cost_category, SUM(total_cost) as category_total')
            ->groupBy('cost_category')
            ->get()
            ->each(function (ProjectCostEntry $row) use (&$totals): void {
                $category = (string) $row->cost_category;
                $totals[$category] = Money::add($totals[$category] ?? 0, (string) $row->getAttribute('category_total'));
            });

        return $totals;
    }

    /**
     * Rolls the summary up and, for projects with a revenue recognition method, capitalises the cost into WIP.
     */
    private function onApproved(Project $project, ProjectCostEntry $entry): void
    {
        $this->rollupProjectCosts($project);

        if ($project->revenue_recognition_method === null || Money::isZero($entry->total_cost)) {
            return;
        }

        $this->dispatcher->publishAndDispatch(new ProjectCostReadyEvent(
            costEntryId: (string) $entry->id,
            projectId: (string) $project->id,
            costCategory: (string) $entry->cost_category,
            totalCost: Money::amount($entry->total_cost),
            debitAccountCode: self::WipAccountCode,
            creditAccountCode: self::CapitalisedCostOffsetAccountCode,
            incurredDate: $entry->incurred_date->toDateString(),
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id)
        ));
    }

    private function announceBudgetOverrun(Project $project, string $previousActual, string $totalActual): void
    {
        $budget = ProjectBudget::query()->where('project_id', $project->id)->where('status', 'approved')->first();

        if ($budget === null || ! Money::isPositive($budget->total_cost_budget)) {
            return;
        }

        $budgetCost = Money::of($budget->total_cost_budget);
        $crossed = Money::compare($totalActual, $budgetCost) > 0 && Money::compare($previousActual, $budgetCost) <= 0;

        if (! $crossed) {
            return;
        }

        $this->dispatcher->publishAndDispatch(new ProjectBudgetExceededEvent(
            projectId: (string) $project->id,
            projectNumber: (string) $project->project_number,
            budgetCost: (float) Money::amount($budgetCost),
            actualCost: (float) Money::amount($totalActual),
            varianceAmount: (float) Money::amount(Money::sub($totalActual, $budgetCost)),
            consumptionPercentage: (float) (Money::percent(Money::div($totalActual, $budgetCost)) ?? '0'),
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id)
        ));
    }

    /**
     * @throws ValidationException
     */
    private function assertFitsProject(Project $project, RecordJobCostDTO $dto): void
    {
        $errors = [];

        if (! in_array($dto->costCategory, ProjectCostEntry::Categories, true)) {
            $errors['cost_category'] = __('projects_errors.cost.category_invalid');
        }

        if (Money::isNegative($dto->totalCost)) {
            $errors['total_cost'] = __('projects_errors.cost.total_negative');
        }

        if ($dto->taskId !== null && ! ProjectTask::query()->whereKey($dto->taskId)->where('project_id', $project->id)->exists()) {
            $errors['task_id'] = __('projects_errors.task.not_in_project');
        }

        if ($dto->phaseId !== null && ! ProjectPhase::query()->whereKey($dto->phaseId)->where('project_id', $project->id)->exists()) {
            $errors['phase_id'] = __('projects_errors.phase.not_in_project');
        }

        if ($dto->allocations !== []) {
            $taskIds = array_map(static fn (array $allocation): string => (string) $allocation['task_id'], $dto->allocations);
            $known = ProjectTask::query()->whereIn('id', $taskIds)->where('project_id', $project->id)->count();
            $percentages = array_map(static fn (array $allocation): string => Money::of($allocation['percentage']), $dto->allocations);

            if ($known !== count(array_unique($taskIds))) {
                $errors['allocations'] = __('projects_errors.cost.allocation_needs_task');
            } elseif (Money::compare(Money::add(...$percentages), 100) !== 0) {
                $errors['allocations'] = __('projects_errors.cost.allocation_not_100');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Splits the cost over the tasks; the last share takes the rounding residual so the shares add up to the total.
     *
     * @param  array<int, array{task_id: string, percentage: string|int|float}>  $allocations
     */
    private function allocate(ProjectCostEntry $entry, array $allocations): void
    {
        $remaining = Money::of($entry->total_cost);
        $last = count($allocations) - 1;

        foreach (array_values($allocations) as $index => $allocation) {
            $amount = $index === $last
                ? Money::amount($remaining)
                : Money::amount(Money::div(Money::mul($entry->total_cost, $allocation['percentage']), 100));
            $remaining = Money::sub($remaining, $amount);

            ProjectCostAllocation::create([
                'tenant_id' => $entry->tenant_id,
                'project_cost_entry_id' => $entry->id,
                'project_task_id' => $allocation['task_id'],
                'allocation_percentage' => Money::amount($allocation['percentage']),
                'allocated_amount' => $amount,
            ]);
        }
    }

    /**
     * Percentage stored in a decimal(5,2) column: the margin of a loss-making project can fall below -999.99.
     */
    private function boundedPercent(?string $ratio): string
    {
        $percent = Money::percent($ratio) ?? '0.00';

        return Money::amount(Money::max(Money::min($percent, '999.99'), '-999.99'));
    }
}
