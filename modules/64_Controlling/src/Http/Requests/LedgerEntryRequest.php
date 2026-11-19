<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\AnalyticalLedgerEntry;

/**
 * Validation of the LedgerEntryRequest payload; every reference is limited to the active company.
 */
final class LedgerEntryRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'posting_date' => ['required', 'date'], 'account_code' => ['nullable', 'string', 'max:40'],
            'nature_type' => ['required', Rule::in(AnalyticalLedgerEntry::Natures)],
            'cost_center_id' => ['nullable', 'uuid', Rule::exists('analytical_cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')], 'job_id' => ['nullable', 'uuid', Rule::exists('analytical_jobs', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'product_id' => ['nullable', 'uuid'], 'product_family_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'], 'sales_agent_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'between:-99999999999999,99999999999999'], 'quantity' => ['nullable', 'numeric'], 'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
