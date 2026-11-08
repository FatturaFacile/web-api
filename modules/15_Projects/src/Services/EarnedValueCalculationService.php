<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Events\ProjectMarginCalculatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectEvmSnapshot;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\Money;

/**
 * Earned value management (EVM) of a project, as defined in the PMI standard:
 *
 *  BAC  budget at completion: the approved cost budget (ProjectBudgetService::budgetAtCompletion)
 *  PV   planned value  = BAC x planned % complete at the date
 *  EV   earned value   = BAC x actual % complete
 *  AC   actual cost    = approved cost entries
 *  CV = EV - AC, SV = EV - PV, CPI = EV / AC, SPI = EV / PV
 *  EAC  estimate at completion = BAC / CPI (BAC while CPI is undefined), ETC = EAC - AC (not below 0), VAC = BAC - EAC
 *
 * The % complete is the average progress of the tasks weighted by their estimated hours; without estimated tasks it is
 * the progress percentage of the project. The planned % complete weights each task by the share of its baseline
 * (or, without baseline, planned) duration elapsed at the date; without tasks the project dates are used linearly.
 * Each calculation is stored as the snapshot of the day; health is red below 0.85 and yellow below 0.95 of CPI or SPI.
 */
final readonly class EarnedValueCalculationService implements EarnedValueCalculationServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectBudgetService $budgets,
        private JobCostingAccumulationServiceInterface $costing,
    ) {}

    public function recalculateEVM(Project $project, ?string $calculatedBy = null): ProjectFinancialSummary
    {
        return DB::transaction(function () use ($project, $calculatedBy): ProjectFinancialSummary {
            $summary = $this->costing->rollupProjectCosts($project);
            $summary = ProjectFinancialSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();
            $metrics = $this->metrics($project, CarbonImmutable::today(), (string) $summary->total_actual_cost);

            $project->update(['health_status' => $metrics['health_status']]);

            $summary->update([
                'budget_at_completion_bac' => $metrics['budget_at_completion'],
                'planned_value_pv' => $metrics['planned_value'],
                'earned_value_ev' => $metrics['earned_value'],
                'cost_performance_index_cpi' => $this->indexForSummary($metrics['cost_performance_index']),
                'schedule_performance_index_spi' => $this->indexForSummary($metrics['schedule_performance_index']),
                'estimate_at_completion_eac' => $metrics['estimate_at_completion'],
                'estimate_to_complete_etc' => $metrics['estimate_to_complete'],
                'variance_at_completion_vac' => $metrics['variance_at_completion'],
                'last_recalculated_at' => now(),
            ]);

            $snapshot = ProjectEvmSnapshot::query()->where('project_id', $project->id)->whereDate('snapshot_date', $metrics['date'])->first()
                ?? new ProjectEvmSnapshot(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'snapshot_date' => $metrics['date']]);
            $snapshot->fill([
                'percent_complete' => $metrics['percent_complete'],
                'planned_percent_complete' => $metrics['planned_percent_complete'],
                'budget_at_completion' => $metrics['budget_at_completion'],
                'planned_value' => $metrics['planned_value'],
                'earned_value' => $metrics['earned_value'],
                'actual_cost' => $metrics['actual_cost'],
                'cost_variance' => $metrics['cost_variance'],
                'schedule_variance' => $metrics['schedule_variance'],
                'cost_performance_index' => $metrics['cost_performance_index'],
                'schedule_performance_index' => $metrics['schedule_performance_index'],
                'estimate_at_completion' => $metrics['estimate_at_completion'],
                'estimate_to_complete' => $metrics['estimate_to_complete'],
                'variance_at_completion' => $metrics['variance_at_completion'],
                'health_status' => $metrics['health_status'],
                'calculated_by' => $calculatedBy,
            ])->save();

            $this->dispatcher->publishAndDispatch(new ProjectMarginCalculatedEvent(
                projectId: (string) $project->id,
                contractRevenue: (float) $summary->total_contract_revenue,
                totalActualCost: (float) $summary->total_actual_cost,
                grossMarginAmount: (float) $summary->gross_profit_amount,
                grossMarginPercentage: (float) $summary->gross_profit_percentage,
                cpi: (float) ($metrics['cost_performance_index'] ?? '1'),
                spi: (float) ($metrics['schedule_performance_index'] ?? '1'),
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            return $summary;
        });
    }

    /**
     * EVM figures of the project at a date, without storing them.
     *
     * @return array{date: string, percent_complete: string, planned_percent_complete: string, budget_at_completion: string, planned_value: string, earned_value: string, actual_cost: string, cost_variance: string, schedule_variance: string, cost_performance_index: string|null, schedule_performance_index: string|null, estimate_at_completion: string, estimate_to_complete: string, variance_at_completion: string, health_status: string}
     */
    public function metrics(Project $project, CarbonImmutable $asOf, string $actualCost): array
    {
        $bac = $this->budgets->budgetAtCompletion($project);
        [$actualRatio, $plannedRatio] = $this->completionRatios($project, $asOf);

        $ev = Money::mul($bac, $actualRatio);
        $pv = Money::mul($bac, $plannedRatio);
        $ac = Money::of($actualCost);
        $cpi = Money::div($ev, $ac);
        $spi = Money::div($ev, $pv);
        $eac = $cpi !== null && Money::isPositive($cpi) ? (Money::div($bac, $cpi) ?? $bac) : Money::max($bac, $ac);
        $etc = Money::max(Money::sub($eac, $ac), 0);

        return [
            'date' => $asOf->toDateString(),
            'percent_complete' => Money::percent($actualRatio) ?? '0.00',
            'planned_percent_complete' => Money::percent($plannedRatio) ?? '0.00',
            'budget_at_completion' => Money::amount($bac),
            'planned_value' => Money::amount($pv),
            'earned_value' => Money::amount($ev),
            'actual_cost' => Money::amount($ac),
            'cost_variance' => Money::amount(Money::sub($ev, $ac)),
            'schedule_variance' => Money::amount(Money::sub($ev, $pv)),
            'cost_performance_index' => $cpi === null ? null : Money::round($cpi, 4),
            'schedule_performance_index' => $spi === null ? null : Money::round($spi, 4),
            'estimate_at_completion' => Money::amount($eac),
            'estimate_to_complete' => Money::amount($etc),
            'variance_at_completion' => Money::amount(Money::sub($bac, $eac)),
            'health_status' => $this->health($cpi, $spi),
        ];
    }

    /**
     * Actual and planned completion ratios (0..1) of the project at the date.
     *
     * @return array{0: string, 1: string}
     */
    private function completionRatios(Project $project, CarbonImmutable $asOf): array
    {
        $tasks = ProjectTask::query()->where('project_id', $project->id)->where('estimated_hours', '>', 0)->get();

        if ($tasks->isEmpty()) {
            $actual = Money::div($project->progress_percentage, 100) ?? '0';
            $planned = $this->elapsedRatio($project->planned_start_date, $project->planned_end_date, $asOf);

            return [$actual, $planned];
        }

        $weights = Money::of(0);
        $earned = Money::of(0);
        $planned = Money::of(0);

        foreach ($tasks as $task) {
            $weight = Money::of($task->estimated_hours);
            $weights = Money::add($weights, $weight);
            $earned = Money::add($earned, Money::mul($weight, Money::div($task->progress_percentage, 100) ?? '0'));
            $planned = Money::add($planned, Money::mul($weight, $this->elapsedRatio($task->baseline_start_date ?? $task->planned_start_date, $task->baseline_end_date ?? $task->planned_end_date, $asOf)));
        }

        return [Money::div($earned, $weights) ?? '0', Money::div($planned, $weights) ?? '0'];
    }

    /**
     * Share of a period elapsed at the date, both ends included, between 0 and 1.
     */
    private function elapsedRatio(mixed $start, mixed $end, CarbonImmutable $asOf): string
    {
        if ($start === null || $end === null) {
            return '0';
        }

        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        if ($asOf->lessThan($from)) {
            return '0';
        }

        if ($asOf->greaterThanOrEqualTo($to)) {
            return '1';
        }

        $total = (int) $from->diffInDays($to) + 1;
        $elapsed = (int) $from->diffInDays($asOf) + 1;

        return Money::div($elapsed, $total) ?? '0';
    }

    private function health(?string $cpi, ?string $spi): string
    {
        $indices = array_filter([$cpi, $spi], static fn (?string $index): bool => $index !== null);

        foreach ($indices as $index) {
            if (Money::compare($index, '0.85') < 0) {
                return 'red';
            }
        }

        foreach ($indices as $index) {
            if (Money::compare($index, '0.95') < 0) {
                return 'yellow';
            }
        }

        return 'green';
    }

    /**
     * The summary keeps CPI and SPI in decimal(5,2) with 1.00 as neutral default; undefined indices stay neutral.
     */
    private function indexForSummary(?string $index): string
    {
        return $index === null ? '1.00' : Money::amount(Money::min($index, '999.99'));
    }
}
