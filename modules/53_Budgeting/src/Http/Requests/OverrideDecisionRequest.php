<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * The decision on an override: a comment (mandatory when rejecting), and on approval optionally a lower amount and an expiry.
 */
final class OverrideDecisionRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
            'approved_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
