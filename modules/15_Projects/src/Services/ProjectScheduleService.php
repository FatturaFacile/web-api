<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectPhase;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTaskAssignment;
use Modules\Projects\Models\ProjectTaskDependency;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * WBS and schedule of a project: tasks, dependencies, assignments, the schedule baseline and the critical path.
 *
 * The critical path method works in calendar days on the planned dates of the tasks. A task lasts from its planned
 * start to its planned end, both included. Forward pass: a task starts as early as its predecessors allow (FS, SS, FF,
 * SF with lag) and never before the project start. Backward pass from the latest early finish. Total float is late
 * start minus early start; tasks with zero float form the critical path. Dependencies that would create a cycle are
 * refused when they are added, so the network is always a DAG.
 */
final readonly class ProjectScheduleService
{
    public function __construct(private ProjectAuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated task attributes
     */
    public function createTask(Project $project, array $attributes): ProjectTask
    {
        $this->assertOpen($project);
        $this->assertReferences($project, $attributes);

        return ProjectTask::create([
            ...$attributes,
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'status' => $attributes['status'] ?? 'backlog',
            'priority' => $attributes['priority'] ?? 'medium',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  validated task attributes
     */
    public function updateTask(ProjectTask $task, array $attributes): ProjectTask
    {
        return DB::transaction(function () use ($task, $attributes): ProjectTask {
            $locked = ProjectTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertOpen($project);
            $this->assertReferences($project, $attributes, $locked);

            $start = $attributes['planned_start_date'] ?? $locked->planned_start_date->toDateString();
            $end = $attributes['planned_end_date'] ?? $locked->planned_end_date->toDateString();

            if ($end < $start) {
                throw ValidationException::withMessages(['planned_end_date' => 'The planned end date must be on or after the planned start date.']);
            }

            if (($attributes['status'] ?? null) === 'completed') {
                $attributes['progress_percentage'] = '100';
                $attributes['actual_end_date'] ??= $locked->actual_end_date?->toDateString() ?? now()->toDateString();
            }

            if (($attributes['status'] ?? null) === 'in_progress' && $locked->actual_start_date === null) {
                $attributes['actual_start_date'] ??= now()->toDateString();
            }

            $locked->update($attributes);

            return $locked;
        });
    }

    /**
     * Deletes a task without booked time or costs, with its dependencies and assignments.
     *
     * @throws ConflictHttpException when hours or costs are booked on it, or it has subtasks
     */
    public function deleteTask(ProjectTask $task): void
    {
        DB::transaction(function () use ($task): void {
            if ($task->timesheets()->exists() || $task->costEntries()->exists()) {
                throw new ConflictHttpException('Hours or costs are booked on this task: it cannot be deleted.');
            }

            if ($task->subTasks()->exists()) {
                throw new ConflictHttpException('The task has subtasks: delete or move them first.');
            }

            ProjectTaskDependency::query()->where('predecessor_task_id', $task->id)->orWhere('successor_task_id', $task->id)->forceDelete();
            $task->assignments()->delete();
            $task->delete();
        });
    }

    /**
     * Links two tasks of the same project.
     *
     * @throws ValidationException when the tasks differ in project, the link exists or would close a cycle
     */
    public function addDependency(ProjectTask $successor, string $predecessorId, string $type, int $lagDays): ProjectTaskDependency
    {
        return DB::transaction(function () use ($successor, $predecessorId, $type, $lagDays): ProjectTaskDependency {
            Project::query()->whereKey($successor->project_id)->lockForUpdate()->firstOrFail();
            $predecessor = ProjectTask::query()->whereKey($predecessorId)->first();

            if ($predecessor === null || $predecessor->project_id !== $successor->project_id) {
                throw ValidationException::withMessages(['predecessor_task_id' => 'The predecessor must be a task of the same project.']);
            }

            if ($predecessor->id === $successor->id) {
                throw ValidationException::withMessages(['predecessor_task_id' => 'A task cannot depend on itself.']);
            }

            if (ProjectTaskDependency::query()->where('predecessor_task_id', $predecessor->id)->where('successor_task_id', $successor->id)->exists()) {
                throw ValidationException::withMessages(['predecessor_task_id' => 'This dependency already exists.']);
            }

            if ($this->reaches((string) $successor->project_id, (string) $successor->id, (string) $predecessor->id)) {
                throw ValidationException::withMessages(['predecessor_task_id' => 'This dependency would create a cycle in the schedule.']);
            }

            return ProjectTaskDependency::create([
                'tenant_id' => $successor->tenant_id,
                'predecessor_task_id' => $predecessor->id,
                'successor_task_id' => $successor->id,
                'dependency_type' => $type,
                'lag_days' => $lagDays,
            ]);
        });
    }

    public function removeDependency(ProjectTaskDependency $dependency): void
    {
        $dependency->forceDelete();
    }

    /**
     * Assigns a member of the company to a task with the hours planned for them; assigning again updates the hours.
     */
    public function assign(ProjectTask $task, string $userId, string $allocatedHours, ?string $role): ProjectTaskAssignment
    {
        return DB::transaction(function () use ($task, $userId, $allocatedHours, $role): ProjectTaskAssignment {
            ProjectTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            $assignment = ProjectTaskAssignment::query()->where('project_task_id', $task->id)->where('user_id', $userId)->first()
                ?? new ProjectTaskAssignment(['tenant_id' => $task->tenant_id, 'project_task_id' => $task->id, 'user_id' => $userId]);

            $assignment->fill(['allocated_hours' => $allocatedHours, 'role_description' => $role])->save();

            return $assignment;
        });
    }

    /**
     * Freezes the current planned dates of the project and of every task as the schedule baseline.
     */
    public function baseline(Project $project, string $actorUserId): Project
    {
        return DB::transaction(function () use ($project, $actorUserId): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            ProjectTask::query()->where('project_id', $locked->id)->update([
                'baseline_start_date' => DB::raw('planned_start_date'),
                'baseline_end_date' => DB::raw('planned_end_date'),
            ]);

            $locked->update([
                'baseline_start_date' => $locked->planned_start_date,
                'baseline_end_date' => $locked->planned_end_date,
                'baselined_at' => now(),
            ]);

            $this->audit->record($locked, $actorUserId, 'schedule_baselined', 'Schedule baseline frozen', [], ['baseline_start_date' => $locked->planned_start_date?->toDateString(), 'baseline_end_date' => $locked->planned_end_date?->toDateString()]);

            return $locked;
        });
    }

    /**
     * Critical path of the project: early and late dates, total float and the critical flag of every task.
     *
     * @return array{project_start: string|null, project_finish: string|null, duration_days: int, critical_path: list<string>, tasks: array<string, array{early_start: string, early_finish: string, late_start: string, late_finish: string, total_float_days: int, is_critical: bool}>}
     */
    public function criticalPath(Project $project): array
    {
        /** @var Collection<int, ProjectTask> $tasks */
        $tasks = ProjectTask::query()->where('project_id', $project->id)->orderBy('planned_start_date')->get();

        if ($tasks->isEmpty()) {
            return ['project_start' => $project->planned_start_date?->toDateString(), 'project_finish' => $project->planned_end_date?->toDateString(), 'duration_days' => 0, 'critical_path' => [], 'tasks' => []];
        }

        $dependencies = ProjectTaskDependency::query()->whereIn('successor_task_id', $tasks->pluck('id'))->get();
        $origin = CarbonImmutable::parse($project->planned_start_date ?? $tasks->min('planned_start_date'));
        $taskStart = CarbonImmutable::parse($tasks->min('planned_start_date'));
        $origin = $taskStart->lessThan($origin) ? $taskStart : $origin;

        $duration = [];
        $predecessors = [];
        $successors = [];

        foreach ($tasks as $task) {
            $duration[$task->id] = max(1, (int) $task->planned_start_date->diffInDays($task->planned_end_date) + 1);
            $predecessors[$task->id] = [];
            $successors[$task->id] = [];
        }

        foreach ($dependencies as $dependency) {
            if (! isset($duration[$dependency->predecessor_task_id])) {
                continue;
            }

            $predecessors[$dependency->successor_task_id][] = $dependency;
            $successors[$dependency->predecessor_task_id][] = $dependency;
        }

        $order = $this->topologicalOrder(array_keys($duration), $successors);
        $earlyStart = [];
        $earlyFinish = [];

        foreach ($order as $id) {
            $start = 0;

            foreach ($predecessors[$id] as $dependency) {
                $start = max($start, $this->earliestStart($dependency, $earlyStart, $earlyFinish, $duration[$id]));
            }

            $earlyStart[$id] = $start;
            $earlyFinish[$id] = $start + $duration[$id];
        }

        $projectFinish = max($earlyFinish);
        $lateStart = [];
        $lateFinish = [];

        foreach (array_reverse($order) as $id) {
            $finish = $projectFinish;

            foreach ($successors[$id] as $dependency) {
                $finish = min($finish, $this->latestFinish($dependency, $lateStart, $lateFinish, $duration[$id]));
            }

            $lateFinish[$id] = $finish;
            $lateStart[$id] = $finish - $duration[$id];
        }

        $result = [];
        $critical = [];

        foreach ($order as $id) {
            $float = $lateStart[$id] - $earlyStart[$id];
            $isCritical = $float === 0;

            if ($isCritical) {
                $critical[] = $id;
            }

            $result[$id] = [
                'early_start' => $origin->addDays($earlyStart[$id])->toDateString(),
                'early_finish' => $origin->addDays($earlyFinish[$id] - 1)->toDateString(),
                'late_start' => $origin->addDays($lateStart[$id])->toDateString(),
                'late_finish' => $origin->addDays($lateFinish[$id] - 1)->toDateString(),
                'total_float_days' => $float,
                'is_critical' => $isCritical,
            ];
        }

        usort($critical, static fn (string $a, string $b): int => $earlyStart[$a] <=> $earlyStart[$b]);

        return [
            'project_start' => $origin->toDateString(),
            'project_finish' => $origin->addDays($projectFinish - 1)->toDateString(),
            'duration_days' => $projectFinish,
            'critical_path' => $critical,
            'tasks' => $result,
        ];
    }

    /**
     * Gantt data: tasks with planned, baseline and actual dates, slippage, dependencies and critical path flags.
     *
     * @return array<string, mixed>
     */
    public function gantt(Project $project): array
    {
        $cpm = $this->criticalPath($project);
        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with(['predecessors', 'assignments'])
            ->orderBy('sort_order')
            ->orderBy('planned_start_date')
            ->get();

        return [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'planned_start_date' => $project->planned_start_date?->toDateString(),
            'planned_end_date' => $project->planned_end_date?->toDateString(),
            'baseline_start_date' => $project->baseline_start_date?->toDateString(),
            'baseline_end_date' => $project->baseline_end_date?->toDateString(),
            'progress_percentage' => (string) $project->progress_percentage,
            'schedule_finish' => $cpm['project_finish'],
            'critical_path' => $cpm['critical_path'],
            'phases' => ProjectPhase::query()->where('project_id', $project->id)->orderBy('sequence_order')->get()->map(static fn (ProjectPhase $phase): array => [
                'id' => $phase->id,
                'name' => $phase->getAttribute('name'),
                'planned_start_date' => $phase->getAttribute('planned_start_date')?->toDateString(),
                'planned_end_date' => $phase->getAttribute('planned_end_date')?->toDateString(),
                'status' => $phase->getAttribute('status'),
            ])->all(),
            'milestones' => ProjectMilestone::query()->where('project_id', $project->id)->orderBy('milestone_date')->get()->map(static fn (ProjectMilestone $milestone): array => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'milestone_date' => $milestone->milestone_date->toDateString(),
                'is_completed' => $milestone->is_completed,
            ])->all(),
            'tasks' => $tasks->map(fn (ProjectTask $task): array => [
                'id' => $task->id,
                'task_number' => $task->task_number,
                'wbs_code' => $task->wbs_code,
                'title' => $task->title,
                'parent_task_id' => $task->parent_task_id,
                'phase_id' => $task->project_phase_id,
                'status' => $task->status,
                'progress_percentage' => (string) $task->progress_percentage,
                'planned_start_date' => $task->planned_start_date->toDateString(),
                'planned_end_date' => $task->planned_end_date->toDateString(),
                'baseline_start_date' => $task->baseline_start_date?->toDateString(),
                'baseline_end_date' => $task->baseline_end_date?->toDateString(),
                'actual_start_date' => $task->actual_start_date?->toDateString(),
                'actual_end_date' => $task->actual_end_date?->toDateString(),
                'start_variance_days' => $task->baseline_start_date !== null ? (int) $task->baseline_start_date->diffInDays($task->planned_start_date, false) : null,
                'finish_variance_days' => $task->baseline_end_date !== null ? (int) $task->baseline_end_date->diffInDays($task->planned_end_date, false) : null,
                'estimated_hours' => (string) $task->estimated_hours,
                'actual_hours' => (string) $task->actual_hours,
                'assignee_user_ids' => $task->assignments->pluck('user_id')->all(),
                'dependencies' => $task->predecessors->map(static fn (ProjectTaskDependency $dependency): array => [
                    'id' => $dependency->id,
                    'predecessor_task_id' => $dependency->predecessor_task_id,
                    'type' => $dependency->dependency_type,
                    'lag_days' => $dependency->lag_days,
                ])->all(),
                ...($cpm['tasks'][$task->id] ?? []),
            ])->all(),
        ];
    }

    /**
     * Earliest start of the successor allowed by one dependency, in days from the origin (finish is exclusive).
     *
     * @param  array<string, int>  $earlyStart
     * @param  array<string, int>  $earlyFinish
     */
    private function earliestStart(ProjectTaskDependency $dependency, array $earlyStart, array $earlyFinish, int $successorDuration): int
    {
        $from = $dependency->predecessor_task_id;
        $lag = $dependency->lag_days;

        return match ($dependency->dependency_type) {
            'start_to_start' => $earlyStart[$from] + $lag,
            'finish_to_finish' => $earlyFinish[$from] + $lag - $successorDuration,
            'start_to_finish' => $earlyStart[$from] + $lag - $successorDuration,
            default => $earlyFinish[$from] + $lag,
        };
    }

    /**
     * Latest finish of the predecessor allowed by one dependency, in days from the origin (finish is exclusive).
     *
     * @param  array<string, int>  $lateStart
     * @param  array<string, int>  $lateFinish
     */
    private function latestFinish(ProjectTaskDependency $dependency, array $lateStart, array $lateFinish, int $predecessorDuration): int
    {
        $to = $dependency->successor_task_id;
        $lag = $dependency->lag_days;

        return match ($dependency->dependency_type) {
            'start_to_start' => $lateStart[$to] - $lag + $predecessorDuration,
            'finish_to_finish' => $lateFinish[$to] - $lag,
            'start_to_finish' => $lateFinish[$to] - $lag + $predecessorDuration,
            default => $lateStart[$to] - $lag,
        };
    }

    /**
     * Kahn's algorithm; the network is acyclic because cycles are refused when dependencies are added.
     *
     * @param  list<string>  $ids
     * @param  array<string, list<ProjectTaskDependency>>  $successors
     * @return list<string>
     */
    private function topologicalOrder(array $ids, array $successors): array
    {
        $inDegree = array_fill_keys($ids, 0);

        foreach ($successors as $links) {
            foreach ($links as $dependency) {
                $inDegree[$dependency->successor_task_id]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, static fn (int $degree): bool => $degree === 0));
        $order = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($successors[$id] as $dependency) {
                if (--$inDegree[$dependency->successor_task_id] === 0) {
                    $queue[] = $dependency->successor_task_id;
                }
            }
        }

        if (count($order) !== count($ids)) {
            throw new ConflictHttpException('The task dependencies form a cycle: the critical path cannot be computed.');
        }

        return $order;
    }

    /**
     * Whether a path of dependencies leads from one task to another within the project.
     */
    private function reaches(string $projectId, string $fromTaskId, string $toTaskId): bool
    {
        $taskIds = ProjectTask::query()->where('project_id', $projectId)->pluck('id');
        $edges = ProjectTaskDependency::query()->whereIn('predecessor_task_id', $taskIds)->get(['predecessor_task_id', 'successor_task_id'])
            ->groupBy('predecessor_task_id')
            ->map(static fn (Collection $links): array => $links->pluck('successor_task_id')->all());

        $stack = [$fromTaskId];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $toTaskId) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($edges->get($current, []) as $next) {
                $stack[] = (string) $next;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertReferences(Project $project, array $attributes, ?ProjectTask $task = null): void
    {
        $errors = [];

        if (isset($attributes['project_phase_id']) && ! ProjectPhase::query()->whereKey($attributes['project_phase_id'])->where('project_id', $project->id)->exists()) {
            $errors['project_phase_id'] = 'The phase does not belong to the project.';
        }

        if (isset($attributes['project_milestone_id']) && ! ProjectMilestone::query()->whereKey($attributes['project_milestone_id'])->where('project_id', $project->id)->exists()) {
            $errors['project_milestone_id'] = 'The milestone does not belong to the project.';
        }

        if (isset($attributes['parent_task_id'])) {
            $parentOk = ProjectTask::query()->whereKey($attributes['parent_task_id'])->where('project_id', $project->id)->exists();

            if (! $parentOk || ($task !== null && $attributes['parent_task_id'] === $task->id)) {
                $errors['parent_task_id'] = 'The parent must be another task of the same project.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: its schedule can no longer change.");
        }
    }
}
