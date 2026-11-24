<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\AgentType;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Domain\Enums\FiscalRegime;
use Modules\AgentCommissions\Domain\Enums\SettlementChannel;
use Modules\AgentCommissions\Domain\Enums\SplitMode;
use Modules\AgentCommissions\Models\AgentProfile;

final class SaveAgentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', AgentProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');

        return [
            'sales_representative_id' => [
                $creating ? 'required' : 'prohibited',
                'uuid',
                Rule::exists('sales_representatives', 'id')->where('tenant_id', $tenantId),
                Rule::unique('agc_agent_profiles', 'sales_representative_id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'agent_type' => [$creating ? 'required' : 'sometimes', Rule::enum(AgentType::class)],
            'fiscal_regime' => [$creating ? 'required' : 'sometimes', Rule::enum(FiscalRegime::class)],
            'enasarco_mandate' => ['nullable', Rule::enum(EnasarcoMandate::class)],
            'parent_agent_profile_id' => ['nullable', 'uuid', Rule::exists('agc_agent_profiles', 'id')->where('tenant_id', $tenantId)],
            'parent_split_mode' => ['nullable', 'required_with:parent_agent_profile_id', Rule::enum(SplitMode::class)],
            'parent_split_value' => ['nullable', 'required_with:parent_agent_profile_id', 'numeric', 'min:0', 'max:100'],
            'accrual_basis' => ['sometimes', Rule::enum(AccrualBasis::class)],
            'default_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settlement_channel' => ['sometimes', Rule::enum(SettlementChannel::class)],
            'has_own_organization' => ['sometimes', 'boolean'],
            'vat_applies' => ['sometimes', 'boolean'],
            'firr_enabled' => ['sometimes', 'boolean'],
            'enasarco_registration_number' => ['nullable', 'string', 'max:40'],
            'tax_code' => ['nullable', 'string', 'max:32'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'contract_start' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date', Rule::when($this->filled('contract_start'), ['after_or_equal:contract_start'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
