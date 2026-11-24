<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\BudgetLine;
use Modules\Controlling\Models\BudgetPlan;

/**
 * API representation of a BudgetPlan.
 *
 * @property BudgetPlan $resource
 */
final class BudgetPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'fiscal_year' => $this->resource->fiscal_year,
            'plan_type' => $this->resource->plan_type,
            'version_number' => $this->resource->version_number,
            'parent_plan_id' => $this->resource->parent_plan_id,
            'cutoff_month' => $this->resource->cutoff_month,
            'status' => $this->resource->status,
            'currency_code' => $this->resource->currency_code,
            'approved_by' => $this->resource->approved_by,
            'approved_at' => $this->resource->approved_at,
            'notes' => $this->resource->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->resource->lines->map(static fn (BudgetLine $line): array => $line->only(['id', 'plan_id', 'account_code', 'nature_type', 'cost_center_id', 'job_id', 'product_family_id', 'customer_id', 'country_code', 'region_code', 'sales_agent_id', 'production_department_id', ...BudgetLine::monthColumns(), 'annual_total', 'target_quantity', 'target_amount', 'target_margin_percent', 'distribution_driver']))->all()),
            'lines_count' => $this->whenCounted('lines'),

        ];
    }
}
