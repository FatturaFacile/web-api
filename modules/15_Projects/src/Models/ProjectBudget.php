<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cost and revenue budget of a project, split by cost category. Every approval freezes a version (baseline); the
 * approved total cost is the budget at completion (BAC) of the earned value analysis.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property int $current_version_number
 * @property string $status
 * @property string $total_revenue_budget
 * @property string $total_cost_budget
 * @property string $labour_budget
 * @property string $material_budget
 * @property string $supplier_budget
 * @property string $fleet_budget
 * @property string $expense_budget
 * @property string $contingency_budget
 * @property string $projected_margin_amount
 * @property string $projected_margin_percentage
 * @property string|null $approved_by
 * @property CarbonInterface|null $approved_at
 */
final class ProjectBudget extends BaseEnterpriseModel
{
    /** Budget column of each cost category; equipment costs are consumed from the fleet budget. */
    public const array CategoryColumns = [
        'labour' => 'labour_budget',
        'material' => 'material_budget',
        'supplier' => 'supplier_budget',
        'fleet' => 'fleet_budget',
        'expense' => 'expense_budget',
        'contingency' => 'contingency_budget',
    ];

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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<ProjectBudgetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectBudgetVersion::class, 'project_budget_id')->orderBy('version_number', 'desc');
    }

    /**
     * @return HasMany<ProjectBudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProjectBudgetLine::class, 'project_budget_id');
    }

    /**
     * @return HasMany<ProjectBudgetApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ProjectBudgetApproval::class, 'project_budget_id');
    }
}
