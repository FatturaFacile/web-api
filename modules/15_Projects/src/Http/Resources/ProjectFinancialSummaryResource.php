<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectFinancialSummary;

/**
 * @mixin ProjectFinancialSummary
 */
final class ProjectFinancialSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'total_contract_revenue' => (float) $this->total_contract_revenue,
            'total_invoiced_revenue' => (float) $this->total_invoiced_revenue,
            'total_recognized_revenue' => (float) $this->total_recognized_revenue,
            'actual_material_cost' => (float) $this->actual_material_cost,
            'actual_labour_cost' => (float) $this->actual_labour_cost,
            'actual_supplier_cost' => (float) $this->actual_supplier_cost,
            'actual_fleet_cost' => (float) $this->actual_fleet_cost,
            'actual_expense_cost' => (float) $this->actual_expense_cost,
            'total_actual_cost' => (float) $this->total_actual_cost,
            'committed_po_cost' => (float) $this->committed_po_cost,
            'gross_profit_amount' => (float) $this->gross_profit_amount,
            'gross_profit_percentage' => (float) $this->gross_profit_percentage,
            'earned_value_management' => [
                'planned_value_pv' => (float) $this->planned_value_pv,
                'earned_value_ev' => (float) $this->earned_value_ev,
                'budget_at_completion_bac' => (float) $this->budget_at_completion_bac,
                'cost_performance_index_cpi' => (float) $this->cost_performance_index_cpi,
                'schedule_performance_index_spi' => (float) $this->schedule_performance_index_spi,
                'estimate_at_completion_eac' => (float) $this->estimate_at_completion_eac,
                'estimate_to_complete_etc' => (float) $this->estimate_to_complete_etc,
                'variance_at_completion_vac' => (float) $this->variance_at_completion_vac,
            ],
            'last_recalculated_at' => $this->last_recalculated_at?->toIso8601String(),
        ];
    }
}
