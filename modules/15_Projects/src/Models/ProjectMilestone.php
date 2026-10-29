<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectMilestone extends BaseEnterpriseModel
{
    protected $table = 'project_milestones';

    protected function casts(): array
    {
        return [
            'milestone_date' => 'date',
            'billing_percentage' => 'decimal:2',
            'billing_amount' => 'decimal:2',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'is_billed' => 'boolean',
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

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_milestone_id');
    }
}
