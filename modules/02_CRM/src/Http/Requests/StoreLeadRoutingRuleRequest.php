<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\Models\LeadRoutingRule;

/**
 * Create (POST) and update (PUT) of a routing rule. On PUT every field is optional.
 */
final class StoreLeadRoutingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => [$required, 'string', 'max:150'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'conditions' => ['nullable', 'array'],
            'conditions.countries' => ['nullable', 'array'],
            'conditions.countries.*' => ['string', 'size:2'],
            'conditions.regions' => ['nullable', 'array'],
            'conditions.regions.*' => ['string', 'max:50'],
            'conditions.product_lines' => ['nullable', 'array'],
            'conditions.product_lines.*' => ['string', 'max:100'],
            'conditions.employee_min' => ['nullable', 'integer', 'min:0'],
            'conditions.employee_max' => ['nullable', 'integer', 'min:0'],
            'conditions.ratings' => ['nullable', 'array'],
            'conditions.ratings.*' => ['string', Rule::in(['cold', 'warm', 'hot'])],
            'strategy' => [$required, 'string', Rule::in(LeadRoutingRule::STRATEGIES)],
            'assignee_user_ids' => [$required, 'array', 'min:1', 'max:100'],
            'assignee_user_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $min = $this->input('conditions.employee_min');
                $max = $this->input('conditions.employee_max');

                if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
                    $validator->errors()->add('conditions.employee_max', 'The maximum number of employees must be greater than or equal to the minimum.');
                }
            },
        ];
    }

    /**
     * Only the known condition keys are stored.
     *
     * @return array<string, mixed>|null
     */
    public function conditions(): ?array
    {
        $conditions = $this->input('conditions');
        if (! is_array($conditions)) {
            return null;
        }

        return array_intersect_key($conditions, array_flip(['countries', 'regions', 'product_lines', 'employee_min', 'employee_max', 'ratings']));
    }
}
