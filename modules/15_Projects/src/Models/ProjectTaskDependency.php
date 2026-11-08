<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logical link between two tasks of the same project (finish-to-start, start-to-start, finish-to-finish or
 * start-to-finish) with a lag in calendar days, used by the critical path computation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $predecessor_task_id
 * @property string $successor_task_id
 * @property string $dependency_type
 * @property int $lag_days
 */
final class ProjectTaskDependency extends BaseEnterpriseModel
{
    public const array Types = ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'];

    protected $table = 'project_task_dependencies';

    protected function casts(): array
    {
        return [
            'lag_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'predecessor_task_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'successor_task_id');
    }
}
