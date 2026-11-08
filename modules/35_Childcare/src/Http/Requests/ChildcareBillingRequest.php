<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Childcare\Models\FeePlan;
use Modules\Childcare\Models\SubsidyRule;

/**
 * Fee plans, subsidy rules, billing runs and the subsidy export. Each route names the payload it validates.
 */
final class ChildcareBillingRequest extends FormRequest
{
    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        $month = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

        return match (str_replace('api.v1.childcare.', '', (string) $this->route()?->getName())) {
            'fee-plans.store' => [
                'name' => ['required', 'string', 'max:150'],
                'basis' => ['required', Rule::in(FeePlan::Bases)],
                'amount' => ['required', 'decimal:0,2', 'min:0'],
                'meal_amount' => ['sometimes', 'decimal:0,2', 'min:0'],
                'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
                'currency' => ['required', 'string', 'size:3'],
            ],
            'subsidy-rules.store' => [
                'canton' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
                'name' => ['required', 'string', 'max:150'],
                'method' => ['required', Rule::in(SubsidyRule::Methods)],
                'parameters' => ['required', 'array'],
                'valid_from' => ['required', 'date'],
                'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
                'source_reference' => ['nullable', 'string', 'max:500'],
            ],
            'subsidy-rules.verify' => ['source_reference' => ['required', 'string', 'min:5', 'max:500']],
            'billing.run' => ['period' => $month, 'date' => ['required', 'date']],
            'bills.index' => ['period' => $month],
            'subsidies.export' => ['period' => $month, 'canton' => ['nullable', 'string', 'size:2']],
            default => [],
        };
    }
}
