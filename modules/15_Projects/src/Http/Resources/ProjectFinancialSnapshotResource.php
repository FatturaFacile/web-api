<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Support\EvmCalculator;

/**
 * API representation of a financial snapshot of a project or WBS node (Mod 082).
 *
 * @mixin ProjectFinancialSnapshot
 */
final class ProjectFinancialSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectFinancialSnapshot $snapshot */
        $snapshot = $this->resource;
        $cpi = $snapshot->cost_performance_index_cpi === null ? null : (string) $snapshot->cost_performance_index_cpi;
        $spi = $snapshot->schedule_performance_index_spi === null ? null : (string) $snapshot->schedule_performance_index_spi;

        return [
            'id' => $snapshot->id,
            'project_id' => $snapshot->project_id,
            'wbs_node_id' => $snapshot->wbs_node_id,
            'snapshot_date' => $snapshot->snapshot_date->toDateString(),
            'currency_code' => $snapshot->currency_code,
            'budget_at_completion_bac' => (string) $snapshot->budget_at_completion_bac,
            'planned_value_pv' => (string) $snapshot->planned_value_pv,
            'earned_value_ev' => (string) $snapshot->earned_value_ev,
            'actual_cost_ac' => (string) $snapshot->actual_cost_ac,
            'committed_cost' => (string) $snapshot->committed_cost,
            'cost_variance_cv' => (string) $snapshot->cost_variance_cv,
            'schedule_variance_sv' => (string) $snapshot->schedule_variance_sv,
            'cost_performance_index_cpi' => $cpi,
            'schedule_performance_index_spi' => $spi,
            'cpi_light' => EvmCalculator::trafficLight($cpi),
            'spi_light' => EvmCalculator::trafficLight($spi),
            'estimate_at_completion_eac' => (string) $snapshot->estimate_at_completion_eac,
            'estimate_to_complete_etc' => (string) $snapshot->estimate_to_complete_etc,
            'variance_at_completion_vac' => (string) $snapshot->variance_at_completion_vac,
            'budget_absorption_percent' => $snapshot->budget_absorption_percent === null ? null : (string) $snapshot->budget_absorption_percent,
            'recognized_revenue' => (string) $snapshot->recognized_revenue,
            'gross_margin_amount' => (string) $snapshot->gross_margin_amount,
            'gross_margin_percent' => $snapshot->gross_margin_percent === null ? null : (string) $snapshot->gross_margin_percent,
            'manual_etc_override' => $snapshot->manual_etc_override === null ? null : (string) $snapshot->manual_etc_override,
            'snapshot_trigger' => $snapshot->snapshot_trigger,
            'calculation_version' => $snapshot->calculation_version,
            'reconciliation' => $snapshot->reconciliation,
            'created_at' => $snapshot->created_at?->toIso8601String(),
            'updated_at' => $snapshot->updated_at?->toIso8601String(),
        ];
    }
}
