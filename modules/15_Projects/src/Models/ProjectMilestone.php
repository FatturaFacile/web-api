<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A milestone of the project. When it carries a billing amount it becomes billable once completed, and billing it
 * creates a draft sales invoice (the fiscal number is assigned by Invoicing at emission).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $project_phase_id
 * @property string $name
 * @property CarbonInterface $milestone_date
 * @property string $billing_percentage
 * @property string $billing_amount
 * @property bool $is_completed
 * @property CarbonInterface|null $completed_at
 * @property bool $is_billed
 * @property CarbonInterface|null $billed_at
 * @property string|null $invoice_id
 */
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
            'billed_at' => 'datetime',
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
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_milestone_id');
    }
}
