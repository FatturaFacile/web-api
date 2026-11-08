<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A detail line of the project budget (quantity times unit cost) within a cost category.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_budget_id
 * @property string|null $project_phase_id
 * @property string $cost_category
 * @property string $description
 * @property string $planned_quantity
 * @property string $planned_unit_cost
 * @property string $planned_total_cost
 * @property string $planned_revenue_amount
 */
final class ProjectBudgetLine extends BaseEnterpriseModel
{
    protected $table = 'project_budget_lines';

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:4',
            'planned_unit_cost' => 'decimal:4',
            'planned_total_cost' => 'decimal:2',
            'planned_revenue_amount' => 'decimal:2',
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
     * @return BelongsTo<ProjectPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }
}
