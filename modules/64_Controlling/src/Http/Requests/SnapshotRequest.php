<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the SnapshotRequest payload; every reference is limited to the active company.
 */
final class SnapshotRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'snapshot_date' => ['required', 'date'], 'mode' => ['nullable', Rule::in(['compute', 'manual'])],
            'accounts_receivable_balance' => ['required_if:mode,manual', 'numeric'], 'overdue_receivables_balance' => ['nullable', 'numeric'],
            'accounts_payable_balance' => ['required_if:mode,manual', 'numeric'], 'inventory_valuation_balance' => ['required_if:mode,manual', 'numeric'],
            'cash_and_bank_balance' => ['nullable', 'numeric'], 'credit_sales_period' => ['nullable', 'numeric'], 'credit_purchases_period' => ['nullable', 'numeric'], 'cogs_period' => ['nullable', 'numeric'],
        ];
    }
}
