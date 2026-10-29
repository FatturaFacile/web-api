<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ProjectBudget::class, 'project_budget_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }
}
