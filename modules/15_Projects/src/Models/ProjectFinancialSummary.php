<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectFinancialSummary extends BaseEnterpriseModel
{
    protected $table = 'project_financial_summaries';

    protected function casts(): array
    {
        return [
            'total_contract_revenue' => 'decimal:2',
            'total_invoiced_revenue' => 'decimal:2',
            'total_recognized_revenue' => 'decimal:2',
            'actual_material_cost' => 'decimal:2',
            'actual_labour_cost' => 'decimal:2',
            'actual_supplier_cost' => 'decimal:2',
            'actual_fleet_cost' => 'decimal:2',
            'actual_expense_cost' => 'decimal:2',
            'total_actual_cost' => 'decimal:2',
            'committed_po_cost' => 'decimal:2',
            'gross_profit_amount' => 'decimal:2',
            'gross_profit_percentage' => 'decimal:2',
            'planned_value_pv' => 'decimal:2',
            'earned_value_ev' => 'decimal:2',
            'budget_at_completion_bac' => 'decimal:2',
            'cost_performance_index_cpi' => 'decimal:2',
            'schedule_performance_index_spi' => 'decimal:2',
            'estimate_at_completion_eac' => 'decimal:2',
            'estimate_to_complete_etc' => 'decimal:2',
            'variance_at_completion_vac' => 'decimal:2',
            'last_recalculated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
