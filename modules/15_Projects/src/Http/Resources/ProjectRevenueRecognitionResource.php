<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectRevenueRecognition;

/**
 * API representation of a revenue recognition run (schema ProjectsRevenueRecognition).
 *
 * @mixin ProjectRevenueRecognition
 */
final class ProjectRevenueRecognitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectRevenueRecognition $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'project_id' => $run->project_id,
            'accounting_period_date' => $run->accounting_period_date->toDateString(),
            'recognition_method' => $run->recognition_method,
            'contract_value' => (string) $run->contract_value,
            'cumulative_actual_cost' => (string) $run->cumulative_actual_cost,
            'estimated_total_cost' => (string) $run->estimated_total_cost_eac,
            'completion_percentage' => (string) $run->cost_completion_percentage,
            'cumulative_recognized_revenue' => (string) $run->cumulative_recognized_revenue,
            'period_revenue' => (string) $run->period_revenue_adjustment,
            'cumulative_billed_revenue' => (string) $run->cumulative_billed_revenue,
            'contract_asset_balance' => (string) $run->contract_asset_balance,
            'period_contract_asset_adjustment' => (string) $run->period_contract_asset_adjustment,
            'cumulative_cost_of_revenue' => (string) $run->cumulative_cost_of_revenue,
            'period_cost_of_revenue' => (string) $run->period_cost_of_revenue,
            'is_posted_to_gl' => $run->is_posted_to_gl,
        ];
    }
}
