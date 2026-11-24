<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsBaseline;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Services\ProjectWbsBaselineService;

/**
 * Read model of the Gantt chart of a project: the WBS in depth-first order with schedule, floats and criticality of every
 * node, the dependencies, the critical path, and optionally the variance against a baseline.
 */
final readonly class GetProjectWbsGanttQuery
{
    public function __construct(private ProjectWbsBaselineService $baselines) {}

    /**
     * @return array{nodes: list<array<string, mixed>>, dependencies: list<array<string, mixed>>, cpm: array<string, mixed>, baseline: array<string, mixed>|null}
     */
    public function handle(Project $project, ?ProjectWbsBaseline $baseline = null): array
    {
        $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->get();
        $variance = $baseline !== null ? $this->baselines->variance($project, $baseline) : null;
        $varianceByNode = $variance === null ? [] : collect($variance['nodes'])->keyBy('node_id')->all();

        $children = [];

        foreach ($nodes as $node) {
            $children[$node->parent_node_id ?? ''][] = $node;
        }

        $ordered = [];
        $this->walk($children, '', 0, $ordered);

        $rows = [];

        foreach ($ordered as [$node, $depth]) {
            $row = [
                'id' => $node->id,
                'parent_node_id' => $node->parent_node_id,
                'wbs_code' => $node->wbs_code,
                'depth' => $depth,
                'title' => $node->title,
                'node_type' => $node->node_type,
                'status' => $node->status,
                'is_summary' => isset($children[$node->id]),
                'required_role' => $node->required_role,
                'planned_hours' => (string) $node->planned_hours,
                'actual_hours' => (string) $node->actual_hours,
                'planned_cost' => (string) $node->planned_cost,
                'actual_cost' => (string) $node->actual_cost,
                'planned_revenue' => (string) $node->planned_revenue,
                'percent_complete' => (string) $node->percent_complete,
                'sort_order' => $node->sort_order,
                'start_date' => $node->start_date?->toDateString(),
                'end_date' => $node->end_date?->toDateString(),
                'early_start' => $node->early_start?->toDateString(),
                'early_finish' => $node->early_finish?->toDateString(),
                'late_start' => $node->late_start?->toDateString(),
                'late_finish' => $node->late_finish?->toDateString(),
                'total_float_days' => $node->total_float_days,
                'free_float_days' => $node->free_float_days,
                'is_critical' => $node->is_critical,
            ];

            if ($variance !== null) {
                $row['baseline'] = $varianceByNode[$node->id] ?? null;
            }

            $rows[] = $row;
        }

        $dependencies = ProjectWbsDependency::query()->where('project_id', $project->id)->get()->map(static fn (ProjectWbsDependency $dependency): array => [
            'id' => $dependency->id,
            'predecessor_node_id' => $dependency->predecessor_node_id,
            'successor_node_id' => $dependency->successor_node_id,
            'dependency_type' => $dependency->dependency_type,
            'lag_days' => $dependency->lag_days,
        ])->values()->all();

        $leaves = $nodes->filter(static fn (ProjectWbsNode $node): bool => ! isset($children[$node->id]));
        $criticalPath = $leaves->where('is_critical', true)->sortBy(static fn (ProjectWbsNode $node): string => ($node->early_start?->toDateString() ?? '').'|'.$node->wbs_code)->pluck('id')->values()->all();

        return [
            'nodes' => $rows,
            'dependencies' => $dependencies,
            'cpm' => [
                'project_finish' => $leaves->map(static fn (ProjectWbsNode $node) => $node->early_finish?->toDateString())->filter()->max(),
                'critical_path' => $criticalPath,
                'critical_node_count' => count($criticalPath),
            ],
            'baseline' => $variance === null ? null : ['baseline' => $variance['baseline'], 'summary' => $variance['summary']],
        ];
    }

    /**
     * @param  array<string, list<ProjectWbsNode>>  $children
     * @param  list<array{ProjectWbsNode, int}>  $ordered
     */
    private function walk(array $children, string $parent, int $depth, array &$ordered): void
    {
        $siblings = $children[$parent] ?? [];
        usort($siblings, static fn (ProjectWbsNode $a, ProjectWbsNode $b): int => $a->sort_order <=> $b->sort_order);

        foreach ($siblings as $node) {
            $ordered[] = [$node, $depth];
            $this->walk($children, $node->id, $depth + 1, $ordered);
        }
    }
}
