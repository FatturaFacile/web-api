<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\RateType;
use Modules\AgentCommissions\Models\CommissionRule;

final class SaveCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', CommissionRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');

        return [
            'agent_profile_id' => ['nullable', 'uuid', Rule::exists('agc_agent_profiles', 'id')->where('tenant_id', $tenantId)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'product_category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'zone_id' => ['nullable', 'uuid', Rule::exists('agc_zones', 'id')->where('tenant_id', $tenantId)],
            'rate_type' => [$creating ? 'required' : 'sometimes', Rule::enum(RateType::class)],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'accrual_basis' => ['nullable', Rule::enum(AccrualBasis::class)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'tiers' => ['nullable', 'array', 'max:20'],
            'tiers.*.threshold_from' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.threshold_to' => ['nullable', 'numeric', 'gt:tiers.*.threshold_from'],
            'tiers.*.rate' => ['required_with:tiers', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
