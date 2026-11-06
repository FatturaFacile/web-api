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
            'output_vat_account_ids' => ['nullable', 'array'],
            'output_vat_account_ids.*' => ['uuid', $this->tenantAccount()],
            'input_vat_account_ids' => ['nullable', 'array'],
            'input_vat_account_ids.*' => ['uuid', $this->tenantAccount()],
            'settlement_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'interest_expense_account_id' => ['nullable', 'uuid', $this->tenantAccount()],
            'declarant_tax_code' => ['nullable', 'string', 'max:16', 'alpha_num'],
            'declarant_role_code' => ['nullable', 'string', 'max:2'],
        ];
    }

    private function tenantAccount(): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at');
    }
}
