<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectTaskDependency extends BaseEnterpriseModel
{
    protected $table = 'project_task_dependencies';

    protected function casts(): array
    {
        return [
            'lag_days' => 'integer',
        ];
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'predecessor_task_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'successor_task_id');
    }
}
