<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTaskAssignment;
use Modules\Projects\Models\ProjectTaskDependency;

/**
 * API representation of a WBS task with its assignments and predecessors (schema ProjectsTask).
 *
 * @mixin ProjectTask
 */
final class ProjectTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectTask $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'project_phase_id' => $task->project_phase_id,
            'project_milestone_id' => $task->project_milestone_id,
            'parent_task_id' => $task->parent_task_id,
            'task_number' => $task->task_number,
            'wbs_code' => $task->wbs_code,
            'sort_order' => $task->sort_order,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'planned_start_date' => $task->planned_start_date->toDateString(),
            'planned_end_date' => $task->planned_end_date->toDateString(),
            'baseline_start_date' => $task->baseline_start_date?->toDateString(),
            'baseline_end_date' => $task->baseline_end_date?->toDateString(),
            'actual_start_date' => $task->actual_start_date?->toDateString(),
            'actual_end_date' => $task->actual_end_date?->toDateString(),
            'estimated_hours' => (string) $task->estimated_hours,
            'actual_hours' => (string) $task->actual_hours,
            'progress_percentage' => (string) $task->progress_percentage,
            'assignments' => $this->whenLoaded('assignments', fn () => $task->assignments->map(static fn (ProjectTaskAssignment $assignment): array => self::assignment($assignment))->all()),
            'dependencies' => $this->whenLoaded('predecessors', fn () => $task->predecessors->map(static fn (ProjectTaskDependency $dependency): array => self::dependency($dependency))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignment(ProjectTaskAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'project_task_id' => $assignment->project_task_id,
            'user_id' => $assignment->user_id,
            'role_description' => $assignment->role_description,
            'allocated_hours' => (string) $assignment->allocated_hours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dependency(ProjectTaskDependency $dependency): array
    {
        return [
            'id' => $dependency->id,
            'predecessor_task_id' => $dependency->predecessor_task_id,
            'successor_task_id' => $dependency->successor_task_id,
            'dependency_type' => $dependency->dependency_type,
            'lag_days' => $dependency->lag_days,
        ];
    }
}
