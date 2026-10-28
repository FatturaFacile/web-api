<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class ProjectCostEntry extends BaseEnterpriseModel
{
    protected $table = 'project_cost_entries';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_invoiced' => 'boolean',
            'incurred_date' => 'date',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProjectCostAllocation::class, 'project_cost_entry_id');
    }

    public function costSource(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'cost_source_type', 'cost_source_id');
    }
}
