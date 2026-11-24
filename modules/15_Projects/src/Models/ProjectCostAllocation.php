<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of a cost entry attributed to a task when one cost serves several work packages.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_cost_entry_id
 * @property string $project_task_id
 * @property string $allocation_percentage
 * @property string $allocated_amount
 */
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

    /**
     * @return BelongsTo<ProjectCostEntry, $this>
     */
    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectCostEntry::class, 'project_cost_entry_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
