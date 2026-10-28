<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectCostAllocation extends BaseEnterpriseModel
{
    protected $table = 'project_cost_allocations';

    protected function casts(): array
    {
        return [
            'allocation_percentage' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
        ];
    }

    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectCostEntry::class, 'project_cost_entry_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
