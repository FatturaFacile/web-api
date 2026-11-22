<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Wbs\Enums\WbsNodeStatus;

/**
 * Kanban board of a project: the work items of the WBS (leaves; summary nodes only group them) in one column per
 * workflow status, in WBS order.
 */
final readonly class GetProjectWbsKanbanQuery
{
    /**
     * @return list<array{status: string, count: int, items: list<array<string, mixed>>}>
     */
    public function handle(Project $project): array
    {
        $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->orderBy('sort_order')->get();
        $parents = $nodes->pluck('parent_node_id')->filter()->flip();

        $items = $nodes
            ->filter(static fn (ProjectWbsNode $node): bool => ! $parents->has($node->id))
            ->sortBy(static fn (ProjectWbsNode $node): array => array_map('intval', explode('.', $node->wbs_code === '' ? '0' : $node->wbs_code)))
            ->groupBy('status');

        $columns = [];

        foreach (WbsNodeStatus::cases() as $status) {
            $column = $items->get($status->value, collect());
            $columns[] = [
                'status' => $status->value,
                'count' => $column->count(),
                'items' => $column->map(static fn (ProjectWbsNode $node): array => [
                    'id' => $node->id,
                    'wbs_code' => $node->wbs_code,
                    'title' => $node->title,
                    'node_type' => $node->node_type,
                    'required_role' => $node->required_role,
                    'percent_complete' => (string) $node->percent_complete,
                    'planned_hours' => (string) $node->planned_hours,
                    'actual_hours' => (string) $node->actual_hours,
                    'end_date' => $node->end_date?->toDateString(),
                    'total_float_days' => $node->total_float_days,
                    'is_critical' => $node->is_critical,
                ])->values()->all(),
            ];
        }

        return $columns;
    }
}
