<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work package of the WBS. It carries the planned dates (current plan), the baseline dates frozen when the schedule
 * was baselined, and the actual dates, so the Gantt can show the slippage and the critical path can be computed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $project_phase_id
 * @property string|null $project_milestone_id
 * @property string|null $parent_task_id
 * @property string $task_number
 * @property string|null $wbs_code
 * @property int $sort_order
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property CarbonInterface $planned_start_date
 * @property CarbonInterface $planned_end_date
 * @property CarbonInterface|null $baseline_start_date
 * @property CarbonInterface|null $baseline_end_date
 * @property CarbonInterface|null $actual_start_date
 * @property CarbonInterface|null $actual_end_date
 * @property string $estimated_hours
 * @property string $actual_hours
 * @property string $progress_percentage
 * @property bool $is_milestone_driver
 */
final class ProjectTask extends BaseEnterpriseModel
{
    public const array Statuses = ['backlog', 'todo', 'in_progress', 'review', 'completed', 'blocked'];

    protected $table = 'project_tasks';

    /**
     * Calculated columns of the task grid (portable SQL, see App\Support\Http\GridColumns).
     *
     * `code` is the identifier the grid shows: the task number, or the WBS code when the number is empty.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'code' => "COALESCE(NULLIF(project_tasks.task_number, ''), NULLIF(project_tasks.wbs_code, ''), '-')",
        ];
    }

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'baseline_start_date' => 'date',
            'baseline_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
            'is_milestone_driver' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    /**
     * @return BelongsTo<ProjectMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function subTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<ProjectTaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectTaskAssignment::class, 'project_task_id');
    }

    /**
     * Dependencies in which this task is the successor.
     *
     * @return HasMany<ProjectTaskDependency, $this>
     */
    public function predecessors(): HasMany
    {
        return $this->hasMany(ProjectTaskDependency::class, 'successor_task_id');
    }

    /**
     * Dependencies in which this task is the predecessor.
     *
     * @return HasMany<ProjectTaskDependency, $this>
     */
    public function successors(): HasMany
    {
        return $this->hasMany(ProjectTaskDependency::class, 'predecessor_task_id');
    }

    /**
     * @return HasMany<ProjectCostEntry, $this>
     */
    public function costEntries(): HasMany
    {
        return $this->hasMany(ProjectCostEntry::class, 'project_task_id');
    }

    /**
     * @return HasMany<ProjectTimesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class, 'project_task_id');
    }
}
