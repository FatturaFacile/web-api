<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\ReportingClass;

final class StoreChartOfAccountRequest extends FormRequest
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
        return [
            'account_code' => ['required', 'string', 'max:50', Rule::unique('chart_of_accounts', 'account_code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('account'))],
            'name' => ['required_without:account_name', 'nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'account_class' => ['nullable', 'string', 'max:30'],
            'account_type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense,cogs,wip,memo,statistical'],
            'normal_balance' => ['required', 'string', 'in:debit,credit'],
            'parent_account_id' => ['nullable', 'uuid', $this->tenantExists('chart_of_accounts')],
            'currency' => ['nullable', 'string', 'size:3'],
            'vat_code_id' => ['nullable', 'uuid', 'exists:vat_codes,id'],
            'default_cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'open_items_enabled' => ['nullable', 'boolean'],
            'allowed_currencies' => ['nullable', 'array'],
            'allowed_currencies.*' => ['string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'is_postable' => ['nullable', 'boolean'],
            'is_reconciliation_account' => ['nullable', 'boolean'],
            'reconciliation_type' => ['nullable', 'string', 'max:20'],
            'allows_manual_posting' => ['nullable', 'boolean'],
            'is_cost_center_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'template_code' => ['nullable', 'string', 'max:50'],
            'statutory_code' => ['nullable', 'string', 'max:50'],
            'reporting_class' => ['nullable', Rule::enum(ReportingClass::class)],
        ];
    }
}
