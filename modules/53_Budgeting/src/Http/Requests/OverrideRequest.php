<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetOverride;

/**
 * A request to spend beyond the budget (deroga): for one document, or an allowance of an account, cost centre or
 * project until a date.
 */
final class OverrideRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(BudgetOverride::Scopes)],
            'source_type' => ['required_if:scope,document', 'nullable', Rule::in([BudgetCommitment::Requisition, BudgetCommitment::PurchaseOrder])],
            'source_id' => ['required_if:scope,document', 'nullable', 'uuid'],
            'source_number' => ['nullable', 'string', 'max:60'],
            'account_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'requested_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
