<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectTask extends BaseEnterpriseModel
{
    protected $table = 'project_tasks';

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
            'is_milestone_driver' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectTaskAssignment::class, 'project_task_id');
    }

    public function predecessors(): HasMany
    {
        return $this->hasMany(ProjectTaskDependency::class, 'successor_task_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(ProjectTaskDependency::class, 'predecessor_task_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProjectCostEntry::class, 'project_task_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class, 'project_task_id');
    }
}
