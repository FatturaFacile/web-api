<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A frozen copy of the budget taken at each approval (baseline), kept for variance analysis and audit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_budget_id
 * @property int $version_number
 * @property string|null $revision_reason
 * @property array<string, mixed> $snapshot_payload
 * @property string $total_revenue_budget
 * @property string $total_cost_budget
 * @property bool $is_baseline
 * @property string|null $approved_by
 */
final class ProjectBudgetVersion extends BaseEnterpriseModel
{
    protected $table = 'project_budget_versions';

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot_payload' => 'array',
            'total_revenue_budget' => 'decimal:2',
            'total_cost_budget' => 'decimal:2',
            'is_baseline' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProjectBudget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(ProjectBudget::class, 'project_budget_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<ProjectBudgetApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ProjectBudgetApproval::class, 'budget_version_id');
    }
}
