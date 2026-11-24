<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A discount policy or promotion: header discount, validity, limits, priority, stacking and its rules (by product,
 * customer or customer group). On update omitted fields keep their value; `rules`, when given, replace the rules.
 */
class StoreDiscountPolicyRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();
        $policyId = $this->route('id');

        return [
            'code' => [$presence, 'string', 'max:50', Rule::unique('discount_policies', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($policyId)],
            'name' => [$presence, 'string', 'max:255'],
            'discount_type' => [$presence, Rule::in(['percentage', 'fixed_amount'])],
            'value' => [$presence, 'numeric', 'min:0', 'max:999999999', 'decimal:0,4'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'min_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_stackable' => ['sometimes', 'boolean'],
            'auto_apply' => ['sometimes', 'boolean'],
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'rules' => ['sometimes', 'array', 'max:200'],
            'rules.*.product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'rules.*.customer_group_id' => ['nullable', 'uuid', $this->tenantOwns('customer_groups')],
            'rules.*.contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'rules.*.min_threshold' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'rules.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'rules.*.discount_fixed_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /**
     * A percentage policy cannot give more than 100%, and a rule must target a product, a customer or a customer group.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('discount_type') === 'percentage' && is_numeric($this->input('value')) && (float) $this->input('value') > 100) {
                $validator->errors()->add('value', __('sales.discount_percentage_over_100'));
            }

            foreach ((array) $this->input('rules', []) as $index => $rule) {
                if (empty($rule['product_id']) && empty($rule['customer_group_id']) && empty($rule['contact_id'])) {
                    $validator->errors()->add("rules.{$index}", __('sales.discount_rule_without_target'));
                }
            }
        }];
    }
}
