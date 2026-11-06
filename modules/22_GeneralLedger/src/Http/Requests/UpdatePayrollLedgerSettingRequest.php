<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class UpdatePayrollLedgerSettingRequest extends FormRequest
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
        $account = ['nullable', 'uuid', $this->tenantAccount()];

        return [
            'salary_expense_account_id' => $account,
            'employer_cost_account_id' => $account,
            'expense_allowance_account_id' => $account,
            'employee_payable_account_id' => $account,
            'social_payable_account_id' => $account,
            'tax_payable_account_id' => $account,
            'non_cash_benefit_account_id' => $account,
            'rounding_account_id' => $account,
            'wage_type_accounts' => ['nullable', 'array'],
            'wage_type_accounts.*' => ['uuid', $this->tenantAccount()],
        ];
    }

    private function tenantAccount(): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId());
    }
}
