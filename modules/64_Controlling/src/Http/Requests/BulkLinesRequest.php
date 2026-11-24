<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\AnalyticalLedgerEntry;
use Modules\Controlling\Models\BudgetLine;

/**
 * Validation of the BulkLinesRequest payload; every reference is limited to the active company.
 */
final class BulkLinesRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        $months = [];
        foreach (BudgetLine::monthColumns() as $column) {
            $months['lines.*.'.$column] = ['nullable', 'numeric', 'between:-99999999999999,99999999999999'];
        }

        return $months + [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.nature_type' => ['required_without:lines.*.id', Rule::in(AnalyticalLedgerEntry::Natures)],
            'lines.*.annual_total' => ['nullable', 'numeric', 'between:-99999999999999,99999999999999'],
            'lines.*.distribution_driver' => ['nullable', Rule::in(BudgetLine::Drivers)],
            'lines.*.weights' => ['nullable', 'array', 'size:12'], 'lines.*.weights.*' => ['numeric', 'min:0'],
            'lines.*.account_code' => ['nullable', 'string', 'max:40'],
            'lines.*.cost_center_id' => ['nullable', 'uuid', Rule::exists('analytical_cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')], 'lines.*.job_id' => ['nullable', 'uuid', Rule::exists('analytical_jobs', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'lines.*.product_family_id' => ['nullable', 'uuid'], 'lines.*.customer_id' => ['nullable', 'uuid'], 'lines.*.sales_agent_id' => ['nullable', 'uuid'],
            'lines.*.production_department_id' => ['nullable', 'uuid'], 'lines.*.country_code' => ['nullable', 'string', 'size:2'], 'lines.*.region_code' => ['nullable', 'string', 'max:16'],
            'lines.*.target_quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.target_margin_percent' => ['nullable', 'numeric'],
        ];
    }
}
