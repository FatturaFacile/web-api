<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectWipValuationRun;

/**
 * A period-end WIP valuation run of a project (Mod 081).
 *
 * @mixin ProjectWipValuationRun
 */
final class ProjectWipValuationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectWipValuationRun $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'project_id' => $m->project_id,
            'revenue_recognition_id' => $m->revenue_recognition_id,
            'period_end_date' => $m->period_end_date->toDateString(),
            'valuation_method' => $m->valuation_method,
            'accounting_standard' => $m->accounting_standard,
            'cumulative_actual_cost_to_date' => (string) $m->cumulative_actual_cost_to_date,
            'estimated_total_cost_at_completion' => (string) $m->estimated_total_cost_at_completion,
            'completion_ratio' => (string) $m->completion_ratio,
            'recognized_revenue_to_date' => (string) $m->recognized_revenue_to_date,
            'billed_to_date' => (string) $m->billed_to_date,
            'wip_asset_or_deferred_revenue_diff' => (string) $m->wip_asset_or_deferred_revenue_diff,
            'expected_loss_provision' => (string) $m->expected_loss_provision,
            'gl_journal_entry_id' => $m->gl_journal_entry_id,
            'status' => $m->status,
        ];
    }
}
