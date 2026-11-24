<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectFinancialSummary;

/**
 * API representation of the financial summary of a project (schema ProjectsFinancialSummary).
 *
 * @mixin ProjectFinancialSummary
 */
final class ProjectFinancialSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectFinancialSummary $summary */
        $summary = $this->resource;

        return [
            'project_id' => $summary->project_id,
            'total_contract_revenue' => (string) $summary->total_contract_revenue,
            'total_invoiced_revenue' => (string) $summary->total_invoiced_revenue,
            'total_recognized_revenue' => (string) $summary->total_recognized_revenue,
            'actual_cost' => [
                'labour' => (string) $summary->actual_labour_cost,
                'material' => (string) $summary->actual_material_cost,
                'supplier' => (string) $summary->actual_supplier_cost,
                'fleet' => (string) $summary->actual_fleet_cost,
                'expense' => (string) $summary->actual_expense_cost,
                'total' => (string) $summary->total_actual_cost,
            ],
            'committed_po_cost' => (string) $summary->committed_po_cost,
            'gross_profit_amount' => (string) $summary->gross_profit_amount,
            'gross_profit_percentage' => (string) $summary->gross_profit_percentage,
            'evm' => [
                'budget_at_completion' => (string) $summary->budget_at_completion_bac,
                'planned_value' => (string) $summary->planned_value_pv,
                'earned_value' => (string) $summary->earned_value_ev,
                'cost_performance_index' => (string) $summary->cost_performance_index_cpi,
                'schedule_performance_index' => (string) $summary->schedule_performance_index_spi,
                'estimate_at_completion' => (string) $summary->estimate_at_completion_eac,
                'estimate_to_complete' => (string) $summary->estimate_to_complete_etc,
                'variance_at_completion' => (string) $summary->variance_at_completion_vac,
            ],
            'last_recalculated_at' => $summary->last_recalculated_at?->toIso8601String(),
        ];
    }
}
