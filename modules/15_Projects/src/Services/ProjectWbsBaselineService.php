<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Events\ProjectBaselineCapturedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsBaseline;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Support\ProjectAuditTrail;
use Modules\Projects\Wbs\BaselineVarianceCalculator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Numbered baselines of the WBS of a project: a frozen copy of every node (dates, effort, cost, revenue, progress) and of
 * the dependencies, and the variance of the live WBS against any of them.
 *
 * @phpstan-import-type Snapshot from BaselineVarianceCalculator
 */
final readonly class ProjectWbsBaselineService
{
    public function __construct(
        private BaselineVarianceCalculator $variance,
        private ProjectAuditTrail $audit,
        private EventDispatcher $events,
    ) {}

    /**
     * @throws ConflictHttpException when the project is closed
     * @throws ValidationException when the project has no WBS to baseline
     */
    public function capture(Project $project, ?string $name, string $actorUserId): ProjectWbsBaseline
    {
        return DB::transaction(function () use ($project, $name, $actorUserId): ProjectWbsBaseline {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new ConflictHttpException(__('projects_errors.project.no_longer_changeable', ['status' => $locked->status]));
            }

            $nodes = $this->snapshotNodes($locked);

            if ($nodes === []) {
                throw ValidationException::withMessages(['baseline' => __('projects_errors.wbs.baseline_needs_nodes')]);
            }

            $number = ((int) ProjectWbsBaseline::query()->where('project_id', $locked->id)->max('baseline_number')) + 1;
            $baseline = ProjectWbsBaseline::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'project_id' => $locked->id,
                'baseline_number' => $number,
                'name' => $name !== null && $name !== '' ? $name : "Baseline {$number}",
                'captured_at' => now(),
                'captured_by' => $actorUserId,
                'snapshot_json' => [
                    'project' => [
                        'planned_start_date' => $locked->planned_start_date?->toDateString(),
                        'planned_end_date' => $locked->planned_end_date?->toDateString(),
                        'finish_date' => collect($nodes)->pluck('end_date')->filter()->max(),
                        'planned_hours' => (string) $locked->planned_hours,
                        'planned_cost' => (string) $locked->planned_cost,
                        'planned_revenue' => (string) $locked->planned_revenue,
                        'percent_complete' => (string) $locked->progress_percentage,
                    ],
                    'nodes' => $nodes,
                    'dependencies' => ProjectWbsDependency::query()->where('project_id', $locked->id)->get()->map(static fn (ProjectWbsDependency $dependency): array => [
                        'predecessor_node_id' => $dependency->predecessor_node_id,
                        'successor_node_id' => $dependency->successor_node_id,
                        'dependency_type' => $dependency->dependency_type,
                        'lag_days' => $dependency->lag_days,
                    ])->all(),
                ],
            ]);

            $this->audit->record($locked, $actorUserId, 'wbs_baseline_captured', "WBS baseline {$baseline->baseline_number} captured", [], ['baseline_id' => $baseline->id, 'nodes' => count($nodes)], $baseline);

            $this->events->dispatch(new ProjectBaselineCapturedEvent(
                projectId: $locked->id,
                baselineId: $baseline->id,
                baselineNumber: $number,
                nodeCount: count($nodes),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
            ));

            return $baseline;
        });
    }

    /**
     * Variance of the live WBS against a baseline of the project.
     *
     * @return array{baseline: array<string, mixed>, nodes: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function variance(Project $project, ProjectWbsBaseline $baseline): array
    {
        /** @var list<Snapshot> $before */
        $before = $baseline->snapshot_json['nodes'] ?? [];
        $current = $this->snapshotNodes($project);
        $comparison = $this->variance->compare($before, $current, $baseline->snapshot_json['project']['finish_date'] ?? null);

        return [
            'baseline' => [
                'id' => $baseline->id,
                'baseline_number' => $baseline->baseline_number,
                'name' => $baseline->name,
                'captured_at' => $baseline->captured_at->toIso8601String(),
            ],
            'nodes' => $comparison['nodes'],
            'summary' => $comparison['summary'],
        ];
    }

    /**
     * The WBS of the project as baseline rows, in WBS order.
     *
     * @return list<Snapshot>
     */
    public function snapshotNodes(Project $project): array
    {
        return ProjectWbsNode::query()
            ->where('project_id', $project->id)
            ->get()
            ->sortBy(static fn (ProjectWbsNode $node): array => array_map('intval', explode('.', $node->wbs_code === '' ? '0' : $node->wbs_code)))
            ->map(static fn (ProjectWbsNode $node): array => [
                'id' => $node->id,
                'parent_node_id' => $node->parent_node_id,
                'wbs_code' => $node->wbs_code,
                'title' => $node->title,
                'node_type' => $node->node_type,
                'start_date' => $node->start_date?->toDateString(),
                'end_date' => $node->end_date?->toDateString(),
                'planned_hours' => (string) $node->planned_hours,
                'planned_cost' => (string) $node->planned_cost,
                'planned_revenue' => (string) $node->planned_revenue,
                'percent_complete' => (string) $node->percent_complete,
            ])
            ->values()
            ->all();
    }
}
