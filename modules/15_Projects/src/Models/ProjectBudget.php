<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectBudget extends BaseEnterpriseModel
{
    protected $table = 'project_budgets';

    protected function casts(): array
    {
        return [
            'current_version_number' => 'integer',
            'total_revenue_budget' => 'decimal:2',
            'total_cost_budget' => 'decimal:2',
            'labour_budget' => 'decimal:2',
            'material_budget' => 'decimal:2',
            'supplier_budget' => 'decimal:2',
            'fleet_budget' => 'decimal:2',
            'expense_budget' => 'decimal:2',
            'contingency_budget' => 'decimal:2',
            'projected_margin_amount' => 'decimal:2',
            'projected_margin_percentage' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectBudgetVersion::class, 'project_budget_id')->orderBy('version_number', 'desc');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProjectBudgetLine::class, 'project_budget_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ProjectBudgetApproval::class, 'project_budget_id');
    }
}
