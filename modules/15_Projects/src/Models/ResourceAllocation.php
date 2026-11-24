<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ResourceAllocation extends BaseEnterpriseModel
{
    protected $table = 'resource_allocations';

    protected function casts(): array
    {
        return [
            'allocated_start_date' => 'date',
            'allocated_end_date' => 'date',
            'allocated_hours_per_day' => 'decimal:2',
            'total_allocated_hours' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'resource_id');
    }
}
