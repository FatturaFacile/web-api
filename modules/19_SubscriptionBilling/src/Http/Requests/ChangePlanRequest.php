<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An upgrade or downgrade to another active plan of the company, optionally with a new quantity.
 */
final class ChangePlanRequest extends FormRequest
{
    use TenantRules;

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
            'new_plan_id' => ['required', 'uuid', $this->existsInTenant('sub_plans')->where('is_active', true)],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'effective_date' => ['nullable', 'date'],
            'change_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
