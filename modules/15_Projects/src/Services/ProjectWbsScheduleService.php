<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Events\ProjectScheduleRecalculatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Support\Money;
use Modules\Projects\Wbs\CriticalPathCalculator;
use Modules\Projects\Wbs\Enums\DependencyType;
use Modules\Projects\Wbs\Enums\WbsNodeType;
use Modules\Projects\Wbs\Exceptions\CyclicDependencyException;
use Modules\Projects\Wbs\WbsRollupCalculator;

/**
 * Schedule and roll-up of the WBS of a project in one pass: critical path method on the leaf nodes (forward and backward
 * pass, floats, critical path), cascade of the early dates onto the planned dates, then the bottom-up roll-up of hours,
 * cost, revenue, progress and dates to every summary node and to the project.
 *
 * Dependencies are only meaningful between leaf nodes: links that touch a summary node are ignored by the pass.
 */
final readonly class ProjectWbsScheduleService
{
    public function __construct(
        private CriticalPathCalculator $cpm,
        private WbsRollupCalculator $rollup,
        private EventDispatcher $events,
    ) {}

    /**
     * Recomputes the schedule and the roll-up of the project and stores them.
     *
     * @param  bool  $applySchedule  move the planned dates of nodes that a predecessor pushes later
     * @return array{project_finish: string|null, duration_days: int, critical_path: list<string>, shifted_nodes: int, node_count: int}
     *
     * @throws ValidationException when the dependencies contain a cycle
     */
    public function recalculate(Project $project, bool $applySchedule = true): array
    {
        return DB::transaction(function () use ($project, $applySchedule): array {
            $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->get()->keyBy('id');
            $dependencies = ProjectWbsDependency::query()->where('project_id', $project->id)->get();

            if ($nodes->isEmpty()) {
                $this->storeProjectTotals($project, $this->rollup->rollUp([])['totals']);

                return ['project_finish' => null, 'duration_days' => 0, 'critical_path' => [], 'shifted_nodes' => 0, 'node_count' => 0];
            }

            $parents = $nodes->pluck('parent_node_id')->filter()->unique()->flip();
            $leaves = $nodes->filter(static fn (ProjectWbsNode $node): bool => ! $parents->has($node->id));
            $projectStart = CarbonImmutable::parse($project->planned_start_date)->startOfDay();
            $base = $leaves->reduce(
                static fn (CarbonImmutable $carry, ProjectWbsNode $node): CarbonImmutable => $node->start_date !== null && CarbonImmutable::parse($node->start_date)->startOfDay()->lt($carry)
                    ? CarbonImmutable::parse($node->start_date)->startOfDay()
                    : $carry,
                $projectStart,
            );

            $cpmNodes = [];

            foreach ($leaves as $leaf) {
                $cpmNodes[] = [
                    'id' => $leaf->id,
                    'duration' => $this->duration($leaf),
                    'earliest_start' => $this->offset($base, $leaf->start_date ?? $projectStart),
                ];
            }

            $links = [];

            foreach ($dependencies as $dependency) {
                $links[] = [
                    'predecessor' => $dependency->predecessor_node_id,
                    'successor' => $dependency->successor_node_id,
                    'type' => DependencyType::from($dependency->dependency_type),
                    'lag' => $dependency->lag_days,
                ];
            }

            try {
                $result = $this->cpm->calculate($cpmNodes, $links);
            } catch (CyclicDependencyException $e) {
                throw ValidationException::withMessages(['dependencies' => __('projects_errors.wbs.dependency_cycle')]);
            }

            $shifted = $this->storeLeafSchedule($leaves->values()->all(), $result['nodes'], $base, $applySchedule);
            $this->storeSummarySchedule($nodes->values()->all(), $parents->keys()->all());
            $this->storeRollUp($project, $nodes->values()->all());

            $finish = $this->finishDate($base, $leaves->values()->all(), $result['nodes']);

            $summary = [
                'project_finish' => $finish?->toDateString(),
                'duration_days' => max(0, $this->offset($projectStart, $finish ?? $projectStart) + 1),
                'critical_path' => $result['critical_path'],
                'shifted_nodes' => $shifted,
                'node_count' => $nodes->count(),
            ];

            $this->events->dispatch(new ProjectScheduleRecalculatedEvent(
                projectId: $project->id,
                projectFinishDate: $summary['project_finish'],
                criticalNodeCount: count($result['critical_path']),
                shiftedNodeCount: $shifted,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id),
            ));

            return $summary;
        });
    }

    /**
     * Calendar-day duration of a leaf: a milestone has none, a node without dates lasts one day.
     */
    private function duration(ProjectWbsNode $node): int
    {
        if ($node->node_type === WbsNodeType::Milestone->value) {
            return 0;
        }

        if ($node->start_date === null || $node->end_date === null) {
            return 1;
        }

        return max(1, $this->offset(CarbonImmutable::parse($node->start_date), CarbonImmutable::parse($node->end_date)) + 1);
    }

    private function offset(CarbonImmutable $from, \DateTimeInterface $to): int
    {
        return (int) round($from->startOfDay()->diffInDays(CarbonImmutable::instance($to)->startOfDay(), false));
    }

    /**
     * Stores early/late dates, floats and criticality of the leaves and moves pushed nodes.
     *
     * @param  list<ProjectWbsNode>  $leaves
     * @param  array<string, array{es: int, ef: int, ls: int, lf: int, total_float: int, free_float: int, critical: bool}>  $schedule
     */
    private function storeLeafSchedule(array $leaves, array $schedule, CarbonImmutable $base, bool $apply): int
    {
        $shifted = 0;

        foreach ($leaves as $leaf) {
            $row = $schedule[$leaf->id];
            $isPoint = $row['ef'] === $row['es'];
            $earlyStart = $base->addDays($row['es']);
            $earlyFinish = $base->addDays($isPoint ? $row['es'] : $row['ef'] - 1);
            $lateStart = $base->addDays($row['ls']);
            $lateFinish = $base->addDays($isPoint ? $row['ls'] : $row['lf'] - 1);

            $attributes = [
                'early_start' => $earlyStart->toDateString(),
                'early_finish' => $earlyFinish->toDateString(),
                'late_start' => $lateStart->toDateString(),
                'late_finish' => $lateFinish->toDateString(),
                'total_float_days' => $row['total_float'],
                'free_float_days' => $row['free_float'],
                'is_critical' => $row['critical'],
            ];

            if ($apply && $leaf->start_date !== null && ! CarbonImmutable::parse($leaf->start_date)->startOfDay()->equalTo($earlyStart)) {
                $attributes['start_date'] = $earlyStart->toDateString();
                $attributes['end_date'] = $earlyFinish->toDateString();
                $shifted++;
            }

            $leaf->update($attributes);
        }

        return $shifted;
    }

    /**
     * A summary node spans its children and inherits the tightest float and the criticality of the chain below it.
     *
     * @param  list<ProjectWbsNode>  $nodes
     * @param  list<string>  $parentIds
     */
    private function storeSummarySchedule(array $nodes, array $parentIds): void
    {
        $byParent = [];

        foreach ($nodes as $node) {
            if ($node->parent_node_id !== null) {
                $byParent[$node->parent_node_id][] = $node;
            }
        }

        $depth = static function (ProjectWbsNode $node) use ($nodes): int {
            $level = 0;
            $byId = [];

            foreach ($nodes as $candidate) {
                $byId[$candidate->id] = $candidate;
            }

            while ($node->parent_node_id !== null && isset($byId[$node->parent_node_id]) && $level < 1000) {
                $node = $byId[$node->parent_node_id];
                $level++;
            }

            return $level;
        };

        $summaries = array_values(array_filter($nodes, static fn (ProjectWbsNode $node): bool => in_array($node->id, $parentIds, true)));
        $depths = [];

        foreach ($summaries as $summary) {
            $depths[$summary->id] = $depth($summary);
        }

        usort($summaries, static fn (ProjectWbsNode $a, ProjectWbsNode $b): int => $depths[$b->id] <=> $depths[$a->id]);

        foreach ($summaries as $summary) {
            $children = $byParent[$summary->id] ?? [];

            $summary->update([
                'early_start' => $this->earliest($children, 'early_start'),
                'early_finish' => $this->latest($children, 'early_finish'),
                'late_start' => $this->earliest($children, 'late_start'),
                'late_finish' => $this->latest($children, 'late_finish'),
                'total_float_days' => $this->smallest($children, 'total_float_days'),
                'free_float_days' => $this->smallest($children, 'free_float_days'),
                'is_critical' => collect($children)->contains(static fn (ProjectWbsNode $child): bool => $child->is_critical),
            ]);
        }
    }

    /**
     * Writes the rolled-up values on every node and on the project.
     *
     * @param  list<ProjectWbsNode>  $nodes
     * @return array<string, mixed>
     */
    private function storeRollUp(Project $project, array $nodes): array
    {
        $input = [];

        foreach ($nodes as $node) {
            $input[] = [
                'id' => $node->id,
                'parent_id' => $node->parent_node_id,
                'planned_hours' => (string) $node->planned_hours,
                'actual_hours' => (string) $node->actual_hours,
                'planned_cost' => (string) $node->planned_cost,
                'actual_cost' => (string) $node->actual_cost,
                'planned_revenue' => (string) $node->planned_revenue,
                'percent_complete' => (string) $node->percent_complete,
                'start_date' => $node->start_date?->toDateString(),
                'end_date' => $node->end_date?->toDateString(),
            ];
        }

        $rolled = $this->rollup->rollUp($input);

        foreach ($nodes as $node) {
            $row = $rolled['nodes'][$node->id];

            if ($row['is_leaf']) {
                continue;
            }

            $node->update([
                'planned_hours' => $row['planned_hours'],
                'actual_hours' => $row['actual_hours'],
                'planned_cost' => $row['planned_cost'],
                'actual_cost' => $row['actual_cost'],
                'planned_revenue' => $row['planned_revenue'],
                'percent_complete' => $row['percent_complete'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
            ]);
        }

        $this->storeProjectTotals($project, $rolled['totals']);

        return $rolled['totals'];
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function storeProjectTotals(Project $project, array $totals): void
    {
        $updates = [
            'planned_hours' => $totals['planned_hours'],
            'actual_hours' => $totals['actual_hours'],
            'planned_cost' => $totals['planned_cost'],
            'actual_cost' => $totals['actual_cost'],
            'planned_revenue' => $totals['planned_revenue'],
        ];

        // Without a WBS the progress of the project stays whatever the task-based schedule and lifecycle made of it.
        if (ProjectWbsNode::query()->where('project_id', $project->id)->exists()) {
            $updates['progress_percentage'] = Money::round(Money::min(100, $totals['percent_complete']));
        }

        $project->update($updates);
    }

    /**
     * @param  list<ProjectWbsNode>  $leaves
     * @param  array<string, array{es: int, ef: int, ls: int, lf: int, total_float: int, free_float: int, critical: bool}>  $schedule
     */
    private function finishDate(CarbonImmutable $base, array $leaves, array $schedule): ?CarbonImmutable
    {
        $latest = null;

        foreach ($leaves as $leaf) {
            $row = $schedule[$leaf->id];
            $date = $base->addDays($row['ef'] === $row['es'] ? $row['es'] : $row['ef'] - 1);

            if ($latest === null || $date->gt($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /**
     * @param  list<ProjectWbsNode>  $children
     */
    private function earliest(array $children, string $attribute): ?string
    {
        $dates = array_filter(array_map(static fn (ProjectWbsNode $child) => $child->{$attribute}?->toDateString(), $children));

        return $dates === [] ? null : min($dates);
    }

    /**
     * @param  list<ProjectWbsNode>  $children
     */
    private function latest(array $children, string $attribute): ?string
    {
        $dates = array_filter(array_map(static fn (ProjectWbsNode $child) => $child->{$attribute}?->toDateString(), $children));

        return $dates === [] ? null : max($dates);
    }

    /**
     * @param  list<ProjectWbsNode>  $children
     */
    private function smallest(array $children, string $attribute): ?int
    {
        $values = array_filter(array_map(static fn (ProjectWbsNode $child): ?int => $child->{$attribute}, $children), static fn (?int $value): bool => $value !== null);

        return $values === [] ? null : min($values);
    }
}
