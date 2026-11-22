<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Events\ProjectEvmSnapshotComputedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Models\ProjectEtcOverride;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\CostSourceType;
use Modules\Projects\Support\EvmCalculator;
use Modules\Projects\Support\Money;

/**
 * Computes the financial snapshot of a project and of every WBS node (Mod 082).
 *
 * The budget at completion of the project (approved cost budget) is spread over the work packages by their estimated
 * hours; a work package earns its share by its own progress and plans it by its baseline dates, and a parent node adds up
 * its children. The actual and committed cost come from the cost ledger (so they include the overhead allocated from
 * Mod 018 and the open purchase orders of Mod 046); cost without a WBS node counts at project level only. The recognised
 * revenue of the project is shared among the nodes in proportion to their budget. A manual bottom-up ETC on a node or on
 * the project replaces the BAC / CPI forecast of that node.
 *
 * The snapshots of the day are replaced on every computation; the alerts of Mod 008 are evaluated afterwards.
 */
final readonly class ProjectEvmSnapshotService
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectBudgetService $budgets,
        private EarnedValueCalculationService $evm,
        private ProjectCostLedgerSyncService $ledger,
        private ProjectProfitabilityService $profitability,
        private ProjectEvmAlertService $alerts,
    ) {}

    public function compute(Project $project, string $trigger = 'manual', ?string $asOf = null): ProjectFinancialSnapshot
    {
        $date = $asOf === null ? CarbonImmutable::today() : CarbonImmutable::parse($asOf)->startOfDay();

        /** @var array{snapshot: ProjectFinancialSnapshot, nodes: list<array<string, mixed>>} $result */
        $result = DB::transaction(function () use ($project, $trigger, $date): array {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->ledger->sync($project);

            $bac = $this->budgets->budgetAtCompletion($project);
            $ledger = $this->ledgerTotals($project);
            $nodes = $this->nodeFigures($project, $bac, $date, $ledger);
            $recognised = Money::of($this->profitability->report($project)['revenue']['recognised']);
            $overrides = $this->overrides($project);

            $leafBase = array_filter($nodes, static fn (array $node): bool => $node['is_leaf']);
            $weighted = $leafBase !== [];

            if ($weighted) {
                $bacTotal = Money::add(...array_column($leafBase, 'bac'));
                $evTotal = Money::add(...array_column($leafBase, 'ev'));
                $pvTotal = Money::add(...array_column($leafBase, 'pv'));
                $progress = Money::div($evTotal, $bacTotal) ?? '0';
                $planned = Money::div($pvTotal, $bacTotal) ?? '0';
            } else {
                [$progress, $planned] = $this->evm->completionRatios($project, $date);
            }

            $projectFigures = EvmCalculator::compute($bac, $progress, $planned, $ledger['project']['actual'], $overrides['project'] ?? null);
            $projectSnapshot = $this->store($project, null, $date, $trigger, $projectFigures, $ledger['project']['committed'], $recognised, $this->reconciliation($project, $ledger));

            $alertNodes = [['wbs_node_id' => null, 'wbs_label' => null, 'cpi' => $projectFigures['cost_performance_index'], 'spi' => $projectFigures['schedule_performance_index'], 'absorption' => $projectFigures['budget_absorption_percent']]];

            foreach ($nodes as $id => $node) {
                if (! Money::isPositive($node['bac']) && ! Money::isPositive($node['ac'])) {
                    continue;
                }

                $ratio = static fn (string $part): string => Money::isPositive($node['bac']) ? (Money::div($part, $node['bac']) ?? '0') : '0';
                $figures = EvmCalculator::compute($node['bac'], $ratio($node['ev']), $ratio($node['pv']), $node['ac'], $overrides['nodes'][$id] ?? null);
                $revenue = Money::isPositive($bac) ? Money::mul($recognised, Money::div($node['bac'], $bac) ?? '0') : '0';
                $this->store($project, $id, $date, $trigger, $figures, $node['committed'], $revenue, null);
                $alertNodes[] = ['wbs_node_id' => $id, 'wbs_label' => $node['label'], 'cpi' => $figures['cost_performance_index'], 'spi' => $figures['schedule_performance_index'], 'absorption' => $figures['budget_absorption_percent']];
            }

            return ['snapshot' => $projectSnapshot, 'nodes' => $alertNodes];
        });

        $this->alerts->evaluate($project, $result['nodes']);
        $snapshot = $result['snapshot'];

        $this->dispatcher->publishAndDispatch(new ProjectEvmSnapshotComputedEvent(
            projectId: (string) $project->id,
            snapshotDate: $date->toDateString(),
            figures: [
                'bac' => (string) $snapshot->budget_at_completion_bac,
                'ev' => (string) $snapshot->earned_value_ev,
                'ac' => (string) $snapshot->actual_cost_ac,
                'cpi' => $snapshot->cost_performance_index_cpi === null ? null : (string) $snapshot->cost_performance_index_cpi,
                'spi' => $snapshot->schedule_performance_index_spi === null ? null : (string) $snapshot->schedule_performance_index_spi,
                'eac' => (string) $snapshot->estimate_at_completion_eac,
                'gross_margin_percent' => $snapshot->gross_margin_percent === null ? null : (string) $snapshot->gross_margin_percent,
            ],
            trigger: $trigger,
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id),
        ));

        return $snapshot;
    }

    /**
     * Base-currency committed and actual cost of the ledger by WBS node, by source and for the whole project.
     *
     * @return array{project: array{actual: string, committed: string}, nodes: array<string, array{actual: string, committed: string}>}
     */
    public function ledgerTotals(Project $project): array
    {
        $totals = ['project' => ['actual' => '0', 'committed' => '0'], 'nodes' => []];

        ProjectCostLedgerEntry::query()->where('project_id', $project->id)->get(['wbs_node_id', 'base_currency_actual_amount', 'base_currency_committed_amount'])
            ->each(function (ProjectCostLedgerEntry $entry) use (&$totals): void {
                $totals['project']['actual'] = Money::add($totals['project']['actual'], $entry->base_currency_actual_amount);
                $totals['project']['committed'] = Money::add($totals['project']['committed'], $entry->base_currency_committed_amount);

                if ($entry->wbs_node_id !== null) {
                    $node = $totals['nodes'][$entry->wbs_node_id] ?? ['actual' => '0', 'committed' => '0'];
                    $totals['nodes'][$entry->wbs_node_id] = [
                        'actual' => Money::add($node['actual'], $entry->base_currency_actual_amount),
                        'committed' => Money::add($node['committed'], $entry->base_currency_committed_amount),
                    ];
                }
            });

        return $totals;
    }

    /**
     * Budget, planned and earned value and cost of every WBS node, parents adding up their children.
     *
     * @param  array{project: array{actual: string, committed: string}, nodes: array<string, array{actual: string, committed: string}>}  $ledger
     * @return array<string, array{label: string, parent: string|null, is_leaf: bool, bac: string, pv: string, ev: string, ac: string, committed: string}>
     */
    private function nodeFigures(Project $project, string $bac, CarbonImmutable $date, array $ledger): array
    {
        $tasks = ProjectTask::query()->where('project_id', $project->id)->orderBy('sort_order')->get();
        $parents = $tasks->pluck('parent_task_id')->filter()->map(static fn (mixed $id): string => (string) $id)->unique()->flip();
        $weightOf = static fn (ProjectTask $task): string => $parents->has((string) $task->id) ? '0' : Money::of($task->estimated_hours);
        $weights = Money::add(...$tasks->map($weightOf)->all());
        $nodes = [];

        foreach ($tasks as $task) {
            $id = (string) $task->id;
            $isLeaf = ! $parents->has($id);
            $share = Money::isPositive($weights) ? (Money::div($weightOf($task), $weights) ?? '0') : '0';
            $taskBac = Money::mul($bac, $share);
            $actual = $ledger['nodes'][$id] ?? ['actual' => '0', 'committed' => '0'];

            $nodes[$id] = [
                'label' => trim(($task->wbs_code ?? $task->task_number).' '.$task->title),
                'parent' => $task->parent_task_id === null ? null : (string) $task->parent_task_id,
                'is_leaf' => $isLeaf && Money::isPositive($weightOf($task)),
                'bac' => $isLeaf ? $taskBac : '0',
                'pv' => $isLeaf ? Money::mul($taskBac, $this->evm->elapsedRatio($task->baseline_start_date ?? $task->planned_start_date, $task->baseline_end_date ?? $task->planned_end_date, $date)) : '0',
                'ev' => $isLeaf ? Money::mul($taskBac, Money::div($task->progress_percentage, 100) ?? '0') : '0',
                'ac' => $actual['actual'],
                'committed' => $actual['committed'],
            ];
        }

        // Parents add up the budget, value and cost of their descendants, deepest first.
        foreach ($this->parentsDeepestFirst($nodes) as $id) {
            foreach (['bac', 'pv', 'ev', 'ac', 'committed'] as $key) {
                $children = array_filter($nodes, static fn (array $node): bool => $node['parent'] === $id);
                $nodes[$id][$key] = Money::add($nodes[$id][$key], ...array_column($children, $key));
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string, array{parent: string|null}>  $nodes
     * @return list<string>
     */
    private function parentsDeepestFirst(array $nodes): array
    {
        $depth = static function (string $id) use ($nodes): int {
            $level = 0;
            $seen = [];

            while (($nodes[$id]['parent'] ?? null) !== null && ! isset($seen[$id])) {
                $seen[$id] = true;
                $id = (string) $nodes[$id]['parent'];
                $level++;
            }

            return $level;
        };

        $parentIds = array_values(array_unique(array_filter(array_column($nodes, 'parent'), static fn (?string $id): bool => $id !== null && isset($nodes[$id]))));
        usort($parentIds, static fn (string $a, string $b): int => $depth($b) <=> $depth($a));

        return $parentIds;
    }

    /**
     * Manual ETC of the project and of each node (the most recent override wins).
     *
     * @return array{project: string|null, nodes: array<string, string>}
     */
    private function overrides(Project $project): array
    {
        $result = ['project' => null, 'nodes' => []];

        ProjectEtcOverride::query()->where('project_id', $project->id)->orderBy('updated_at')->get()->each(function (ProjectEtcOverride $override) use (&$result): void {
            if ($override->wbs_node_id === null) {
                $result['project'] = (string) $override->estimate_to_complete;
            } else {
                $result['nodes'][$override->wbs_node_id] = (string) $override->estimate_to_complete;
            }
        });

        return $result;
    }

    /**
     * @param  array<string, string|null>  $figures
     * @param  array<string, mixed>|null  $reconciliation
     */
    private function store(Project $project, ?string $nodeId, CarbonImmutable $date, string $trigger, array $figures, string $committed, string $revenue, ?array $reconciliation): ProjectFinancialSnapshot
    {
        $margin = EvmCalculator::margin($revenue, (string) $figures['actual_cost']);

        $snapshot = ProjectFinancialSnapshot::query()->where('project_id', $project->id)
            ->when($nodeId === null, fn ($query) => $query->whereNull('wbs_node_id'), fn ($query) => $query->where('wbs_node_id', $nodeId))
            ->whereDate('snapshot_date', $date->toDateString())->first()
            ?? new ProjectFinancialSnapshot(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'wbs_node_id' => $nodeId, 'snapshot_date' => $date->toDateString()]);

        $snapshot->fill([
            'currency_code' => strtoupper((string) ($project->currency ?? 'EUR')),
            'budget_at_completion_bac' => $figures['budget_at_completion'],
            'planned_value_pv' => $figures['planned_value'],
            'earned_value_ev' => $figures['earned_value'],
            'actual_cost_ac' => $figures['actual_cost'],
            'committed_cost' => Money::round($committed, 4),
            'cost_variance_cv' => $figures['cost_variance'],
            'schedule_variance_sv' => $figures['schedule_variance'],
            'cost_performance_index_cpi' => $figures['cost_performance_index'],
            'schedule_performance_index_spi' => $figures['schedule_performance_index'],
            'estimate_at_completion_eac' => $figures['estimate_at_completion'],
            'estimate_to_complete_etc' => $figures['estimate_to_complete'],
            'variance_at_completion_vac' => $figures['variance_at_completion'],
            'budget_absorption_percent' => $figures['budget_absorption_percent'],
            'recognized_revenue' => Money::round($revenue, 4),
            'gross_margin_amount' => $margin['gross_margin_amount'],
            'gross_margin_percent' => $margin['gross_margin_percent'],
            'manual_etc_override' => $figures['manual_etc_override'],
            'snapshot_trigger' => $trigger,
            'calculation_version' => EvmCalculator::CalculationVersion,
            'reconciliation' => $reconciliation,
        ])->save();

        return $snapshot;
    }

    /**
     * Managerial project cost against the statutory valuation: OIC 23 (Italy) or Swiss GAAP FER 22 (Switzerland), and the
     * overhead taken from the cost centres of Mod 018.
     *
     * @param  array{project: array{actual: string, committed: string}, nodes: array<string, array{actual: string, committed: string}>}  $ledger
     * @return array<string, mixed>
     */
    private function reconciliation(Project $project, array $ledger): array
    {
        $country = strtoupper((string) (Tenant::query()->whereKey($project->tenant_id)->value('country') ?? ''));
        $standard = match ($country) {
            'IT' => 'OIC 23',
            'CH' => 'Swiss GAAP FER 22',
            default => null,
        };
        $last = ProjectRevenueRecognition::query()->where('project_id', $project->id)->orderByDesc('accounting_period_date')->first();
        $overhead = Money::of(ProjectCostLedgerEntry::query()->where('project_id', $project->id)->where('source_type', CostSourceType::OverheadAllocation->value)->sum('base_currency_actual_amount'));
        $statutoryCost = Money::of($last->cumulative_cost_of_revenue ?? 0);

        return [
            'standard' => $standard,
            'valuation_method' => $project->revenue_recognition_method,
            'managerial_actual_cost' => Money::round($ledger['project']['actual'], 4),
            'statutory_cumulative_revenue' => Money::round($last->cumulative_recognized_revenue ?? 0, 4),
            'statutory_cumulative_cost' => Money::round($statutoryCost, 4),
            'cost_difference' => $last === null ? null : Money::round(Money::sub($ledger['project']['actual'], $statutoryCost), 4),
            'cost_center_overhead' => Money::round($overhead, 4),
            'last_recognition_date' => $last?->accounting_period_date?->toDateString(),
            'is_reconciled' => $last !== null && Money::isZero(Money::sub($ledger['project']['actual'], $statutoryCost)),
        ];
    }
}
