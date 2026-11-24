<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Queries\GetPortfolioEvmScurveQuery;
use Modules\Projects\Queries\GetProjectProfitabilityWaterfallQuery;
use Modules\Projects\Queries\GetWbsCostBreakdownQuery;
use Modules\Projects\Support\CostSourceType;
use Modules\Projects\Support\EvmCalculator;
use Modules\Projects\Support\Money;

/**
 * Read side of the profitability and earned value dashboards (Mod 082): handlers of the three queries. They read the
 * stored snapshots and the cost ledger only, so they are cheap and never change data.
 */
final readonly class ProjectEvmQueryService
{
    /**
     * Revenue, then each cost source, down to the gross margin, with the variance bridge BAC, EAC and VAC.
     *
     * @return array<string, mixed>
     */
    public function waterfall(GetProjectProfitabilityWaterfallQuery $query): array
    {
        $project = Project::query()->findOrFail($query->projectId);
        $snapshot = $this->latestSnapshot($project->id, null);
        $bySource = [];

        ProjectCostLedgerEntry::query()->where('project_id', $project->id)->get(['source_type', 'base_currency_actual_amount', 'base_currency_committed_amount'])
            ->each(function (ProjectCostLedgerEntry $entry) use (&$bySource): void {
                $bySource[$entry->source_type] = Money::add($bySource[$entry->source_type] ?? '0', $entry->base_currency_actual_amount);
            });

        $revenue = Money::of($snapshot->recognized_revenue ?? 0);
        $running = $revenue;
        $steps = [['key' => 'revenue', 'amount' => Money::amount($revenue), 'running_total' => Money::amount($running), 'kind' => 'total']];
        $cost = '0';

        foreach (CostSourceType::actualSources() as $source) {
            $amount = $bySource[$source->value] ?? '0';

            if ($source === CostSourceType::Adjustment && ! Money::isPositive(Money::abs($amount))) {
                continue;
            }

            $cost = Money::add($cost, $amount);
            $running = Money::sub($running, $amount);
            $steps[] = ['key' => $source->value, 'amount' => Money::amount(Money::neg($amount)), 'running_total' => Money::amount($running), 'kind' => 'delta'];
        }

        $steps[] = ['key' => 'gross_margin', 'amount' => Money::amount($running), 'running_total' => Money::amount($running), 'kind' => 'total'];
        $margin = EvmCalculator::margin($revenue, $cost);

        return [
            'project_id' => $project->id,
            'currency' => $snapshot->currency_code ?? $project->currency,
            'snapshot_date' => $snapshot?->snapshot_date->toDateString(),
            'recognized_revenue' => Money::amount($revenue),
            'total_cost' => Money::amount($cost),
            'gross_margin_amount' => Money::amount($margin['gross_margin_amount']),
            'gross_margin_percent' => $margin['gross_margin_percent'],
            'steps' => $steps,
            'variance_bridge' => [
                'budget_at_completion' => Money::amount($snapshot->budget_at_completion_bac ?? 0),
                'estimate_at_completion' => Money::amount($snapshot->estimate_at_completion_eac ?? 0),
                'variance_at_completion' => Money::amount($snapshot->variance_at_completion_vac ?? 0),
                'committed_cost' => Money::amount($snapshot->committed_cost ?? 0),
            ],
        ];
    }

    /**
     * Cumulative PV, EV and AC at the end of each period, plus the two points of the EAC projection from the last actual
     * date to the planned end. A portfolio adds up the latest snapshot of each project on or before each period end.
     *
     * @return array<string, mixed>
     */
    public function scurve(GetPortfolioEvmScurveQuery $query): array
    {
        $projects = Project::query()->when($query->projectIds !== [], fn ($builder) => $builder->whereIn('id', $query->projectIds))->get();
        $snapshots = ProjectFinancialSnapshot::query()->whereIn('project_id', $projects->pluck('id'))->whereNull('wbs_node_id')
            ->orderBy('snapshot_date')->get()->groupBy('project_id');

        if ($snapshots->isEmpty()) {
            return ['granularity' => $query->granularity, 'currency' => null, 'points' => [], 'forecast' => []];
        }

        $dates = $snapshots->flatten()->map(static fn (ProjectFinancialSnapshot $snapshot): CarbonImmutable => CarbonImmutable::instance($snapshot->snapshot_date));
        $from = $query->from !== null ? CarbonImmutable::parse($query->from) : $dates->min();
        $to = $query->to !== null ? CarbonImmutable::parse($query->to) : $dates->max();
        $points = [];

        foreach ($this->periodEnds($from, $to, $query->granularity) as $end) {
            $sum = ['pv' => '0', 'ev' => '0', 'ac' => '0'];
            $any = false;

            foreach ($snapshots as $series) {
                $latest = $series->last(static fn (ProjectFinancialSnapshot $snapshot): bool => $snapshot->snapshot_date->lessThanOrEqualTo($end));

                if ($latest !== null) {
                    $any = true;
                    $sum = ['pv' => Money::add($sum['pv'], $latest->planned_value_pv), 'ev' => Money::add($sum['ev'], $latest->earned_value_ev), 'ac' => Money::add($sum['ac'], $latest->actual_cost_ac)];
                }
            }

            if ($any) {
                $points[] = ['date' => $end->toDateString(), 'pv' => Money::amount($sum['pv']), 'ev' => Money::amount($sum['ev']), 'ac' => Money::amount($sum['ac'])];
            }
        }

        $latestAc = '0';
        $eac = '0';
        $bac = '0';
        $lastDate = $from;

        foreach ($snapshots as $series) {
            $last = $series->last();
            $latestAc = Money::add($latestAc, $last->actual_cost_ac);
            $eac = Money::add($eac, $last->estimate_at_completion_eac);
            $bac = Money::add($bac, $last->budget_at_completion_bac);
            $lastDate = $lastDate->max(CarbonImmutable::instance($last->snapshot_date));
        }

        $plannedEnd = $projects->map(static fn (Project $project): ?CarbonImmutable => $project->planned_end_date === null ? null : CarbonImmutable::instance($project->planned_end_date))->filter()->max() ?? $lastDate;

        return [
            'granularity' => $query->granularity,
            'currency' => $snapshots->flatten()->first()?->currency_code,
            'budget_at_completion' => Money::amount($bac),
            'points' => $points,
            'forecast' => [
                ['date' => $lastDate->toDateString(), 'eac' => Money::amount($latestAc)],
                ['date' => $plannedEnd->max($lastDate)->toDateString(), 'eac' => Money::amount($eac)],
            ],
        ];
    }

    /**
     * The WBS tree with the figures of its latest snapshot, the project as root.
     *
     * @return array<string, mixed>
     */
    public function wbsBreakdown(GetWbsCostBreakdownQuery $query): array
    {
        $project = Project::query()->findOrFail($query->projectId);
        $root = $this->latestSnapshot($project->id, null);
        $date = $root?->snapshot_date->toDateString();
        $snapshots = $date === null ? collect() : ProjectFinancialSnapshot::query()->where('project_id', $project->id)->whereDate('snapshot_date', $date)->whereNotNull('wbs_node_id')->get()->keyBy('wbs_node_id');
        $tasks = ProjectTask::query()->where('project_id', $project->id)->orderBy('sort_order')->get();

        $build = function (?string $parentId) use (&$build, $tasks, $snapshots): array {
            return $tasks->filter(static fn (ProjectTask $task): bool => ($task->parent_task_id === null ? null : (string) $task->parent_task_id) === $parentId)
                ->map(fn (ProjectTask $task): array => [
                    'wbs_node_id' => $task->id,
                    'wbs_code' => $task->wbs_code ?? $task->task_number,
                    'title' => $task->title,
                    ...$this->row($snapshots->get($task->id)),
                    'children' => $build((string) $task->id),
                ])->values()->all();
        };

        return [
            'project_id' => $project->id,
            'currency' => $root->currency_code ?? $project->currency,
            'snapshot_date' => $date,
            'project' => $this->row($root),
            'reconciliation' => $root?->reconciliation,
            'nodes' => $build(null),
        ];
    }

    private function latestSnapshot(string $projectId, ?string $nodeId): ?ProjectFinancialSnapshot
    {
        return ProjectFinancialSnapshot::query()->where('project_id', $projectId)
            ->when($nodeId === null, fn ($query) => $query->whereNull('wbs_node_id'), fn ($query) => $query->where('wbs_node_id', $nodeId))
            ->orderByDesc('snapshot_date')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?ProjectFinancialSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return ['has_snapshot' => false];
        }

        $cpi = $snapshot->cost_performance_index_cpi === null ? null : (string) $snapshot->cost_performance_index_cpi;
        $spi = $snapshot->schedule_performance_index_spi === null ? null : (string) $snapshot->schedule_performance_index_spi;

        return [
            'has_snapshot' => true,
            'bac' => Money::amount($snapshot->budget_at_completion_bac),
            'committed' => Money::amount($snapshot->committed_cost),
            'actual' => Money::amount($snapshot->actual_cost_ac),
            'total_exposure' => Money::amount(Money::add($snapshot->committed_cost, $snapshot->actual_cost_ac)),
            'pv' => Money::amount($snapshot->planned_value_pv),
            'ev' => Money::amount($snapshot->earned_value_ev),
            'cv' => Money::amount($snapshot->cost_variance_cv),
            'sv' => Money::amount($snapshot->schedule_variance_sv),
            'cpi' => $cpi,
            'spi' => $spi,
            'cpi_light' => EvmCalculator::trafficLight($cpi),
            'spi_light' => EvmCalculator::trafficLight($spi),
            'eac' => Money::amount($snapshot->estimate_at_completion_eac),
            'etc' => Money::amount($snapshot->estimate_to_complete_etc),
            'vac' => Money::amount($snapshot->variance_at_completion_vac),
            'budget_absorption_percent' => $snapshot->budget_absorption_percent === null ? null : (string) $snapshot->budget_absorption_percent,
            'recognized_revenue' => Money::amount($snapshot->recognized_revenue),
            'gross_margin_amount' => Money::amount($snapshot->gross_margin_amount),
            'gross_margin_percent' => $snapshot->gross_margin_percent === null ? null : (string) $snapshot->gross_margin_percent,
            'manual_etc_override' => $snapshot->manual_etc_override === null ? null : Money::amount($snapshot->manual_etc_override),
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function periodEnds(CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $ends = [];
        $cursor = match ($granularity) {
            'day' => $from->startOfDay(),
            'week' => $from->endOfWeek()->startOfDay(),
            default => $from->endOfMonth()->startOfDay(),
        };

        while ($cursor->lessThan($to->startOfDay()) && count($ends) < 1000) {
            $ends[] = $cursor;
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->endOfMonth()->startOfDay(),
            };
        }

        $ends[] = $to->startOfDay();

        return $ends;
    }
}
