<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\GeneralLedger\Services\Tax\VatPeriod;

final class UpdateVatTaxSettingRequest extends FormRequest
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
        return [
            'periodicity' => ['nullable', Rule::in([VatPeriod::MONTHLY, VatPeriod::QUARTERLY, VatPeriod::SEMIANNUAL])],
            'regime' => ['nullable', Rule::in(['effective', 'net_tax_rate', 'flat_tax_rate'])],
            'sss_rate_1' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'sss_rate_2' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'flat_tax_rates' => ['nullable', 'array'],
            'flat_tax_rates.*' => ['numeric', 'min:0', 'max:20'],
            'flat_rate_adjustment_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'acquisition_tax_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'output_vat_account_ids' => ['nullable', 'array'],
            'output_vat_account_ids.*' => ['uuid', $this->tenantAccount()],
            'input_vat_account_ids' => ['nullable', 'array'],
            'input_vat_account_ids.*' => ['uuid', $this->tenantAccount()],
            'settlement_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'interest_expense_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'declarant_tax_code' => ['nullable', 'string', 'max:16', 'alpha_num'],
            'declarant_role_code' => ['nullable', 'string', 'max:2'],
            'carry_over_below_minimum' => ['sometimes', 'boolean'],
        ];
    }

    private function tenantAccount(): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at');
    }
}
