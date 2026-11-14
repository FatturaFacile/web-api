<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;

/**
 * Remeasurement of an active lease from a period start on: a modification of the contract, a change of the index the
 * payments are linked to, or the reassessment of the term (an extension option exercised or lapsed).
 */
final class RemeasureLeaseRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(array_values(array_diff(LeaseScheduleVersion::Reasons, ['initial'])))],
            'new_payment_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'required_if:reason,index_change'],
            'remaining_term_months' => ['nullable', 'integer', 'min:1', 'max:'.config('leases.max_term_months')],
            'new_discount_rate_percent' => ['nullable', 'numeric', 'decimal:0,6', 'between:0,100', 'required_if:reason,modification', 'required_if:reason,reassessment'],
            'post_journal' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
