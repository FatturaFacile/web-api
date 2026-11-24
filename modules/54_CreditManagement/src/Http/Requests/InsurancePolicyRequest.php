<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditInsurancePolicy;

/**
 * Payload of a credit insurance policy.
 */
final class InsurancePolicyRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'insurer' => ['required', 'string', 'max:160'],
            'policy_number' => ['required', 'string', 'max:80'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'coverage_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'max_total_cover' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'status' => [Rule::in(CreditInsurancePolicy::Statuses)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
