<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ProjectBudget::class, 'project_budget_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ProjectBudgetApproval::class, 'budget_version_id');
    }
}
