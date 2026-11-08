<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per project with the rolled up actuals (by cost category), billed and recognised revenue and the latest
 * earned value figures. It is a cache rebuilt from the cost entries, invoices and recognitions, updated under a row lock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string $total_contract_revenue
 * @property string $total_invoiced_revenue
 * @property string $total_recognized_revenue
 * @property string $actual_material_cost
 * @property string $actual_labour_cost
 * @property string $actual_supplier_cost
 * @property string $actual_fleet_cost
 * @property string $actual_expense_cost
 * @property string $total_actual_cost
 * @property string $committed_po_cost
 * @property string $gross_profit_amount
 * @property string $gross_profit_percentage
 * @property string $planned_value_pv
 * @property string $earned_value_ev
 * @property string $budget_at_completion_bac
 * @property string $cost_performance_index_cpi
 * @property string $schedule_performance_index_spi
 * @property string $estimate_at_completion_eac
 * @property string $estimate_to_complete_etc
 * @property string $variance_at_completion_vac
 * @property CarbonInterface|null $last_recalculated_at
 */
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
